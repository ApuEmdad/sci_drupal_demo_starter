<?php

namespace Drupal\tmgmt_ilangl\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * FAQs search form with autocomplete.
 */
class AbortJobItemForm extends FormBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * The state key value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The JobItem storage.
   *
   * @var \Drupal\tmgmt\JobItemInterface
   */
  protected $jobItemStorage;

  /**
   * The logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The construct method for Abort translations form.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key/value store.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger instance.
   */
  public function __construct(ClientInterface $client, StateInterface $state, EntityTypeManagerInterface $entityTypeManager, LoggerInterface $logger) {
    $this->client = $client;
    $this->state = $state;
    $this->jobItemStorage = $entityTypeManager
      ->getStorage('tmgmt_job_item');
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    /**
     * @var \GuzzleHttp\ClientInterface $client
     */
    $client = $container->get('http_client');

    /**
     * @var \Drupal\Core\State\StateInterface $state
     */
    $state = $container->get('state');

    /**
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
     */
    $entityTypeManager = $container->get('entity_type.manager');

    /**
     * @var \Psr\Log\LoggerInterface $logger
     */
    $logger = $container->get('logger.factory')->get('tmgmt_ilangl');

    return new static($client, $state, $entityTypeManager, $logger);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'abort_job_item';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Please, tell us the reason why you decided to abort this Job Item.'),
      '#required' => TRUE,
    ];

    $form['validation_message'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => 'abort-validation-message',
      ],
    ];

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'abort-job-item-submit',
      ],
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Send'),
        '#ajax' => [
          'callback' => '::sendAbortMessage',
        ],
        '#attributes' => [
          'class' => ['use-ajax-submit'],
          'data-dialog-type' => 'modal',
        ],
      ],
    ];

    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attached']['library'][] = 'core/jquery.form';

    return $form;
  }

  /**
   * Callback function for Abort JobItem translation.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response.
   */
  public function sendAbortMessage(array &$form, FormStateInterface $form_state): AjaxResponse {
    $jod_item_id = $this->getRequest()->query->get('aborted_job_item');
    $jod_item = $this->jobItemStorage->load($jod_item_id);

    /**
     * @var \Drupal\tmgmt\JobInterface $job
     */
    $job = $jod_item->getJob();

    /**
     * @var \Drupal\tmgmt\TranslatorInterface $translator
     */
    $translator = $jod_item->getTranslator();

    /**
     * @var \Drupal\tmgmt_ilangl\Plugin\tmgmt\Translator\ILangLTranslator $iLangLTranslator
     */
    $ilanglTranslator = $translator->getPlugin();

    $provider_id = ($translator->id()) ?? $translator->getPluginId();
    $provider_data = $ilanglTranslator->getState('provider_data', $provider_id);

    $message = $form_state->getValue('message');
    $validation_message = '';
    $response = new AjaxResponse();

    if (!empty($message)) {
      foreach ($jod_item->getRemoteMappings() as $remote) {
        $remote_job_id = $remote->getRemoteIdentifier3();

        if ($remote_job_id) {
          $json = [
            'JobId' => (int) $remote_job_id,
            'CMSContentId' => $jod_item_id,
            'CMSContentType' => 'job',
            'source'  => $job->getSourceLangcode(),
            'target' => $job->getRemoteTargetLanguage(),
            'Description' => $message,
          ];

          if ($ilanglTranslator && $ilanglTranslator->getPluginId() === 'ilangl') {
            $abort_job_item = $ilanglTranslator->request('/api/job/abort', $json, 'POST', [], $provider_id);

            if ($provider_data['debug_mode']) {
              $ilanglTranslator->debugerModeMessage($json, $abort_job_item);
            }

            if ($abort_job_item) {
              $url = Url::fromRoute('entity.tmgmt_job_item.abort_form',
                ['tmgmt_job_item' => $jod_item_id]
              );
              $response->addCommand(new RedirectCommand($url->toString()));
              $this->messenger()->deleteByType('error');
              $this->messenger()->addStatus($this->t('Your message was sent. Thank you.'));

              // Send request to change remote Job status to "Review".
              $state_json = [
                'JobId' => $remote_job_id,
                'State' => FALSE,
              ];

              $review = $ilanglTranslator->request('/api/job/changestate', $state_json, 'POST', [], $provider_id);

              if ($review) {
                $message = $this->t(
                  'Job status changed to Review. JobItem id: @job_item_id, Remote id: @remote_job_id.', [
                    '@remote_job_id' => $remote_job_id,
                    '@job_item_id' => $jod_item_id,
                  ]);

                $this->messenger()->addStatus($message);
                $this->logger->notice($message);

                // Change JobItem status to the Active.
                $jod_item->active();
                $jod_item->addMessage($this->t(
                  'Your message about Abort translation was successfully sent to iLangL.'
                ));

                if ($provider_data['debug_mode']) {
                  $ilanglTranslator->debugerModeMessage($state_json, $review);
                }

                return $response;
              }
              else {
                $message = $this->t(
                  'Job status not changed. iLangL API return False. JobItem id: @job_item_id, Remote id: @remote_job_id.', [
                    '@remote_job_id' => $remote_job_id,
                    '@job_item_id' => $jod_item_id,
                  ]);

                $this->messenger()->addError($message);
                $this->logger->error($message);
              }
            }
          }
        }
      }
    }
    else {
      $validation_message = $this->t('Please, fill required fields *');
    }

    $response->addCommand(new HtmlCommand('.abort-validation-message', $validation_message));
    $response->addCommand(new CssCommand('.abort-validation-message', ['color' => '#dc2323']));

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
