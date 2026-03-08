<?php

namespace Drupal\tmgmt_ilangl\Plugin\tmgmt\Translator;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\Translator\TranslatableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use Drupal\tmgmt_file\Format\FormatManager;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * ILangL translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "ilangl",
 *   label = @Translation("iLangL"),
 *   description = @Translation("The iLangL translator module that integrates with the Translation Management Tool."),
 *   logo = "icons/ilangl.png",
 *   ui = "Drupal\tmgmt_ilangl\ILangLTranslatorUi",
 * )
 */
class ILangLTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {
  use StringTranslationTrait;

  /**
   * The translator run by Cron is inactive.
   */
  const IS_INACTIVE = 0;

  /**
   * The translator run by Cron is active.
   */
  const IS_ACTIVE = 1;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Xliff converter service.
   *
   * @var \Drupal\tmgmt_file\Format\FormatManager
   */
  protected $formatManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The state key value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * List of supported languages by iLangL.
   *
   * @var string
   */
  protected $supportedTargetLanguages = [];

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Translator entity.
   *
   * @var \Drupal\tmgmt\Entity\Translator
   */
  protected $translator;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * {@inheritdoc}
   */
  public function __construct(ClientInterface $client, FormatManager $formatManager, array $configuration, string $pluginId, array $pluginDefinition, ConfigFactoryInterface $config_factory, StateInterface $state, LoggerInterface $logger, RendererInterface $renderer, RequestStack $requestStack, ModuleHandlerInterface $moduleHandler, EntityTypeManagerInterface $entityTypeManager, LanguageManagerInterface $languageManager, CacheBackendInterface $cache) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->client = $client;
    $this->formatManager = $formatManager;
    $this->configFactory = $config_factory;
    $this->config = $config_factory->getEditable('tmgmt_ilangl.settings');
    $this->state = $state;
    $this->logger = $logger;
    $this->renderer = $renderer;
    $this->requestStack = $requestStack;
    $this->moduleHandler = $moduleHandler;
    $this->translator = $entityTypeManager
      ->getStorage('tmgmt_translator');
    $this->languageManager = $languageManager;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    /**
     * @var \GuzzleHttp\ClientInterface $client
     */
    $client = $container->get('http_client');

    /**
     * @var \Drupal\tmgmt_file\Format\FormatManager $formatManager
     */
    $formatManager = $container->get('plugin.manager.tmgmt_file.format');

    /**
     * @var \Drupal\Core\Config\ConfigFactoryInterface $configFactory
     */
    $configFactory = $container->get('config.factory');

    /**
     * @var \Drupal\Core\State\StateInterface $state
     */
    $state = $container->get('state');

    /**
     * @var \Psr\Log\LoggerInterface $logger
     */
    $logger = $container->get('logger.factory')->get('tmgmt_ilangl');

    /**
     * @var \Drupal\Core\Render\RendererInterface $renderer
     */
    $renderer = $container->get('renderer');

    /**
     * @var \Symfony\Component\HttpFoundation\RequestStack $requestStack
     */
    $requestStack = $container->get('request_stack');

    /**
     * @var \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
     */
    $moduleHandler = $container->get('module_handler');

    /**
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
     */
    $entityTypeManager = $container->get('entity_type.manager');

    /**
     * @var \Drupal\Core\Language\LanguageManagerInterface $languageManager
     */
    $languageManager = $container->get('language_manager');

    /**
     * @var \Drupal\Core\Cache\CacheBackendInterface $cache
     */
    $cache = $container->get('cache.default');

    return new static($client, $formatManager, $configuration, $plugin_id, $plugin_definition, $configFactory, $state, $logger, $renderer, $requestStack, $moduleHandler, $entityTypeManager, $languageManager, $cache);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings(): array {
    $defaults = parent::defaultSettings();

    $defaults['domain'] = '';
    $defaults['xliff_cdata'] = TRUE;

    return $defaults;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language): array {
    try {
      $provider_id = ($translator->id()) ?? $translator->getPluginId();
      $provider_data = $this->getState('provider_data', $provider_id);
      $supported_languages = $provider_data['remote_languages'];

      if (isset($supported_languages)) {
        foreach ($supported_languages as $language) {

          if ($language['Enabled']) {
            $this->supportedTargetLanguages[$language['Code']] = $language['Name'];
          }
        }

        return $this->supportedTargetLanguages;
      }

      return [];

    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
      $this->messenger()->addError($e->getMessage());
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job): void {
    try {
      $this->requestJobItemsTranslation($job->getItems());
    }
    catch (TMGMTException $e) {
      $message = $this->t('Job has been rejected with following error: @error', [
        '@error' => $e->getMessage(),
      ]);

      $job->rejected($message);
      $this->logger->error($message);
    }

    if (!$job->isRejected()) {
      $job->submitted('Job has been successfully submitted for translation.');
    }
    else {
      $job->setState(JobItemInterface::STATE_INACTIVE);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator): AvailableResult {
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $provider_data = $this->getState('provider_data', $provider_id);

    $not_available = AvailableResult::no(
      $this->t('@translator is not available. Make sure it is properly <a href=":configured">configured</a>.',
        [
          '@translator' => $translator->label(),
          ':configured' => $translator->toUrl()->toString(),
        ]
      )
    );

    $available = AvailableResult::yes();

    if ($provider_data['token']) {

      if (!$provider_data['expires'] || strtotime($provider_data['expires']) <= time()) {
        try {
          $auth = $this->getAuth($translator);

          if (isset($auth['.expires']) && isset($auth['access_token'])) {
            $provider_data['token'] = $auth['access_token'];
            $provider_data['expires'] = $auth['.expires'];

            $this->setState('provider_data', $provider_data, $provider_id);
          }

          return $available;
        }
        catch (\Exception $e) {
          $this->logger->error($this->t('Error: @message', ['@message' => $e->getMessage()]));
          $this->messenger()->addError($this->t('Error: @message', ['@message' => $e->getMessage()]));

          return $not_available;
        }
      }
      else {
        return $available;
      }
    }

    return $not_available;
  }

  /**
   * {@inheritdoc}
   */
  public function checkTranslatable(TranslatorInterface $translator, JobInterface $job) {
    $provider_data = $this->getState('provider_data', $translator->id());

    $service_id = $provider_data['service_id'] ?? NULL;

    $cache_key = 'tmgmt_ilangl.register';

    try {
      if ($data = $this->cache->get($cache_key)) {
        $register = $data->data;
      }
      else {
        $register = $this->getRegister($translator);
        $this->cache->set($cache_key, $register, time() + 600);
      }

      if (
        isset($register['ProjectInfo']['Services'])
        && is_array($register['ProjectInfo']['Services'])
      ) {
        $services = $register['ProjectInfo']['Services'];
      }
      else {
        $services = [];
      }

      if ($services) {
        foreach ($services as $service) {
          if ($service['Id'] === $service_id) {
            $source_langcode = $service['Source'];
            break;
          }
        }
      }
    }
    catch (\Exception $e) {
    }

    if (isset($source_langcode)) {
      $remote_source = $job->getSourceLanguage()->getId();

      if ($remote_source !== $source_langcode) {
        return TranslatableResult::no(t("Source language is not matched with service assigned to provider"));
      }
    }

    return parent::checkTranslatable($translator, $job);
  }

  /**
   * {@inheritdoc}
   *
   * Requests the translation of a JobItem.
   *
   * @param array $job_items
   *   Array with JobItem.
   *
   * @throws \Drupal\tmgmt\TMGMTException|\Drupal\Component\Plugin\Exception\PluginException
   *   In case of error doing request to iLangL service or returned Job id is 0.
   */
  public function requestJobItemsTranslation(array $job_items): void {
    $provider_id = reset($job_items)->getTranslator()->id();
    $provider_data = $this->getState('provider_data', $provider_id);
    $translator = $this->translator->load($provider_id);
    $availability = $this->checkAvailable($translator);
    $this->formatManager->clearCachedDefinitions();
    $xliff = $this->formatManager->createInstance('webxml');

    $json = [
      'ProjectId' => $provider_data['project_id'],
      'ServiceId' => $provider_data['service_id'],
      'CostCenterId' => '',
      'Due' => '',
      'Content' => [],
    ];

    foreach ($job_items as $job_item) {
      $job = $job_item->getJob();
      $job_item_id = $job_item->id();
      $xliff_content = $xliff->export($job, ['tjiid' => ['value' => $job_item_id]]);

      $job_item_name = (is_string($job->label()))
        ? sprintf('%s - %s', $job->label(), $job_item->label())
        : $job_item->label();

      $json['Content'][] = [
        'CMSContentId' => (int) $job_item->id(),
        'CMSContentType' => 'job',
        'CMSContentName' => $job_item_name,
        'Version' => '',
        'Targets' => [$job->getTargetLangcode()],
        'Body' => (is_string($xliff_content))
        ? $xliff_content
        : '',
      ];
    }

    if ($availability->getSuccess()) {
      $response = $this->request('/contentapi/order', $json, 'POST', [], $provider_id);

      if (isset($response['Content'])) {

        foreach ($response['Content'] as $key => $item) {
          $remote_jobs = $response['Content'][$key]['Jobs'];

          if (!empty($remote_jobs)) {
            $remote_job_item_id = $remote_jobs[0]['Id'];
            $job_item = JobItem::load($response['Content'][$key]['CMSContentId']);
            $job_item->addRemoteMapping(
              NULL,
              $job->id(),
              [
                'remote_identifier_2' => $job_item->id(),
                'remote_identifier_3' => $remote_job_item_id,
              ]
            );
            $job_item->active();
          }
          else {
            $job = $job_item->getJob();
            $job->rejected();
            // If iLangL API return empty Jobs array.
            $this->logger->error($this->t('Requested translation language not set up in your iLangL project.'));
            throw new TMGMTException('Requested translation language not set up in your iLangL project.');
          }
        }
        // Display debugger information.
        if ($provider_data['debug_mode']) {
          $this->debugerModeMessage($json, $response);
        }
      }
      else {
        // Display debugger information.
        if ($provider_data['debug_mode']) {
          $this->debugerModeMessage($json, $response);
        }

        $job = $job_item->getJob();
        $job->rejected();

        // If iLangL API return job id 0 throw TMGMTException.
        $this->logger->error($this->t('iLangL service return error.'));
        throw new TMGMTException('iLangL service return error.');
      }
    }
  }

  /**
   * Retrieves the currently active request object.
   *
   * @param string $path
   *   The path for request.
   * @param array $params
   *   Array with body parameters.
   * @param string $method
   *   Either "GET" or "POST". Other HTTP methods are not valid form submission
   *   methods.
   * @param array $options
   *   Array with headers parameters.
   * @param string $provider_id
   *   Current provider machine name.
   *
   * @return string|array
   *   Array returned by request response.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function request(string $path, array $params = [], string $method = 'GET', array $options = [], string $provider_id = '') {
    $provider_data = $this->getState('provider_data', $provider_id);
    $uri = $provider_data['domain'] . $path;
    $options = !empty($options)
      ? $options
      : ['Authorization' => 'Bearer ' . $provider_data['token']];

    try {
      if ($method == 'POST') {
        $response = $this->client->post($uri, [
          'json' => $params,
          'headers' => $options,
        ]);
      }
      else {
        $response = $this->client->get($uri, [
          'query' => $params,
          'headers' => $options,
        ]);
      }
    }
    catch (\Exception $e) {
      $message = $this->t(
          'Unable to connect to iLangL service due to following error: @error. Provider @provider unavailable. Check, please, provider configurations.',
          [
            '@error' => $e->getMessage(),
            '@provider' => $provider_id,
          ],
        );

      $this->logger->error($message);
      throw new TMGMTException($message);
    }

    if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
      $message = $this->t('iLangL service returned validation error: #%code %error',
        [
          '%code' => $response->getStatusCode(),
          '%error' => $response->getReasonPhrase(),
        ]);

      $this->logger->error($message);
      $this->messenger()->addError($message);
      throw new TMGMTException($message);
    }

    return Json::decode($response->getBody()->getContents());
  }

  /**
   * Handle fetching Job Item translations from iLangL service.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The JobItem entity.
   */
  public function fetchTranslation(JobItemInterface $job_item): void {
    try {
      $remotes = $job_item->getRemoteMappings();
      $translated = 0;

      foreach ($remotes as $remote) {
        $job_item = $remote->getJobItem();
        $remote_job_id = $remote->getRemoteIdentifier3();

        if ($remote->getRemoteIdentifier3()) {
          if ($this->updateIlanglTranslation($job_item, $remote_job_id)) {
            $translated++;
          }
        }
      }

      if (!$translated && !empty($remotes)) {
        if (!$this->getState('isCron')) {
          $message = $this->t('Job Item: @job_item not translated yet.', [
            '@job_item' => $job_item,
          ]);

          $this->messenger()->addWarning($message);
          $this->logger->warning($message);
        }
      }
      else {
        $untranslated = count($remotes) - $translated;

        if ($untranslated > 0) {
          $message = $this->t('Fetched translations for @translated job items, @untranslated items are not translated yet.',
            [
              '@translated' => $translated,
              '@untranslated' => $untranslated,
            ]
          );

          $this->messenger()->addStatus($message);
          $this->logger->notice($message);
        }
        else {

          // Check and write downloaded jobs ids.
          foreach ($remotes as $remote) {
            $remote_job_id = $remote->getRemoteIdentifier3();
            $cms_job_id = $remote->getRemoteIdentifier2();

            if (!$this->checkDownloaded($remote_job_id)) {
              $downloaded_jobs = $this->getState('downloaded_jobs');
              $downloaded_jobs[$cms_job_id] = $remote_job_id;
              $this->setState('downloaded_jobs', $downloaded_jobs);
            }
          }
        }
      }
    }
    catch (TMGMTException $e) {
      $message = $this->t('Job has been rejected with following error: @error',
        ['@error' => $e->getMessage()]
      );

      $this->messenger()->addError($message);
      $this->logger->error($message);
    }
    catch (\Exception $e) {
      $message = $this->t('Error: @error',
        ['@error' => $e->getMessage()]
      );

      $this->messenger()->addError($message);
      $this->logger->error($message);
    }
  }

  /**
   * Receives and stores a translation returned by iLangL.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The JobItem entity.
   * @param int $remote_job_id
   *   iLangL service Job id.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function updateIlanglTranslation(JobItemInterface $job_item, int $remote_job_id): bool {
    $translator = $job_item->getTranslator();
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $provider_data = $this->getState('provider_data', $provider_id);
    $query = ['ProjectId' => $provider_data['project_id']];
    $availability = $this->checkAvailable($translator);

    if ($availability->getSuccess()) {

      if ($this->checkDownloaded($remote_job_id)) {
        $get_fetched = $this->getJobTranslation($remote_job_id, $translator);

        if ($get_fetched) {
          $this->importIlanglTranslation($job_item, (string) $get_fetched);

          return TRUE;
        }
        else {
          return FALSE;
        }
      }
      else {
        // Request to the api/job/get endpoint.
        $get_job_list = $this->getJobList($translator);

        if ($get_job_list && in_array($remote_job_id, $get_job_list['Jobs'])) {

          // Display debugger information.
          if ($provider_data['debug_mode']) {
            $this->debugerModeMessage($query, array_slice($get_job_list['Jobs'], -10));
          }

          // Request to the api/job/download endpoint.
          $get_fetched = $this->getJobTranslation($remote_job_id, $translator);

          // Implementation of translation import.
          if ($get_fetched) {
            $this->importIlanglTranslation($job_item, (string) $get_fetched);
          }
          else {
            return TRUE;
          }

        }
        else {
          if (!$this->getState('isCron')) {
            $message = $this->t('iLangL JobItem with remote id: %d not translated yet.');
            $this->logger->warning(sprintf($message, $remote_job_id, $job_item->id()));
            throw new TMGMTException(sprintf($message, $remote_job_id, $job_item->id()));
          }
          return FALSE;
        }
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function abortTranslation(JobInterface $job): bool {
    /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote */
    foreach ($job->getRemoteMappings() as $remote) {
      $job_item = $remote->getJobItem();

      $job_item->setState(
        JobItemInterface::STATE_ABORTED,
        'The translation of <a href=":source_url">@source</a> has been aborted by the user.',
        [
          '@source' => $job_item->getSourceLabel(),
          ':source_url' => $job_item->getSourceUrl()
          ? $job_item->getSourceUrl()->toString()
          : (string) $job_item->getJob()->toUrl(),
        ]
      );
    }

    if ($job->isAbortable()) {
      $job->setState(JobInterface::STATE_ABORTED, 'Translation job has been aborted.');

      return TRUE;
    }

    return FALSE;
  }

  /**
   * Handle import fetched JobItem translation.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The JobItem entity.
   * @param string $fetched_translate
   *   XML returned by iLangL service.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\tmgmt\TMGMTException
   */
  private function importIlanglTranslation(JobItemInterface $job_item, string $fetched_translate): void {
    /** @var \Drupal\tmgmt_ilangl\Plugin\tmgmt_file\Format\WebXML $webxml */
    $webxml = $this->formatManager->createInstance('webxml');
    $data = $webxml->import($fetched_translate, FALSE);

    if ($data) {
      $job_item->getJob()->addTranslatedData($data, NULL, TMGMT_DATA_ITEM_STATE_TRANSLATED);
      $job_item->addMessage('The translation has been received.');
    }
    else {
      $message = $this->t(
        'Could not process received translation data for the target Job @job_id.',
        ['@job_id' => $job_item->id()]
      );

      $this->logger->error($message);
      throw new TMGMTException($message);
    }
  }

  /**
   * Helper method to display advanced information about module operations.
   */
  public function debugerModeMessage($req, $res): void {
    $request = [
      '#type' => 'container',
      '#prefix' => '<pre>',
      '#suffix' => '</pre>',
      '#plain_text' => json_encode($req, JSON_PRETTY_PRINT),
      '#attributes' => ['class' => ['custom-messages--status']],
    ];
    $request_message = $this->renderer->renderPlain($request);

    $response = [
      '#type' => 'container',
      '#prefix' => '<pre>',
      '#suffix' => '</pre>',
      '#plain_text' => (is_array($res))
      ? json_encode($res, JSON_PRETTY_PRINT)
      : $res,
      '#attributes' => ['class' => ['custom-messages--status']],
    ];
    $response_message = $this->renderer->renderPlain($response);

    $this->logger->notice('Request: %request </br> Response: %response', [
      '%request' => $request_message,
      '%response' => $response_message,
    ]);

    $this->messenger()->addStatus($this->t('Request: %request </br> Response: %response', [
      '%request' => $request_message,
      '%response' => $response_message,
    ]));
  }

  /**
   * Helper method to register the current site in iLangL service.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The Translator Entity.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function getRegister(TranslatorInterface $translator): ?array {
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $options = [
      'Content-Type' => 'application/json',
    ];

    $langs = [];
    $enabled_langs = $this->languageManager->getLanguages();

    foreach ($enabled_langs as $lang) {
      $langs[] = [
        'Code' => $lang->getId(),
        'Name' => $lang->getName(),
      ];
    }

    $json = [
      'SystemName' => 'Drupal',
      'Version' => \Drupal::VERSION,
      'Url' => $this->requestStack->getCurrentRequest()->getSchemeAndHttpHost(),
      'AvailableLanguages' => $langs,
      'SourceLanguages' => [
        [
          'Code' => 'en',
          'Name' => 'English',
        ],
      ],
    ];

    return $this->request('/api/extension/register', $json, 'POST', $options, $provider_id);
  }

  /**
   * Helper method to authenticate and get access token.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The Translator Entity.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function getAuth(TranslatorInterface $translator): ?array {
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $provider_data = $translator->getPlugin()->getState('provider_data', $provider_id);

    $options = [
      'Content-Type' => 'application/json',
    ];
    $json = [
      'ProjectId' => $provider_data['project_id'],
      'APIKey' => $provider_data['api_key'],
      'UserName' => $provider_data['username'],
      'Email' => $provider_data['email'],
      'FirstName' => 'FirstName',
      'LastName' => 'LastName',
    ];

    return $this->request('/account/authenticate', $json, 'POST', $options, $provider_id);
  }

  /**
   * Helper method to get translated Jobs list.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The Translator Entity.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function getJobList(TranslatorInterface $translator): ?array {
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $provider_data = $this->getState('provider_data', $provider_id);
    $query = ['ProjectId' => $provider_data['project_id']];

    $response = $this->request('/api/job/get', $query, 'GET', [], $provider_id);

    // Display debugger information.
    if ($provider_data['debug_mode'] && is_array($response)) {
      $this->debugerModeMessage($query, array_slice($response['Jobs'], -10));
    }

    return $response;
  }

  /**
   * Helper method to get Job translation.
   *
   * @param int $remote_job_id
   *   iLangL service Job id.
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The Translator Entity.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function getJobTranslation(int $remote_job_id, TranslatorInterface $translator): ?string {
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $provider_data = $this->getState('provider_data', $provider_id);
    $params = ['JobId' => $remote_job_id];
    $get_fetched = $this->request('/api/job/download', $params, 'POST', [], $provider_id);

    if (is_array($get_fetched)) {
      $get_fetched = reset($get_fetched);
    }

    if (!empty($get_fetched)) {
      // Display debugger information.
      if ($provider_data['debug_mode']) {
        $xml = simplexml_load_string($get_fetched, 'SimpleXMLElement', LIBXML_NOCDATA);
        $json = json_encode($xml);
        $array = json_decode($json, TRUE);

        $this->debugerModeMessage($params, $array);
      }

      return $get_fetched;
    }
    else {

      if ($provider_data['debug_mode']) {
        $this->debugerModeMessage($params, $get_fetched);
      }

      if (!$this->getState('isCron')) {
        $message = $this->t('iLangL service return empty string for this JobItems: @remote_job_id', [
          '@remote_job_id' => $remote_job_id,
        ]);

        $this->messenger->addWarning($message);
        $this->logger->warning($message);
      }

      return NULL;
    }
  }

  /**
   * Helper method to change remote job status.
   *
   * @param int $remote_job_id
   *   iLangL service Job id.
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The Translator Entity.
   * @param bool $state
   *   Set Job status (TRUE = Completed).
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function changeJobState(int $remote_job_id, TranslatorInterface $translator, $state = TRUE): ?bool {
    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $params = [
      'JobId' => $remote_job_id,
      'State' => $state,
    ];

    $changestate = $this->request('/api/job/changestate', $params, 'POST', [], $provider_id);

    if (isset($changestate)) {
      $message = $this->t(
        'Job Item status changed to Completed. Remote id: @remote_job_id.', [
          '@remote_job_id' => $remote_job_id,
        ]);
      $this->logger->info($message);
      $this->messenger()->addStatus($message);

      return $changestate;
    }

    return FALSE;
  }

  /**
   * Helper method to check if Job translation already was downloaded earlier.
   *
   * @param int $remote_job_id
   *   iLangL service Job id.
   */
  public function checkDownloaded(int $remote_job_id): bool {
    $downloaded_jobs = $this->getState('downloaded_jobs');

    if ($downloaded_jobs && is_array($downloaded_jobs)) {
      return in_array($remote_job_id, $downloaded_jobs);
    }

    return FALSE;
  }

  /**
   * Helper method to write module configurations data.
   *
   * @param string $key
   *   Key that identifies the data.
   * @param array $value
   *   The data to be set.
   * @param string $provider_id
   *   The current provider machine name.
   */
  public function setIlanglData(string $key, array $value, string $provider_id = ''): void {
    $data = (count($value) === 1 && is_string($value[0])) ? $value[0] : $value;

    if ($provider_id) {
      $this->config->set(sprintf('provider_data.%s.%s', $provider_id, $key), $data)->save();
    }
    else {
      $this->config->set($key, $data)->save();
    }
  }

  /**
   * Helper method return module configurations data.
   *
   * @param string $key
   *   Key that identifies the data.
   * @param string $provider_id
   *   The current provider machine name.
   */
  public function getIlanglData(string $key, string $provider_id = '') {
    if ($provider_id) {
      return $this->config->get(sprintf('provider_data.%s.%s', $provider_id, $key));
    }
    else {
      return $this->config->get($key);
    }
  }

  /**
   * Helper method to write data to State.
   *
   * @param string $key
   *   Key that identifies the data.
   * @param array $value
   *   The data to be set.
   * @param string $provider_id
   *   The current provider machine name.
   */
  public function setState(string $key, array $value, string $provider_id = ''): void {
    if (!empty($provider_id)) {
      $this->state->set(sprintf('ilangl_%s_%s', $provider_id, $key), $value);
    }
    else {
      $this->state->set($key, $value);
    }
  }

  /**
   * Helper method return data from State.
   *
   * @param string $key
   *   Key that identifies the data.
   * @param string $provider_id
   *   The current provider machine name.
   */
  public function getState(string $key, string $provider_id = '') {
    if (!empty($provider_id)) {
      return $this->state->get(sprintf('ilangl_%s_%s', $provider_id, $key));
    }
    else {
      return $this->state->get($key);
    }
  }

  /**
   * Helper method to change Cron Job title.
   */
  public function changeCronJobTitle(): void {

    if ($this->moduleHandler->moduleExists('ultimate_cron')) {
      $cron_job_title = 'iLangL translations fetcher';

      $ilangl_cron_settings = $this->configFactory
        ->getEditable('ultimate_cron.job.tmgmt_ilangl_cron');
      $ilangl_cron_settings->set('title', $cron_job_title);
      $ilangl_cron_settings->save(TRUE);
    }
  }

}
