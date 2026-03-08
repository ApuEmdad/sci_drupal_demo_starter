<?php

namespace Drupal\tmgmt_ilangl;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\BeforeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginUiBase;

/**
 * ILangL translator User Interface.
 */
class ILangLTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    /**
     * @var \Drupal\tmgmt\TranslatorInterface $translator
     */
    $translator = $form_state->getFormObject()->getEntity();

    /**
     * @var \Drupal\tmgmt\Entity\Translator $tmgmt_translator
     */
    $tmgmt_translator = \Drupal::routeMatch()->getParameter('tmgmt_translator');

    if (isset($tmgmt_translator)) {
      $provider_id = $tmgmt_translator->getOriginalId();
    }
    else {
      $provider_id = ($translator->id()) ?? $translator->getPluginId();
    }

    /**
     * @var \Drupal\tmgmt_ilangl\Plugin\tmgmt\Translator\ILangLTranslator $iLangLTranslator
     */
    $iLangLTranslator = $translator->getPlugin();

    $provider_data = $iLangLTranslator->getState('provider_data', $provider_id);

    $form['domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Domain'),
      '#default_value' => (!empty($provider_data['domain'])) ? $provider_data['domain'] : '',
      '#description' => $this->t('iLangL API domain'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['ilangl-domain'],
      ],
    ];

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User Name'),
      '#default_value' => (!empty($provider_data['username'])) ? $provider_data['username'] : '',
      '#description' => $this->t('User name registered in iLangL service'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['ilangl-username'],
      ],
    ];

    $form['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User E-mail'),
      '#default_value' => (!empty($provider_data['email'])) ? $provider_data['email'] : '',
      '#description' => $this->t('User E-mail registered in iLangL service'),
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['ilangl-email'],
      ],
    ];

    $form['service_id'] = [
      '#type' => 'hidden',
      '#value' => (!empty($provider_data['services'])) ? $provider_data['services'] : '',
    ];

    $default_service_id = (!empty($provider_data['service_id'])) ? $provider_data['service_id'] : '';
    $options = (!empty($provider_data['services'])) ? $provider_data['services'] : [];

    $form['services'] = [
      '#type' => 'select',
      '#title' => $this->t('Service'),
      '#default_value' => $default_service_id,
      '#description' => $this->t('Services in iLangL project'),
      '#required' => TRUE,
      '#options' => $options,
      '#empty_option' => $this->t('- Select service -'),
      '#validated' => TRUE,
    ];

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable test mode'),
      '#default_value' => (!empty($provider_data['debug_mode'])) ? $provider_data['debug_mode'] : 0,
      '#description' => $this->t('Display advanced information about module operations'),
    ];

    $form['connect'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect'),
      '#name' => 'ilangl_connect',
      '#submit' => [
        [$this, 'submitConnect'],
      ],
      '#ajax' => [
        'callback' => [$this, 'ajaxSubmitConnect'],
        'wrapper' => 'tmgmt-plugin-wrapper',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    /**
     * @var \Drupal\tmgmt\TranslatorInterface $translator
     */
    $translator = $form_state->getFormObject()->getEntity();

    /**
     * @var \Drupal\tmgmt_ilangl\Plugin\tmgmt\Translator\ILangLTranslator $iLangLTranslator
     */
    $ilanglTranslator = $translator->getPlugin();

    $provider_id = ($translator->id()) ?? $translator->getPluginId();

    $provider_data = $translator->getPlugin()->getState('provider_data', $provider_id);

    if ($provider_id) {
      $providers = $ilanglTranslator->getState('providers');
      $form_data = ['domain', 'username', 'email', 'debug_mode'];

      // Writing iLangL credential to configurations.
      foreach ($form_data as $key => $value) {
        $form_data[$value] = (isset($form_state->getValue('settings')[$value]))
          ? $form_state->getValue('settings')[$value]
          : '';
        unset($form_data[$key]);

        $provider_data[$value] = $form_state->getValue('settings')[$value];
      }

      if (!empty($form_state->getValue('settings')['services'])) {
        $provider_data['service_id'] = $form_state->getValue('settings')['services'];
      }

      $ilanglTranslator->setState('provider_data', $provider_data, $provider_id);

      if (!in_array($provider_id, $providers)) {
        $providers[] = $provider_id;
        $ilanglTranslator->setState('providers', $providers);
      }

      // Set iLangL Cron Job title.
      $ilanglTranslator->changeCronJobTitle();
    }
    else {
      $this->messenger()->addError($this->t('Specify Provider label, please.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConnect(array $form, FormStateInterface $form_state) {

  }

  /**
   * Handles ajax submit call of "Connect" button.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AJAX response.
   */
  public function ajaxSubmitConnect(array &$form, FormStateInterface $form_state): AjaxResponse {
    /**
     * @var \Drupal\tmgmt\TranslatorInterface $translator
     */
    $translator = $form_state->getFormObject()->getEntity();

    $provider_id = ($translator->id()) ?? $translator->getPluginId();

    /**
     * @var \Drupal\tmgmt_ilangl\Plugin\tmgmt\Translator\ILangLTranslator $iLangLTranslator
     */
    $ilanglTranslator = $translator->getPlugin();

    $provider_data = $ilanglTranslator->getState('provider_data', $provider_id);

    $response = new AjaxResponse();

    $message_wrapper = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['ilangl-connection-message'],
      ],
    ];
    $renderer = \Drupal::service('renderer');
    $result = $renderer->render($message_wrapper);

    $logger = \Drupal::logger('tmgmt_ilangl');

    try {

      $register = $ilanglTranslator->getRegister($translator);

      if ($register) {
        $project_id = $register['ProjectInfo']['Id'];
        $api_key = $register['APIKey'];

        $services = [];
        foreach ($register['ProjectInfo']['Services'] as $service) {
          $services[$service['Id']] = $service['Name'];
        }

        $provider_data['project_id'] = $register['ProjectInfo']['Id'];
        $provider_data['api_key'] = $register['APIKey'];
        $provider_data['remote_languages'] = $register['ProjectInfo']['Languages'];
        $provider_data['services'] = $services;

        if (isset($project_id) && isset($api_key)) {

          if ($provider_id) {
            $ilanglTranslator->setState('provider_data', $provider_data, $provider_id);

            $authenticate = $ilanglTranslator->getAuth($translator);

            if (isset($authenticate['access_token']) && isset($authenticate['.expires'])) {
              $expires = $authenticate['.expires'];
              $token = $authenticate['access_token'];

              $provider_data['token'] = $token;
              $provider_data['expires'] = $expires;
              $provider_data['provider_id'] = $provider_id;

              if (!empty($form_state->getValue('settings')['services'])) {
                $provider_data['service_id'] = $form_state->getValue('settings')['services'];
              }

              $ilanglTranslator->setState('provider_data', $provider_data, $provider_id);

              // Display debugger information.
              if ($provider_data['debug_mode']) {
                $this->messenger()->addStatus($this->t('Expires: @expires', ['@expires' => $expires]));
                $this->messenger()->addStatus($this->t('Token: @token', ['@token' => $token]));
                // Writing authentication information to dblog.
                $logger->info($this->t('Expires: @expires', ['@expires' => $expires]));
                $logger->info($this->t('Token: @token', ['@token' => $token]));
              }

              $response->addCommand(new BeforeCommand('#tmgmt-plugin-wrapper', $result));
              $response->addCommand(new MessageCommand($this->t('Successfully connected!'), '.ilangl-connection-message', [], TRUE));

              $form['plugin_wrapper']['settings']['services']['#options'] = ($services) ?? [];
              $selector = (\Drupal::VERSION < 9) ? '.form-item-settings-services' : '.form-item--settings-services';
              $response->addCommand(new ReplaceCommand($selector, $form['plugin_wrapper']['settings']['services']));

              foreach ($this->messenger()->all() as $type => $messages_by_type) {
                foreach ($messages_by_type as $message) {
                  $response->addCommand(new MessageCommand($message, '.ilangl-connection-message', ['type' => $type], FALSE));
                }
              }
            }
            else {
              $this->messenger()->addError($authenticate['error']);
              $logger->error($authenticate['error']);

              throw new TMGMTException($authenticate['error']);
            }
          }
          else {
            $response->addCommand(new BeforeCommand('#tmgmt-plugin-wrapper', $result));

            foreach ($this->messenger() as $type => $messages_by_type) {
              foreach ($messages_by_type as $message) {
                $response->addCommand(new MessageCommand($message, '.ilangl-connection-message', ['type' => $type], FALSE));
              }
            }
            $response->addCommand(new MessageCommand($this->t('Specify Provider label, please.'), '.ilangl-connection-message', ['type' => 'error'], FALSE));
          }
        }
        else {
          $message = $this->t('Response error. Some data is missing: Project id = @project_id, Service id = @api_key',
            [
              '@project_id' => $project_id,
              '@api_key' => $api_key,
            ]);
          $this->messenger()->addError($message);
          $logger->error($message);

          throw new TMGMTException($message);
        }
      }
    }
    catch (TMGMTException $e) {

      $response->addCommand(new BeforeCommand('#tmgmt-plugin-wrapper', $renderer));
      $response->addCommand(new MessageCommand($e->getMessage(), '.ilangl-connection-message', ['type' => 'error'], TRUE));
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job): array {
    $form = [];

    if ($job->isActive()) {
      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => $this->t('Fetch translations'),
        '#submit' => [[$this, 'submitFetchTranslations']],
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * Handles submit call of "Fetch Translations" button.
   *
   *  Add the variables descriptions here.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   Throws an exception when there is no translator assigned or when the
   *   translator is missing the plugin.
   */
  public function submitFetchTranslations(array &$form, FormStateInterface $form_state): void {
    /**
     * @var \Drupal\tmgmt\Entity\Job $job
     */
    $job = $form_state->getFormObject()->getEntity();

    /**
     * @var \Drupal\tmgmt_ilangl\Plugin\tmgmt\Translator\ILangLTranslator $translator
     */
    $translator = $job->getTranslator()->getPlugin();

    if ($translator && $translator->getPluginId() === 'ilangl') {

      foreach ($job->getItems() as $job_item) {
        $translator->fetchTranslation($job_item);
      }
    }
  }

}
