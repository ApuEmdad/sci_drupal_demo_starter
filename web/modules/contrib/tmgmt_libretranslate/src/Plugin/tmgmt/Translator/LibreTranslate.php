<?php

namespace Drupal\tmgmt_libretranslate\Plugin\tmgmt\Translator;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\tmgmt\Translator\AvailableResult;

/**
 * LibreTranslate translator plugin.
 *
 * @TranslatorPlugin(
 *   id = "libretranslate",
 *   label = @Translation("LibreTranslate"),
 *   description = @Translation("LibreTranslate Translator service."),
 *   ui = "Drupal\tmgmt_libretranslate\LibreTranslateTranslatorUi",
 *   logo = "icons/libre.svg",
 * )
 */
class LibreTranslate extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {

  /**
   * Translation service URL.
   *
   * @var string
   */
  protected $translatorUrl = 'https://libretranslate.com/';

  /**
   * Name of parameter that contains source string to be translated.
   *
   * @var string
   */
  protected $qParamName = 'q';

  /**
   * Available actions for libretranslate translator.
   *
   * @var array
   */
  protected $availableActions = ['translate', 'languages'];

  /**
   * Max number of text queries for translation sent in one request.
   *
   * @var int
   */
  protected $qChunkSize = 5;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Data help service.
   *
   * @var \Drupal\tmgmt\Data
   */
  protected $dataHelper;

  /**
   * Constructs a LocalActionBase object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\tmgmt\Data $data_helper
   *   Data helper service.
   */
  public function __construct(ClientInterface $client, array $configuration, string $plugin_id, array $plugin_definition, Data $data_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
    $this->dataHelper = $data_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('http_client'),
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tmgmt.data')
    );
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::checkAvailable().
   */
  public function checkAvailable(TranslatorInterface $translator) {
    if ($translator->getSetting('api_key')) {
      return AvailableResult::yes();
    }

    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::requestTranslation().
   */
  public function requestTranslation(JobInterface $job) {
    $this->requestJobItemsTranslation($job->getItems());
    if (!$job->isRejected()) {
      $job->submitted('The translation job has been submitted.');
    }
  }

  /**
   * Helper method to do translation request.
   *
   * @param \Drupal\tmgmt\Entity\Job $job
   *   TMGMT Job to be used for translation.
   * @param array|string $q
   *   Text/texts to be translated.
   *
   * @return array
   *   Userialized JSON containing translated texts.
   */
  protected function libreTranslateRequestTranslation(Job $job, $q) {
    $translator = $job->getTranslator();
    return $this->doRequest($translator, 'translate', [
      'source' => $job->getRemoteSourceLanguage(),
      'target' => $job->getRemoteTargetLanguage(),
      $this->qParamName => $q,
    ]);
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedRemoteLanguages().
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $languages = [];
    // Prevent access if the translator isn't configured yet.
    if (!$translator->getSetting('api_url') || !$translator->getSetting('api_key')) {
      return $languages;
    }
    try {
      $request = $this->doRequest($translator, 'languages');
      if (!empty($request)) {
        foreach ($request as $language) {
          $languages[$language['code']] = $language['code'];
        }
      }
    }
    catch (\Exception $e) {
      \Drupal::messenger()->addMessage($e->getMessage(), 'error');
      return $languages;
    }

    return $languages;
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getDefaultRemoteLanguagesMappings().
   */
  public function getDefaultRemoteLanguagesMappings() {
    return [
      'zh-hans' => 'zh-CHS',
      'zh-hant' => 'zh-CHT',
    ];
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::getSupportedTargetLanguages().
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language) {

    $languages = $this->getSupportedRemoteLanguages($translator);

    // There are no language pairs, any supported language can be translated
    // into the others. If the source language is part of the languages,
    // then return them all, just remove the source language.
    if (array_key_exists($source_language, $languages)) {
      unset($languages[$source_language]);
      return $languages;
    }

    return [];
  }

  /**
   * Overrides TMGMTDefaultTranslatorPluginController::hasCheckoutSettings().
   */
  public function hasCheckoutSettings(JobInterface $job) {
    return FALSE;
  }

  /**
   * Local method to do request to LibreTranslate Translate service.
   *
   * @param \Drupal\tmgmt\Entity\Translator $translator
   *   The translator entity to get the settings from.
   * @param string $action
   *   Action to be performed [translate, languages, detect].
   * @param array $request_query
   *   (Optional) Additional query params to be passed into the request.
   * @param array $options
   *   (Optional) Additional options that will be passed into drupal_http_request().
   *
   * @return array
   *   Unserialized JSON response from LibreTranslate.
   *
   * @throws \Drupal\tmgmt\TMGMTException.
   *   - Invalid action provided.
   *   - Unable to connect to the LibreTranslate Service.
   *   - Error returned by the LibreTranslate Service.
   */
  protected function doRequest(Translator $translator, $action, array $request_query = [], array $options = []) {

    if (!in_array($action, $this->availableActions)) {
      throw new TMGMTException('Invalid action requested: @action', ['@action' => $action]);
    }

    // Get custom URL for testing purposes, if available.
    $custom_url = rtrim($translator->getSetting('api_url'), '/');
    $url = ($custom_url ? $custom_url : $this->translatorUrl) . '/' . $action;
    // Build the query.
    $form_params = [];
    $form_params['api_key'] = $translator->getSetting('api_key');

    if (isset($request_query['source'])) {
      $form_params['source'] = $request_query['source'];
    }
    if (isset($request_query['target'])) {
      $form_params['target'] = $request_query['target'];
    }

    if (isset($request_query['q'])) {
      $form_params['q'] = $request_query['q'];
      $form_params['format'] = 'html';
    }

    // Send the request with the query.
    try {
      $method = ($action == 'languages') ? 'GET' : 'POST';
      $response = $this->client->request($method, $url, [
        'form_params' => $form_params,
      ]);
    }
    catch (BadResponseException $e) {
      throw new TMGMTException('LibreTranslate Translate service returned following error: @error', ['@error' => $e->getMessage()]);
    }
    // Process the JSON result into array.
    return json_decode($response->getBody(), TRUE);
  }

  /**
   * We provide translatorUrl setter so that we can override its value
   * in automated testing.
   *
   * @param $translator_url
   */
  final public function setTranslatorURL($translator_url) {
    $this->translatorUrl = $translator_url;
  }

  /**
   * The q parameter name needs to be overridden for Drupal testing as it
   * collides with Drupal q parameter.
   *
   * @param $name
   */
  final public function setQParamName($name) {
    $this->qParamName = $name;
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    foreach ($job_items as $job_item) {
      if ($job->isContinuous()) {
        $job_item->active();
      }
      // Pull the source data array through the job and flatten it.
      $data = $this->dataHelper->filterTranslatable($job_item->getData());

      $translation = [];
      try {
        foreach ($data as $key => $value) {
          $result = $this->libreTranslateRequestTranslation($job, $value['#text']);
          if (!empty($result['translatedText'])) {
            $translation[$key]['#text'] = Html::decodeEntities($result['translatedText']);
          }
        }

        // Save the translated data through the job.
        // Only reached when all translation requests have succeeded.
        $job_item->addTranslatedData($this->dataHelper
          ->unflatten($translation));
      }
      catch (TMGMTException $e) {
        $job->rejected('Translation has been rejected with following error: @error',
          ['@error' => $e->getMessage()], 'error');
      }
    }
  }

}
