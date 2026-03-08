<?php

namespace Drupal\tmgmt_memsource;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\tmgmt\JobInterface;

/**
 * Memsource translator UI.
 */
class MemsourceTranslatorUi extends TranslatorPluginUiBase {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['service_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Phrase TMS Home URL'),
      '#default_value' => $translator->getSetting('service_url') ?: 'https://cloud.memsource.com/web',
      '#description' => $this->t('Please enter the Phrase TMS Home URL.'),
      '#required' => TRUE,
      '#placeholder' => 'https://cloud.memsource.com/web',
    ];
    $form['memsource_user_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User name'),
      '#default_value' => $translator->getSetting('memsource_user_name'),
      '#description' => $this->t('Please enter your Phrase TMS user name.'),
      '#required' => TRUE,
      '#placeholder' => 'user name',
    ];

    if (\Drupal::config($translator->getConfigDependencyName())->get('settings') === NULL) {
      $passwordFieldAttributes = [
        '#required' => TRUE,
        '#attributes' => ['value' => $translator->getSetting('memsource_password')],
      ];
    }
    else {
      $form['memsource_change_password'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Change password'),
      ];
      $passwordFieldAttributes = [
        '#states' => [
          'visible' => [
            ':input[name="settings[memsource_change_password]"]' => ['checked' => TRUE],
          ],
          'required' => [
            ':input[name="settings[memsource_change_password]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      if ($translator->getSetting('memsource_change_password')) {
        $passwordFieldAttributes['#attributes'] = ['value' => $translator->getSetting('memsource_password')];
      }
    }

    $form['memsource_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Please enter your Phrase TMS password.'),
      '#placeholder' => 'password',

    ] + $passwordFieldAttributes;

    $form['memsource_update_job_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set Phrase TMS job status to Delivered after import to Drupal'),
      '#default_value' => $translator->getSetting('memsource_update_job_status'),
    ];

    $form['enable_file_translation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable translation of file attachments'),
      '#description' => $this->t('If checked, allows attached files (matching configured TMGMT MIME types) to be sent to Phrase TMS for translation along with text content.'),
      '#default_value' => $translator->getSetting('enable_file_translation') ?? TRUE,
    ];

    $form['memsource_cron_use'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use cron to pull completed translations'),
      '#default_value' => $translator->getSetting('memsource_cron_use'),
    ];
    $cronFieldAttributes = [
      '#states' => [
        'visible' => [
          ':input[name="settings[memsource_cron_use]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['memsource_cron_start_hour'] = [
        '#type' => 'select',
        '#title' => $this->t('Active time window - Start Hour'),
        '#options' => $this->getHourOptions(),
        '#default_value' => $translator->getSetting('memsource_cron_start_hour') ?? '8',
        '#required' => FALSE,
    ] + $cronFieldAttributes;

    $form['memsource_cron_end_hour'] = [
        '#type' => 'select',
        '#title' => $this->t('Active time window - End Hour'),
        '#options' => $this->getHourOptions(),
        '#default_value' => $translator->getSetting('memsource_cron_end_hour') ?? '18',
        '#required' => FALSE,
    ] + $cronFieldAttributes;

    $form['memsource_cron_time'] = [
        '#type' => 'number',
        '#title' => $this->t('Cron time'),
        '#default_value' => $translator->getSetting('memsource_cron_time') ?? 5,
        '#min' => 5,
        '#step' => 1,
        '#description' => $this->t('Please enter the pull translations start time in minutes.'),
        '#required' => TRUE,
        '#placeholder' => '',
      ] + $cronFieldAttributes;

    $form['memsource_cron_limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Cron items limit'),
        '#default_value' => $translator->getSetting('memsource_cron_limit') ?? 100,
        '#description' => $this->t('Please enter the limit items count processed in the cron job.'),
        '#required' => FALSE,
        '#placeholder' => '',
      ] + $cronFieldAttributes;

    $form += parent::addConnectButton();

    if ($translator->getPlugin()->checkMemsourceConnection($translator)) {
      $form['memsource_connector_token'] = [
        '#type' => 'select',
        '#title' => $this->t('Preview connector'),
        '#description' => $this->t('Please select your preview connector.'),
        '#default_value' => $translator->getSetting('memsource_connector_token'),
        '#empty_option' => $this->t('- Select -'),
        '#options' => $translator->getPlugin()->getDrupalConnectors($translator),
      ];
    }
    else {
      $form['memsource_connector_token'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('Unable to authenticate to Phrase TMS. Please check your login credentials and try to connect again.') . '</div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $currentSettings = \Drupal::config($translator->getConfigDependencyName())->get('settings');
    if ($currentSettings !== NULL && $form_state->getValue(['settings', 'memsource_change_password']) === 0) {
      $translator->setSetting('memsource_password', $currentSettings['memsource_password']);
      $form_state->setValue(['settings', 'memsource_password'], $currentSettings['memsource_password']);
    }
    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $plugin */
    $plugin = $translator->getPlugin();
    $plugin->setTranslator($translator);
    $result = $plugin->loginToMemsource();
    if (!$result) {
      $form_state->setErrorByName('settings][service_url', $this->t('Login incorrect. Please check the API endpoint, user name and password.'));
    }
    else {
      $pwd = $form_state->getValue(['settings', 'memsource_password']);
      $form_state->setValue(['settings', 'memsource_password'], $plugin->encodePassword($pwd));
      $form_state->setValue(['settings', 'memsource_change_password'], 0);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {

    if (!$job->getTranslator()->getPlugin()->checkMemsourceConnection($job->getTranslator())) {
      return [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t('Unable to authenticate to Phrase TMS. Please select a different provider or check your login credentials.') . '</div>',
      ];
    }

    $form_values = $form_state->getUserInput();
    $settings = [];
    if (array_key_exists('settings', $form_values)) {
      if (array_key_exists('project_template', $form_values['settings'])) {
        $settings['project_template'] = $form_values['settings']['project_template'];
      }
      if (array_key_exists('due_date', $form_values['settings'])) {
        $settings['due_date'] = $form_values['settings']['due_date'];
      }
      if (array_key_exists('group_jobs', $form_values['settings'])) {
        $settings['group_jobs'] = $form_values['settings']['group_jobs'];
      }
      if (array_key_exists('force_new_project', $form_values['settings'])) {
        $settings['force_new_project'] = $form_values['settings']['force_new_project'];
      }
      if (array_key_exists('batch_id', $form_values['settings'])) {
        $settings['batch_id'] = $form_values['settings']['batch_id'];
      }
    }

    // Save the settings and job in between to prevent error:
    // "The job has no provider assigned".
    $job->set('settings', $settings);
    $job->save();

    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());

    $project_templates = [];
    // phpcs:disable DrupalPractice.CodeAnalysis.VariableAnalysis.UndefinedVariable.
    for ($page = 0; !isset($templates_page) || !empty($templates_page['content']); $page++) {
      $templates_page = $translator_plugin->sendApiRequest('/api2/v1/projectTemplates', 'GET', ['pageNumber' => $page]);
      $project_templates = array_merge($project_templates, $templates_page['content']);
    }

    // Get langs from select fields.
    $sourceLang = $job->getRemoteSourceLanguage();
    $targetLang = $job->getRemoteTargetLanguage();

    $templates = [];

    foreach ($project_templates as $template) {
      // Display only templates which match the selected source.
      // AND (match the selected target langs OR target langs is empty).
      if (
        $template['sourceLang'] === $sourceLang &&
        (in_array($targetLang, $template['targetLangs'], TRUE) || $template['targetLangs'] === NULL)
      ) {
        $templates[$template['id']] = $template['templateName'];
      }
    }

    natcasesort($templates);

    $options = ['0' => '-'] + $templates;

    $settings['project_template'] = [
      '#type' => 'select',
      '#title' => $this->t('Project template'),
      '#options' => $options,
      '#description' => $this->t('Select a Phrase TMS project template.'),
    ];

    $settings['due_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Due Date'),
      '#date_date_format' => 'Y-m-d',
      '#description' => $this->t('Enter the due date of this translation.'),
      '#default_value' => NULL,
    ];

    $settings['group_jobs'] = [
      '#type' => 'checkbox',
      '#default_value' => FALSE,
      '#title' => $this->t('Group all translation jobs in a single Phrase TMS project.'),
    ];

    $settings['force_new_project'] = [
      '#type' => 'checkbox',
      '#description' => $this->t('If checked, a new Phrase TMS project will be created for this job or the selected group of jobs, even if a suitable project already exists.'),
      '#default_value' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="settings[group_jobs]"]' => ['checked' => TRUE],
        ],
      ],
      '#title' => $this->t('Force to create new Phrase TMS project.'),
    ];

    $settings['batch_id'] = [
      '#type' => 'hidden',
      '#default_value' => uniqid(),
    ];

    if (version_compare(\Drupal::VERSION, '9.0.0', '>=')) {
      $settings['due_date']['#attributes'] = ['placeholder' => 'YYYY-MM-DD'];
    }

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    $form = [];

    if ($job->isActive()) {

      /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
      $translator = $job->getTranslator();
      if ($translator->getPluginId() == 'memsource' && $translator->getSetting('memsource_cron_use')) {

        $startTime = $translator->getSetting('memsource_cron_start_hour') ?? 8;
        $endTime = $translator->getSetting('memsource_cron_end_hour') ?? 18;
        $nowHour = date('G');
        if ($nowHour < $startTime || $nowHour >= $endTime) {
          $form['actions']['info'] = [
            '#type' => 'markup',
            '#weight' => -20,
            '#markup' => '<div class="messages messages--warning">' . $this->t('The cron job runs from %start to %end. Translation must be pulled manually for now.', ['%start' => $startTime.':00', '%end' => $endTime.':00']) . '</div>',
          ];
        }
        else {
          $lastTime = \Drupal::state()->get('cron_las_time.' . $translator->id(), 0);

          $form['actions']['info'] = [
            '#type' => 'markup',
            '#weight' => -20,
            '#markup' => '<div class="messages messages--info">' . $this->t('The cron job is enabled. Translations will be pulled automatically.') . ($lastTime ? ' ' . t('The last cron run was at %time.', ['%time' => date('c', $lastTime)]): '') . '</div>',
          ];
        }
      }

      $form['actions']['pull'] = [
        '#type' => 'submit',
        '#value' => $this->t('Pull translations'),
        '#submit' => [[$this, 'submitPullTranslations']],
        '#weight' => -10,
      ];
    }

    return $form;
  }

  /**
   * Submit callback to pull translations form Memsource Cloud.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();

    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();

    $result = $translator_plugin->fetchTranslatedFiles($job);
    $translated = $result['translated'];
    $untranslated = $result['untranslated'];



    if (count($result['errors']) === 0) {
      if ($untranslated == 0 && $translated != 0) {
        $message = 'Fetched translations for @translated job items.';
        $job->addMessage($message, ['@translated' => $translated]);
      }
      elseif ($translated == 0) {
        $message = 'No job item has been translated yet.';
        $this->messenger()->addStatus($message);
      }
      else {
        $message = 'Fetched translations for @translated job items, @untranslated are not translated yet.';
        $job->addMessage($message, [
          '@translated' => $translated,
          '@untranslated' => $untranslated,
        ]);
      }
    } else {
      \Drupal::logger('tmgmt_memsource')->error('Errors occurred during translation fetching: @errors', [
        '@errors' => implode(', ', $result['errors']),
      ]);
    }

    tmgmt_write_request_messages($job);

    // Check the state of all job items after the pull.
    $items = $job->getItems();
    foreach ($items as $item) {
      $item_id = $item->id();
      $item_state = $item->getState();
      // If the item is still in ACTIVE state but should be in REVIEW state, force it.
      if ($item_state == \Drupal\tmgmt\JobItemInterface::STATE_ACTIVE) {
        // Check if all mappings for this item are completed.
        $mappings = \Drupal\tmgmt\Entity\RemoteMapping::loadByLocalData($job->id(), $item_id);
        $all_completed = TRUE;
        $completed_count = 0;
        $total_count = count($mappings);

        foreach ($mappings as $mapping) {
          $remote_data = $mapping->get('remote_data')->getValue();
          if (!empty($remote_data)) {
            $remote_data = reset($remote_data);
            $state = isset($remote_data['TmsState']) ? $remote_data['TmsState'] : '';

            if ($state === 'COMPLETED') {
              $completed_count++;
            } else {
              $all_completed = FALSE;
            }
          }
        }
        if ($all_completed && $total_count > 0) {

          // Force update the state directly in the database.
          try {
            \Drupal::database()->update('tmgmt_job_item')
              ->fields(['state' => \Drupal\tmgmt\JobItemInterface::STATE_REVIEW])
              ->condition('tjiid', $item_id)
              ->execute();
            // Reload the job item to verify the state change.
            \Drupal::entityTypeManager()->getStorage('tmgmt_job_item')->resetCache([$item_id]);
          }
          catch (\Exception $e) {
            \Drupal::logger('tmgmt_memsource')->error('Exception during forced state update for job item @item_id: @error', [
              '@item_id' => $item_id,
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }
  }

  protected function getHourOptions() {
    $options = [];
    for ($hour = 0; $hour <= 23; $hour++) {
      $options[$hour] = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
    }
    return $options;
  }

}
