<?php

namespace Drupal\tmgmt_memsource\Plugin\tmgmt\Translator;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\InfoParser;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\Entity\File;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phrase TMS translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "memsource",
 *   label = @Translation("phrase"),
 *   description = @Translation("Phrase TMS translator service."),
 *   ui = "Drupal\tmgmt_memsource\MemsourceTranslatorUi",
 *   files = TRUE
 * )
 */
class MemsourceTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface, ContinuousTranslatorInterface {
  use StringTranslationTrait;

  const PASSWORD_V2_PREFIX = 'MEMSOURCE_V2___';
  const PASSWORD_V2_PREFIX_LENGTH = 15;
  const CHECK_JOB_MAX_RETRIES = 5;

  /**
   * The translator.
   *
   * @var \Drupal\tmgmt\TranslatorInterface
   */
  private $translator;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Info file parser.
   *
   * @var \Drupal\Core\Extension\InfoParser
   */
  protected $parser;

  /**
   * Version of tmgmt_memsource module.
   *
   * @var string|null
   */
  protected $moduleVersion = NULL;

  /**
   * Memsource action generated for session.
   *
   * @var string|null
   */
  private $memsourceActionId = NULL;

  /**
   * Constructs a MemsourceTranslator object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param \Drupal\Core\Extension\InfoParser $parser
   *   Info file parser.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, InfoParser $parser, array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
    $this->parser = $parser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \GuzzleHttp\ClientInterface $client */
    $client = $container->get('http_client');
    /** @var \Drupal\Core\Extension\InfoParser $parser */
    $parser = $container->get('info_parser');
    return new static(
      $client,
      $parser,
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * Sets a Translator.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator to set.
   */
  public function setTranslator(TranslatorInterface $translator) {
    $this->translator = $translator;
  }

  /**
   * Checks if plugin is able to connect to Memsource.
   */
  public function checkMemsourceConnection(TranslatorInterface $translator): bool {
    $users = [];
    $this->setTranslator($translator);

    try {
      $users = $this->sendApiRequest('/api2/v1/auth/whoAmI');
    }
    catch (\Exception $e) {
      // Ignore exception, only testing connection.
    }

    if ($users) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    $supported_remote_languages = [];
    $this->setTranslator($translator);
    try {
      $supported_languages = $this->sendApiRequest('/api2/v1/languages');
      if (isset($supported_languages['languages']) &&
        (is_array($supported_languages['languages']) || is_object($supported_languages['languages']))) {
        foreach ($supported_languages['languages'] as $language) {
          $supported_remote_languages[$language['code']] = $language['name'];
        }
      }
    }
    catch (\Exception $e) {
      // Ignore exception, nothing we can do.
    }
    asort($supported_remote_languages);
    return $supported_remote_languages;
  }

  /**
   * Gets all Drupal connectors.
   */
  public function getDrupalConnectors(TranslatorInterface $translator): array {
    $tokens = [];
    $this->setTranslator($translator);
    try {
      $connectors = $this->sendApiRequest('/api2/v1/connectors');

      $connectors = array_filter($connectors['connectors'], static function ($connector) {
          return $connector['type'] === 'DRUPAL_PLUGIN';
      });

      foreach ($connectors as $connector) {
        $tokens[$connector['localToken']] = $connector['name'];
      }
    }
    catch (\Exception $e) {
      // Ignore exception, nothing we can do.
    }

    return $tokens;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    if ($this->getToken()) {
      return AvailableResult::yes();
    }
    return AvailableResult::no($this->t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $job = $this->requestJobItemsTranslation($job->getItems());
    if (!$job->isRejected()) {
      $job->submitted();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function requestJobItemsTranslation(array $job_items) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();
    $this->setTranslator($job->getTranslator());

    $project_id = NULL;
    if ($job->getSetting('group_jobs')) {
      $project_id = $this->getMemsourceProjectIdByBatchId(reset($job_items));
      if (!$job->getSetting('force_new_project') && !$project_id) {
        $project_id = $this->getMemsourceProjectIdByContent($job_items);
      }
    }

    try {
      if ($project_id) {
        $job->addMessage('Using an existing project in Phrase TMS with the id: @id', ['@id' => $project_id], 'debug');
      }
      else {
        $project_id = $this->newTranslationProject($job);
        $job->addMessage('Created a new project in Phrase TMS with the id: @id', ['@id' => $project_id], 'debug');
      }

      $job_part_uids = [];

      /** @var \Drupal\tmgmt\Entity\JobItem $job_item */
      foreach ($job_items as $job_item) {
        // Get the TMGMT data service.
        $data_service = \Drupal::service('tmgmt.data');
        // Check if file translation is enabled in the translator settings and current TMGMT version of plugin supports it.
        $enable_file_translation = $this->translator->getSetting('enable_file_translation')
            && method_exists($data_service, 'getTranslatableFiles')
            && method_exists($data_service, 'createFileTranslation');

        $translatable_files = [];

        if ($enable_file_translation) {
          // Identify translatable files in the job item data.
          $job_item_data = $job_item->getData();
          $translatable_files = $this->getTranslatableFiles($job_item_data);

          if (!empty($translatable_files)) {
            $this->logDebug('Found @count translatable files in job item @id', [
              '@count' => count($translatable_files),
              '@id' => $job_item->id(),
            ]);

            // Store identified files in the job item's translator_state for persistence.
            $serialized_files = [];
            foreach ($translatable_files as $key => $file_info) {
              $serialized_files[$key] = [
                'datakey' => $file_info['datakey'],
                'file_id' => $file_info['file']->id(),
                'filename' => $file_info['file']->getFilename(),
                'mime_type' => $file_info['file']->getMimeType(),
                // We'll add the job_part_id later when we have it.
              ];
            }

            $job_item->set('translator_state', [
              'tmgmt_memsource_files' => $serialized_files,
            ]);

            try {
              $save_result = $job_item->save();

              if ($save_result === FALSE) {
                $this->logError('Failed to save job item @id with translator_state data', [
                  '@id' => $job_item->id(),
                ]);

                $job->addMessage('Failed to save job item data for file tracking. File translation may not work correctly.', [], 'warning');
              }
            }
            catch (\Exception $e) {
              $this->logError('Exception while saving job item @id with translator_state data: @error', [
                '@id' => $job_item->id(),
                '@error' => $e->getMessage(),
              ]);

              $job->addMessage('Exception while saving job item data: @error', [
                '@error' => $e->getMessage(),
              ], 'warning');
            }
          }
        }
        else {
          // Ensure translator_state is cleared or not set with files if disabled.
          $job_item->set('translator_state', NULL);

          try {
            $job_item->save();
          }
          catch (\Exception $e) {
            $this->logError('Exception while saving job item @id when clearing translator_state data: @error', [
              '@id' => $job_item->id(),
              '@error' => $e->getMessage(),
            ]);

            $job->addMessage('Exception while clearing job item data: @error', [
              '@error' => $e->getMessage(),
            ], 'warning');
          }
        }

        $retry = 0;
        $success = FALSE;

        // First, upload the XLIFF content.
        while ($retry < self::CHECK_JOB_MAX_RETRIES && !$success) {
          try {
            $item_type = $job_item->get('item_type')->getString();
            $item_id = $job_item->get('item_id')->getString();
            $due_date = $job->getSetting('due_date');
            $job_part_id = $this->sendFiles($job_item, $project_id, $due_date, $item_type, $item_id);
            // Create the mapping data for XLIFF.
            $xliff_mapping_data = [
              'tjid' => $job->id(),
              'tjiid' => $job_item->id(),
              'remote_identifier_1' => $this->getPluginId(),
              'remote_identifier_2' => $project_id,
              'remote_identifier_3' => $job_part_id,
            ];

            /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_mapping */
            $remote_mapping = RemoteMapping::create($xliff_mapping_data);
            $save_result = $remote_mapping->save(); // Save the mapping.
            if (!$save_result || !$remote_mapping->id()) {
              $this->logError('Failed to save base XLIFF remote mapping entity.');
              $job->addMessage('Failed to save remote mapping for XLIFF content. Translation may not be properly tracked.', [], 'error');
              throw new TMGMTException('Failed to save base XLIFF remote mapping entity.');
            }

            // Add the remote data.
            try {
              $remote_mapping->addRemoteData('isFile', FALSE);
              $remote_mapping->addRemoteData('FileStateVersion', 1);
              $remote_mapping->addRemoteData('TmsState', 'TranslatableSource');
              $remote_mapping->addRemoteData('RequiredBy', $due_date);
              $save_result_2 = $remote_mapping->save();

              if (!$save_result_2) {
                $this->logError('Failed to save XLIFF remote mapping with remote data.');
                $job->addMessage('Failed to save remote mapping data for XLIFF content. Translation may not be properly tracked.', [], 'error');
                throw new TMGMTException('Failed to save XLIFF remote mapping with remote data.');
              }
            }
            catch (\Exception $e) {
              $this->logError('Exception while saving XLIFF remote mapping with remote data: @error', [
                '@error' => $e->getMessage(),
              ]);
              $job->addMessage('Exception while saving remote mapping data: @error', [
                '@error' => $e->getMessage(),
              ], 'error');
              throw new TMGMTException('Exception while saving XLIFF remote mapping with remote data: ' . $e->getMessage());
            }

            if ($job_item->getJob()->isContinuous()) {
              $job_item->active();
            }

            $job_part_uids[] = $job_part_id;
            $success = TRUE;
          }
          catch (\Exception $e) {
            $retry++;

            $this->logWarn('Job item (LABEL: @label) and TMS project (UID: @projectId) failed after (RETRY: @retry): @error', [
              '@label' => $job_item->label(),
              '@projectId' => $project_id,
              '@retry' => $retry,
              '@error' => $e->getMessage(),
            ]);

            if (isset($remote_mapping)) {
              $remote_mapping->delete();
              unset($remote_mapping);
            }

            if ($retry < self::CHECK_JOB_MAX_RETRIES) {
              sleep($retry);
            }
          }
        }
        // Now, upload any translatable files.
        if ($success && $enable_file_translation && !empty($translatable_files)) {
          $target_language = $job->getRemoteTargetLanguage();
          $due_date = $job->getSetting('due_date');

          foreach ($translatable_files as $key => $file_info) {

            $file = $file_info['file'];
            $datakey = $file_info['datakey'];

            $retry = 0;
            $file_success = FALSE;

            while ($retry < self::CHECK_JOB_MAX_RETRIES && !$file_success) {
              try {
                $this->logDebug('Uploading file @filename (FID: @fid) for translation', [
                  '@filename' => $file->getFilename(),
                  '@fid' => $file->id(),
                ]);

                // Prepare API request for file upload.
                $api_path = "/api2/v1/projects/$project_id/jobs";
                // Prepare headers for file upload.
                $memsource_header_data = [
                  'targetLangs' => [$target_language],
                  'useProjectFileImportSettings' => TRUE,
                ];
                if (strlen($due_date) > 0) {
                  $memsource_header_data['due'] = $this->convertDateToEod($due_date);
                }
                $memsource_header_json = Json::encode($memsource_header_data);

                $filename_encoded = rawurlencode($file->getFilename());
                $headers = [
                  'Content-Type' => 'application/octet-stream',
                  'Memsource' => $memsource_header_json,
                  'Content-Disposition' => "attachment; filename*=UTF-8''" . $filename_encoded,
                  'memsource-action-id' => $this->getMemsourceActionId(),
                ];

                try {
                  $file_content = file_get_contents($file->getFileUri());
                  if ($file_content === FALSE) {
                    $error_message = error_get_last() ? error_get_last()['message'] : 'Unknown error';
                    $this->logError('Failed to read file content from @uri: @error', [
                      '@uri' => $file->getFileUri(),
                      '@error' => $error_message,
                    ]);

                    $job->addMessage('Failed to read file @filename for translation: @error', [
                      '@filename' => $file->getFilename(),
                      '@error' => $error_message,
                    ], 'error');

                    throw new TMGMTException('Failed to read file content from @uri: @error', [
                      '@uri' => $file->getFileUri(),
                      '@error' => $error_message,
                    ]);
                  }
                }
                catch (\Exception $e) {
                  $this->logError('Exception while reading file @filename (FID: @fid): @error', [
                    '@filename' => $file->getFilename(),
                    '@fid' => $file->id(),
                    '@error' => $e->getMessage(),
                  ]);

                  $job->addMessage('Exception while reading file @filename for translation: @error', [
                    '@filename' => $file->getFilename(),
                    '@error' => $e->getMessage(),
                  ], 'error');

                  throw $e;
                }
                // Send the file to Phrase TMS.
                $response = $this->sendApiRequest($api_path, 'POST', $headers, FALSE, FALSE, $file_content);

                // Validate response structure.
                if (!isset($response['jobs']) || !is_array($response['jobs']) || empty($response['jobs'])) {
                  throw new TMGMTException('Invalid response from Phrase TMS API: missing or empty jobs array');
                }

                // Get the job part UID from the response.
                $file_job_part_id = $this->getUidOfLatestJob($response['jobs']);

                if (empty($file_job_part_id)) {
                  throw new TMGMTException('Failed to get job part UID from Phrase TMS response');
                }

                // Create the mapping data WITHOUT remote_data.
                $mapping_data_to_save = [
                  'tjid' => $job->id(),
                  'tjiid' => $job_item->id(),
                  'remote_identifier_1' => $this->getPluginId(), // Use Plugin ID.
                  'remote_identifier_2' => $project_id,
                  'remote_identifier_3' => $file_job_part_id,
                  // No 'remote_data' key here initially.
                ];

                // Create a new RemoteMapping for this file.
                $file_remote_mapping = RemoteMapping::create($mapping_data_to_save);
                $save_result = $file_remote_mapping->save(); // Save the base mapping.
                if (!$save_result || !$file_remote_mapping->id()) {
                  $this->logError('Failed to save base file remote mapping entity for file @filename (FID: @fid).', [
                    '@filename' => $file->getFilename(),
                    '@fid' => $file->id(),
                  ]);

                  $job->addMessage('Failed to save remote mapping for file @filename. Translation may not be properly tracked.', [
                    '@filename' => $file->getFilename(),
                  ], 'error');

                  throw new TMGMTException('Failed to save base file remote mapping entity.');
                }

                // Add remote data using the Entity API.
                try {
                  // Add each piece of data individually using the Entity API.
                  $file_remote_mapping->addRemoteData('isFile', TRUE);
                  $file_remote_mapping->addRemoteData('source_fid', $file->id());
                  $file_remote_mapping->addRemoteData('datakey', $datakey);
                  $file_remote_mapping->addRemoteData('TmsState', 'TranslatableSource');

                  if (!empty($due_date)) {
                      $file_remote_mapping->addRemoteData('RequiredBy', $due_date);
                  }

                  // Save the mapping with the added remote data.
                  $save_result_2 = $file_remote_mapping->save();

                  if (!$save_result_2) {
                    $this->logError('Failed to save file remote mapping with remote data for file @filename (FID: @fid).', [
                      '@filename' => $file->getFilename(),
                      '@fid' => $file->id(),
                    ]);

                    $job->addMessage('Failed to save remote mapping data for file @filename. Translation may not be properly tracked.', [
                      '@filename' => $file->getFilename(),
                    ], 'error');

                    throw new TMGMTException('Failed to save file remote mapping with remote data.');
                  }

                } catch (\Exception $e) {
                  $this->logError('Exception adding remote_data for file mapping ID @id: @error', [
                    '@id' => $file_remote_mapping->id(),
                    '@error' => $e->getMessage(),
                  ]);
                  throw $e;
                }

                $job_part_uids[] = $file_job_part_id;
                $file_success = TRUE;

                $this->logDebug('Successfully uploaded file @filename (FID: @fid) for translation. Job part ID: @job_part_id', [
                  '@filename' => $file->getFilename(),
                  '@fid' => $file->id(),
                  '@job_part_id' => $file_job_part_id,
                ]);

                // Update the translator_state to include the job_part_id for this file.
                $translator_state = $job_item->get('translator_state')->getValue();
                if (!empty($translator_state[0]['value']['tmgmt_memsource_files'][$datakey])) {
                  $translator_state[0]['value']['tmgmt_memsource_files'][$datakey]['job_part_id'] = $file_job_part_id;
                  $job_item->set('translator_state', $translator_state[0]['value']);

                  try {
                    $save_result = $job_item->save();

                    if ($save_result === FALSE) {
                      $this->logError('Failed to save job item @id with updated translator_state data (job_part_id)', [
                        '@id' => $job_item->id(),
                      ]);

                      $job->addMessage('Failed to save job item data with file job part ID. File translation tracking may not work correctly.', [], 'warning');
                    }
                  }
                  catch (\Exception $e) {
                    $this->logError('Exception while saving job item @id with updated translator_state data (job_part_id): @error', [
                      '@id' => $job_item->id(),
                      '@error' => $e->getMessage(),
                    ]);

                    $job->addMessage('Exception while saving job item data with file job part ID: @error', [
                      '@error' => $e->getMessage(),
                    ], 'warning');
                  }

                  $this->logDebug('Updated translator_state for file @filename (FID: @fid) with job part ID: @job_part_id', [
                    '@filename' => $file->getFilename(),
                    '@fid' => $file->id(),
                    '@job_part_id' => $file_job_part_id,
                  ]);
                }
              }
              catch (\Exception $e) {
                $retry++;

                $this->logWarn('File upload failed for @filename (FID: @fid) after (RETRY: @retry): @error', [
                  '@filename' => $file->getFilename(),
                  '@fid' => $file->id(),
                  '@retry' => $retry,
                  '@error' => $e->getMessage(),
                ]);

                if (isset($file_remote_mapping)) {
                  $file_remote_mapping->delete();
                  unset($file_remote_mapping);
                }

                if ($retry < self::CHECK_JOB_MAX_RETRIES) {
                  sleep($retry);
                }
              }
            }

            if (!$file_success) {
              $this->logError('Failed to upload file @filename (FID: @fid) after @retries retries', [
                '@filename' => $file->getFilename(),
                '@fid' => $file->id(),
                '@retries' => $retry,
              ]);

              $job->addMessage('Failed to upload file @filename for translation after @retries retries', [
                '@filename' => $file->getFilename(),
                '@retries' => $retry,
              ], 'warning');
            }
          }
        }
        // Reset success flag in case XLIFF failed, to avoid issues with the final check.
        $success = FALSE;
      }

      if ($retry >= self::CHECK_JOB_MAX_RETRIES) {
        $this->logError('Failed to process job-item (LABEL: @label) and TMS project (UID: @projectId) after @retries retries', [
          '@label' => $job_item->label(),
          '@projectId' => $project_id,
          '@retries' => $retry,
        ]);

        $job->rejected('Failed to process job-item (LABEL: @label) and TMS project (UID: @projectId) after @retries retries', [
          '@label' => $job_item->label(),
          '@projectId' => $project_id,
          '@retries' => $retry,
        ], 'error');
      }
      else {
        if (!empty($job_part_uids)) {
          $this->assignMemsourceProviders($project_id, $job, $job_part_uids);
        }
      }
    }
    catch (TMGMTException $e) {
      $this->logError('Job failed for TMS project (UID: @projectId), memsourceActionId (UID: @actionId): @error', [
        '@projectId' => $project_id,
        '@actionId' => $this->getMemsourceActionId(),
        '@error' => $e->getMessage(),
      ]);

      $job->rejected('Job failed for TMS project (UID: @projectId), memsourceActionId (UID: @actionId): @error', [
        '@projectId' => $project_id,
        '@actionId' => $this->getMemsourceActionId(),
        '@error' => $e->getMessage(),
      ], 'error');
    }

    // Job submitted in requestTranslation().
    return $job;
  }

  /**
   * Get Memsource project ID if given job can be added to the project.
   *
   * @param \Drupal\tmgmt\JobItemInterface[] $job_items
   *   TMGMT job items.
   *
   * @return string|null
   *   Memsource project ID if found.
   */
  private function getMemsourceProjectIdByContent(array $job_items) {
    $memsource_project_ids = [];

    foreach ($job_items as $job_item) {
      $raw_items = \Drupal::entityQuery('tmgmt_job_item')
        ->condition('plugin', $job_item->getPlugin())
        ->condition('item_type', $job_item->getItemType())
        ->condition('item_id', $job_item->getItemId())
        ->condition('tjiid', $job_item->id(), '<>')
        ->sort('tjiid', 'DESC')
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($raw_items)) {
        /** @var \Drupal\tmgmt\JobItemInterface[] $items */
        $items = JobItem::loadMultiple($raw_items);
      }

      foreach (($items ?? []) as $item) {
        $raw_remotes = \Drupal::entityQuery('tmgmt_remote')
          ->condition('tjiid', $item->id())
          ->sort('trid', 'DESC')
          ->accessCheck(FALSE)
          ->execute();

        if (!empty($raw_remotes)) {
          /** @var \Drupal\tmgmt\RemoteMappingInterface[] $remotes */
          $remotes = RemoteMapping::loadMultiple($raw_remotes);
        }

        foreach (($remotes ?? []) as $remote) {
          $project_uid = $remote->getRemoteIdentifier2();
          if (!empty($project_uid)) {
            try {
              $memsource_project = $this->sendApiRequest('/api2/v1/projects/' . $project_uid);
            }
            catch (\Exception $e) {
              $this->logWarn('Unable to fetch remote project (UID: @project_id): @error. Processing next project...', [
                '@project_id' => $project_uid,
                '@error' => $e->getMessage(),
              ]);
              continue;
            }

            if (!in_array($memsource_project['status'], ['COMPLETED', 'CANCELLED']) &&
              in_array($job_item->getJob()->getRemoteTargetLanguage(), $memsource_project['targetLangs'])) {
              $memsource_project_ids[] = $project_uid;
            }
          }
        }
      }
    }

    $this->logDebug('Found TMS projects with count: @count and ids=[@ids]', [
      '@count' => count($memsource_project_ids),
      '@ids' => implode(', ', $memsource_project_ids),
    ]);

    if (count(array_unique($memsource_project_ids)) === 1) {
      return reset($memsource_project_ids);
    }

    return NULL;
  }

  /**
   * Get Memsource project ID if given job can be added to the project.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   TMGMT job item.
   *
   * @return string|null
   *   Memsource project ID if found.
   */
  private function getMemsourceProjectIdByBatchId(JobItemInterface $job_item) {
    $job = $job_item->getJob();
    $batch_id = $job->getSetting('batch_id');

    if (!empty($batch_id)) {
      $rawJobs = \Drupal::entityQuery('tmgmt_job')
        ->condition('settings', $batch_id, 'CONTAINS')
        ->condition('tjid', $job->id(), '<>')
        ->sort('tjid', 'DESC')
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($rawJobs)) {
        /** @var \Drupal\tmgmt\JobInterface[] $jobs */
        $jobs = Job::loadMultiple($rawJobs);
      }

      foreach (($jobs ?? []) as $previous_job) {
        if ($previous_job->getSetting('batch_id') === $batch_id) {
          $previous_job_items = $previous_job->getItems([
            'plugin' => $job_item->getPlugin(),
            'item_type' => $job_item->getItemType(),
          ]);

          foreach ($previous_job_items as $previous_job_item) {
            /** @var \Drupal\tmgmt\RemoteMappingInterface $remote */
            foreach ($previous_job_item->getRemoteMappings() as $remote) {
              if (!empty($remote->getRemoteIdentifier2())) {
                try {
                  $memsource_project = $this->sendApiRequest('/api2/v1/projects/' . $remote->getRemoteIdentifier2());
                }
                catch (\Exception $e) {
                  $this->logWarn('Unable to fetch remote project: ' . $e->getMessage());
                  continue;
                }

                if (isset($memsource_project['uid'])) {
                  return $remote->getRemoteIdentifier2();
                }
              }
            }
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Performs a login to Memsource Cloud.
   */
  public function loginToMemsource() {
    $params = [
      'userName' => $this->translator->getSetting('memsource_user_name'),
      'password' => $this->decodePassword($this->translator->getSetting('memsource_password')),
    ];

    try {
      $result = $this->request('/api2/v1/auth/login', 'POST', $params);

      if (isset($result['token']) && $result['token']) {
        // Store the token.
        $this->storeToken($result['token']);

        return TRUE;
      }
    }
    catch (TMGMTException $ex) {
      $this->logError('Unable to log in to Phrase TMS API: ' . $ex->getMessage());
    }

    return FALSE;
  }

  /**
   * Encode Memsource password.
   *
   * @param string $password
   *   Password to be encoded.
   *
   * @return string
   *   Encoded password.
   */
  public function encodePassword($password) {
    if (is_string($password) && (substr($password, 0, self::PASSWORD_V2_PREFIX_LENGTH) !== self::PASSWORD_V2_PREFIX)) {
      $password = self::PASSWORD_V2_PREFIX . bin2hex($password);
    }

    return $password;
  }

  /**
   * Decode Memsource password.
   *
   * @param string $password
   *   Encoded or plaintext password.
   *
   * @return string
   *   Decoded password.
   */
  public function decodePassword($password) {
    if (is_string($password) && substr($password, 0, self::PASSWORD_V2_PREFIX_LENGTH) === self::PASSWORD_V2_PREFIX) {
      $password = hex2bin(substr($password, self::PASSWORD_V2_PREFIX_LENGTH));
    }

    return $password;
  }

  /**
   * Stores a Memsource API token.
   *
   * @param string $token
   *   Token.
   */
  public function storeToken($token) {
    $config = \Drupal::configFactory()->getEditable('tmgmt_memsource.settings');
    $config->set('memsource_token', $token)->save();
  }

  /**
   * Returns a Memsource API token.
   *
   * @return string
   *   Token.
   */
  public function getToken() {
    return \Drupal::configFactory()
      ->get('tmgmt_memsource.settings')
      ->get('memsource_token');
  }

  /**
   * Sends a request to the Memsource API and refreshes the token if necessary.
   *
   * @param string $path
   *   API path.
   * @param string $method
   *   (Optional) HTTP method.
   * @param array $params
   *   (Optional) API params.
   * @param bool $download
   *   (Optional) If true, return the response body as a downloaded content.
   * @param bool $code
   *   (Optional) If true, return only the response HTTP status code.
   * @param string $body
   *   (Optional) An optional request body.
   * @param int $timeout
   *   (Optional) Custom timeout in seconds for this request.
   *
   * @return array|int|null
   *   Result of the API request.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function sendApiRequest($path, $method = 'GET', array $params = [], $download = FALSE, $code = FALSE, $body = NULL, $timeout = NULL) {
    $result = NULL;
    $params['token'] = $this->getToken();

    try {
      $result = $this->request($path, $method, $params, $download, $code, $body, $timeout);
    }
    catch (TMGMTException $ex) {
      if ($ex->getCode() == 401) {
        // Token is invalid, try to re-login.
        $this->loginToMemsource();
        $params['token'] = $this->getToken();
        $result = $this->request($path, $method, $params, $download, $code, $body, $timeout);
      }
      else {
        throw $ex;
      }
    }
    return $result;
  }

  /**
   * Does a request to Memsource API.
   *
   * @param string $path
   *   Resource path, for example '/api2/v1/auth/login'.
   * @param string $method
   *   (Optional) HTTP method (GET, POST...). By default uses GET method.
   * @param array $params
   *   (Optional) Form parameters to send to Memsource API.
   * @param bool $download
   *   (Optional) If we expect resource to be downloaded. FALSE by default.
   * @param bool $code
   *   (Optional) If we want to return the status code of the call. FALSE by
   *   default.
   * @param string|null $body
   *   (Optional) Body of the POST request. NULL by
   *   default.
   * @param int $timeout
   *   (Optional) Custom timeout in seconds for this request.
   *
   * @return array|int
   *   Response array or status code.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function request($path, $method = 'GET', array $params = [], $download = FALSE, $code = FALSE, $body = NULL, $timeout = NULL) {
    $options = ['headers' => []];

    if (!$this->translator) {
      throw new TMGMTException('There is no Translator entity. Access to the Phrase TMS API is not possible.');
    }

    $service_url = $this->translator->getSetting('service_url');

    if (!$service_url) {
      return [];
    }

    $url = $this->updateServiceUrl($service_url) . $path;

    if (isset($params['token'])) {
      $options['headers'] = ['Authorization' => 'ApiToken ' . $params['token']];
      unset($params['token']);
    }

    if ($body) {
      $options['body'] = $body;
      if (!empty($params)) {
        $options['headers'] += $params;
      }
    }
    elseif ($method === 'GET') {
      $options['query'] = $params;
    }
    else {
      $options['json'] = $params;
    }

    // Set timeout options based on the request type or custom timeout.
    $is_target_file_download = ($download && strpos($path, '/targetFile') !== FALSE);

    if ($timeout !== NULL) {
      // Use the custom timeout if provided.
      $options['timeout'] = $timeout;
      $options['connect_timeout'] = min(60, $timeout / 3); // Set connect timeout to 1/3 of total timeout, max 60 seconds

      $this->logDebug('Using custom timeout (@timeout seconds, connect: @connect) for request: @path', [
        '@timeout' => $options['timeout'],
        '@connect' => $options['connect_timeout'],
        '@path' => $path,
      ]);
    }
    elseif ($is_target_file_download) {
      // 180 seconds (3 minutes) should be enough for most files.
      $options['timeout'] = 180;
      $options['connect_timeout'] = 60; // Increase connect timeout too.
      $this->logDebug('Using extended timeout (@timeout seconds, connect: @connect) for /targetFile download: @path', [
        '@timeout' => $options['timeout'],
        '@connect' => $options['connect_timeout'],
        '@path' => $path,
      ]);
    } else {
      // For regular API requests, use standard timeouts.
      $options['timeout'] = 30; // Default timeout.
      $options['connect_timeout'] = 10; // Default connect timeout.
    }

    try {
      $response = $this->client->request($method, $url, $options);
    }
    catch (RequestException $e) {
      if (!$e->hasResponse()) {
        if ($code) {
          return $e->getCode();
        }

        throw new TMGMTException('Unable to connect to Phrase TMS API [memsource-action-id=@actionId] due to following error: @error',
            ['@error' => $e->getMessage(), '@actionId' => $this->getMemsourceActionId()], $e->getCode());
      }

      $response = $e->getResponse();

      if ($code) {
        return $response->getStatusCode();
      }

      throw new TMGMTException('Unable to connect to Phrase TMS API [memsource-action-id=@actionId] due to following error: @error',
          ['@error' => $response->getReasonPhrase(), '@actionId' => $this->getMemsourceActionId()], $response->getStatusCode());
    }

    $received_data = $response->getBody()->getContents();

    if ($code) {
      return $response->getStatusCode();
    }

    if (!in_array($response->getStatusCode(), [200, 201], TRUE)) {
      throw new TMGMTException(
        'Unable to connect to the Phrase TMS API (memsource-action-id: @actionId), code: @code due to following error: @error at @url',
        [
          '@actionId' => $this->getMemsourceActionId(),
          '@code' => $response->getStatusCode(),
          '@error' => $response->getReasonPhrase(),
          '@url' => $url,
        ]
      );
    }

    if ($download) {
      return $received_data;
    }

    return $this->decodeJson($received_data);
  }

  /**
   * Modify service URL due to the Memsource API migration (if necessary).
   *
   * 'https://qa.memsource.com/web/api' -> 'https://qa.memsource.com/web'.
   *
   * @param string $url
   *   Service URL.
   *
   * @return string
   *   Updated Memsource URL.
   */
  private function updateServiceUrl($url) {
    $pos = strpos($url, '/web/');

    if ($pos !== FALSE) {
      $url = substr($url, 0, $pos + 4);
      // $this->translator->setSetting('service_url', $url);.
    }

    return $url;
  }

  /**
   * Creates new translation project at Memsource Cloud.
   *
   * Project has all languages available by default in order to load
   * all Translation Memories and Term Bases set in the template.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   *
   * @return string
   *   Memsource project uid.
   */
  public function newTranslationProject(JobInterface $job) {
    $due_date = $job->getSetting('due_date');
    $template_id = $job->getSetting('project_template');
    $source_language = $job->getRemoteSourceLanguage();
    $name_suffix = '';

    if ($job->getSetting('group_jobs')) {
      $remote_languages_mappings = $job->getTranslator()->getRemoteLanguagesMappings();
      if (($source_language_key = array_search($source_language, $remote_languages_mappings)) !== FALSE) {
        unset($remote_languages_mappings[$source_language_key]);
      }
      $target_languages = array_values($remote_languages_mappings);
    }
    else {
      $target_languages = [$job->getRemoteTargetLanguage()];
      $target_language_name = $job->getRemoteTargetLanguage();
      $config = \Drupal::configFactory()->get("language.entity.$target_language_name");
      if ($config && $config->get('label')) {
        $target_language_name = $config->get('label');
      }
      $name_suffix = " ($target_language_name)";
    }

    $params = [
      'name' => ($job->label() ?: 'Drupal TMGMT project ' . $job->id()) . $name_suffix,
      'sourceLang' => $source_language,
      'targetLangs' => $target_languages,
    ];

    if (strlen($due_date) > 0) {
      $params['dateDue'] = $this->convertDateToEod($due_date);
    }

    if ($template_id === '0' || $template_id === NULL) {
      $result = $this->sendApiRequest('/api2/v1/projects', 'POST', $params);
    }
    else {
      $result = $this->sendApiRequest("/api2/v2/projects/applyTemplate/$template_id", 'POST', $params);
    }

    return $result['uid'];
  }

  /**
   * Assign providers defined in project template.
   *
   * @param string $project_id
   *   Project UID.
   * @param \Drupal\tmgmt\JobInterface $job
   *   The job.
   * @param string[] $job_part_uids
   *   List of TMS job parts.
   */
  private function assignMemsourceProviders($project_id, JobInterface $job, array $job_part_uids) {
    $template_id = $job->getSetting('project_template');

    if ($template_id !== NULL && $template_id !== '0') {
      $this->checkJobsCreated($project_id, $job_part_uids);
      try {
        $this->sendApiRequest(
          "/api2/v1/projects/$project_id/applyTemplate/$template_id/assignProviders",
          'POST'
        );
      }
      catch (\Exception $e) {
        $this->logError('Unable to assign providers. Phrase TMS project (UID: @projectId), template (UID: @templateId). Error: @error', [
          '@projectId' => $project_id,
          '@templateId' => $template_id,
          '@error' => $e->getMessage(),
        ]);

        $job->addMessage('Unable to assign providers. Phrase TMS project (UID: @projectId), template (UID: @templateId)', [
          '@projectId' => $project_id,
          '@templateId' => $template_id,
        ], 'error');
      }
    }
  }

  /**
   * Check that all the jobs were created in Phrase TMS.
   *
   * If jobs were not created yet, sleep for a while and try again
   * until CHECK_JOB_MAX_RETRIES is reached.
   *
   * @param string $project_id
   *   Project UID.
   * @param string[] $job_part_uids
   *   Job part UID.
   */
  private function checkJobsCreated($project_id, array $job_part_uids) {
    foreach ($job_part_uids as $job_part_uid) {
      for ($retries = 0; TRUE; $retries++) {
        try {
          $response = $this->sendApiRequest("/api2/v1/projects/$project_id/jobs/$job_part_uid");
          // Use ensureDecodedResponse to validate the response.
          $response = $this->ensureDecodedResponse($response);
          if (isset($response['importStatus']['status']) && in_array($response['importStatus']['status'], ['ERROR', 'OK'], TRUE)) {
            break;
          }
        }
        catch (\Exception $e) {
          $this->logDebug("Exception while checking job import status: @error", [
            '@error' => $e->getMessage(),
          ]);
        }
        $this->logDebug("Job uid=$job_part_uid import not finished, going to sleep for $retries seconds");
        sleep($retries);
        if ($retries > self::CHECK_JOB_MAX_RETRIES) {
          $this->logWarn("Job uid=$job_part_uid not found, max retries reached");
          break;
        }
      }
    }
  }

  /**
   * Send the files to Memsource Cloud.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The Job.
   * @param int $project_id
   *   Memsource project id.
   * @param string $due_date
   *   The date by when the translation is required.
   * @param string $item_type
   *   The type of item being translated.
   * @param string $item_id
   *   The ID of the item being translated.
   *
   * @return string
   *   Memsource jobPartId.
   */
  private function sendFiles(JobItemInterface $job_item, $project_id, $due_date, $item_type, $item_id) {
    /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
    $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');

    $job_id = $job_item->getJob()->id();
    $job_item_id = $job_item->id();
    $job_item_label = $job_item->label();
    $source_language = $job_item->getJob()->getSourceLangcode();
    $target_language = $job_item->getJob()->getRemoteTargetLanguage();
    $conditions = ['tjiid' => ['value' => $job_item_id]];
    $xliff = $xliff_converter->export($job_item->getJob(), $conditions);
    $name = "JobID_{$job_id}_{$job_item_label}_{$source_language}_{$target_language}";

    $job_item->addMessage('Created memsource-action-id=' . $this->getMemsourceActionId(), [], 'debug');

    return $this->createJob($project_id, $target_language, $due_date, $xliff, $name, $item_type, $item_id);
  }

  /**
   * Create a job in Memsource.
   *
   * @param string $project_id
   *   Project ID.
   * @param string $target_language
   *   Target language code.
   * @param string $due_date
   *   Job due date.
   * @param string $xliff
   *   XLIFF file.
   * @param string $name
   *   File name.
   * @param string $item_type
   *   The type of item being translated.
   * @param string $item_id
   *   The ID of the item being translated.
   *
   * @return string
   *   Job UID.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function createJob($project_id, $target_language, $due_date, $xliff, $name, $item_type, $item_id) {
    $params = [
      'targetLangs' => [$target_language],
      'useProjectFileImportSettings' => 'true',
      'sourceData' => [
        'clientType' => 'DRUPAL',
        'clientVersion' => $this->getMemsourceModuleVersion(),
        'hostVersion' => \Drupal::VERSION,
      ],
      'remotePreview' => [
        'connectorToken' => $this->translator->getSetting('memsource_connector_token'),
        'remoteFolder' => $item_type,
        'remoteFileName' => $item_id,
      ],
    ];

    if (strlen($due_date) > 0) {
      $params['due'] = $this->convertDateToEod($due_date);
    }

    // Use Component\Serialization\Json for consistent JSON encoding.
    $headers = [
      'Content-Disposition' => "filename*=UTF-8''" . urlencode($name) . ".xliff",
      'Memsource' => Json::encode($params),
      'memsource-action-id' => $this->getMemsourceActionId(),
    ];

    $result = $this->sendApiRequest(
      "/api2/v1/projects/$project_id/jobs",
      'POST',
      $headers,
      FALSE,
      FALSE,
      $xliff
    );

    return $this->getUidOfLatestJob($result['jobs']);
  }

  /**
   * Convert local date to EOD (23:59:59) datetime in UTC timezone.
   *
   * @param string $date
   *   Date in format  YYYY-MM-DD.
   *
   * @return string
   *   Datetime in format YYYY-MM-DDTHH:mm:ssZ.
   */
  private function convertDateToEod($date) {
    $dateTime = new \DateTime("$date 23:59:59");
    $dateTime->setTimezone(new \DateTimeZone('UTC'));

    return $dateTime->format('Y-m-d\TH:i:s\Z');
  }

  /**
   * Get UID of the latest returned job (according to workflow steps).
   *
   * @param array $jobs
   *   Jobs.
   *
   * @return string
   *   Job UID.
   */
  private function getUidOfLatestJob(array $jobs) {
    $latestJob = current($jobs);

    if (count($jobs) > 1) {
      $maxWorkflowLevel = max(array_column($jobs, 'workflowLevel'));

      $filteredJobs = array_filter($jobs, static function ($job) use ($maxWorkflowLevel) {
        return $job['workflowLevel'] == $maxWorkflowLevel;
      });

      if (count($filteredJobs) > 0) {
        $latestJob = current($filteredJobs);
      }
    }

    return $latestJob['uid'];
  }

  /**
   * Fetches translations for job items of a given job.
   *
   * @param \Drupal\tmgmt\JobInterface $job
   *   A job containing job items that translations will be fetched for.
   *
   * @return array
   *   Array containing a containing the number of items translated and the
   *   number that has not been translated yet.
   */
  public function fetchTranslatedFiles(JobInterface $job) {
    $this->setTranslator($job->getTranslator());
    $translated = 0;
    $errors = [];

    try {
      /** @var \Drupal\tmgmt\JobItemInterface $job_item */
      foreach ($job->getItems() as $job_item) {
        // Use the centralized method to process this job item's mappings.
        // Pass TRUE to update TMS status if configured.
        $processed = $this->processJobItemMappings($job_item, $errors, TRUE);
        $translated += $processed;
      }
    }
    catch (TMGMTException $e) {
      $this->logError('Could not pull translation resources: @error', ['@error' => $e->getMessage()]);
    }

    $result = [
      'translated' => $translated,
      'untranslated' => count($job->getItems()) - $translated,
      'errors' => $errors,
    ];

    // Check the final state of all job items.
    foreach ($job->getItems() as $job_item) {
      $item_id = $job_item->id();
      $item_state = $job_item->getState();
      $item_state_name = '';

      // Convert state number to name for better logging.
      switch ($item_state) {
        case JobItemInterface::STATE_ACTIVE:
          $item_state_name = 'ACTIVE (1)';
          break;
        case JobItemInterface::STATE_REVIEW:
          $item_state_name = 'REVIEW (2)';
          break;
        case JobItemInterface::STATE_ACCEPTED:
          $item_state_name = 'ACCEPTED (3)';
          break;
        case JobItemInterface::STATE_ABORTED:
          $item_state_name = 'ABORTED (4)';
          break;
        default:
          $item_state_name = 'UNKNOWN (' . $item_state . ')';
      }

      $this->logDebug('Job item @id final state: @state', [
        '@id' => $item_id,
        '@state' => $item_state_name,
      ]);

      // If the item should be translated but is still in ACTIVE state, try to force it to REVIEW.
      if ($item_state == JobItemInterface::STATE_ACTIVE) {
        $this->logDebug('Job item @id is still in ACTIVE state, checking if all mappings are complete...', [
          '@id' => $item_id,
        ]);

        // Use our centralized method to check if all mappings are complete.
        $all_completed = $this->checkAllMappingsComplete($job_item);

        // If the state was successfully changed to REVIEW, increment the translated count.
        if ($all_completed && in_array($job_item->getState(), [JobItemInterface::STATE_REVIEW, JobItemInterface::STATE_ACCEPTED], TRUE)) {
          $translated++;
          $result['translated'] = $translated;
          $result['untranslated'] = count($job->getItems()) - $translated;

          $this->logDebug('Successfully updated job item @id to REVIEW state and incremented translated count.', [
            '@id' => $item_id,
          ]);
        }
      }
    }

    return $result;
  }

  /**
   * Retrieve all the updates for all the job items in a translator.
   */
  public function pullRemoteTranslation(JobItemInterface $job_item) {
    $job = $job_item->getJob();
    $this->setTranslator($job->getTranslator());

    // Use the centralized method to process this job item's mappings.
    // Don't update TMS status for individual pulls (FALSE)
    $errors = [];
    $this->processJobItemMappings($job_item, $errors, FALSE);

    // Check if the job item is now in review state.
    if (in_array($job_item->getState(), [JobItemInterface::STATE_REVIEW, JobItemInterface::STATE_ACCEPTED], TRUE)) {
      return 1;
    }

    return 0;
  }

  /**
   * Retrieve the data of a file in a state.
   *
   * @param \Drupal\tmgmt\Entity\RemoteMapping $mapping
   *   The remote mapping object.
   * @param string $tms_job_status
   *   The state of the file.
   * @param int $project_id
   *   The project ID.
   * @param string $job_part_id
   *   The file ID.
   * @param string|null $notes
   *   Optional notes from the callback (unused, kept for API compatibility).
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  public function addFileDataToJob(RemoteMapping $mapping, $tms_job_status, $project_id, $job_part_id, $notes = NULL) {
    // $notes parameter is unused but kept for API compatibility.
    unset($notes); // Explicitly unset to avoid IDE warnings.
    $job = $mapping->getJob();
    $job_item = $mapping->getJobItem();

    // Get the remote data directly from the field.
    $remote_data_field = $mapping->get('remote_data')->getValue();
    $remote_data = [];

    // Check if we have any data.
    if (!empty($remote_data_field) && is_array($remote_data_field)) {
      // The field value is an array of values, we need the first one.
      $remote_data = reset($remote_data_field);
    }
    if (empty($remote_data)) {
      $remote_data = [];
      $this->logWarn('Remote data is empty for mapping @id.', ['@id' => $mapping->id()]);
    }

    // Log the processed remote_data after extraction.
    $this->logDebug('Processed remote_data via getRemoteData() for mapping @mapping_id for job part @job_part_id: @data', [
      '@mapping_id' => $mapping->id(),
      '@job_part_id' => $job_part_id,
      '@data' => var_export($remote_data, TRUE),
    ]);

    // Enhanced file mapping detection.
    // First check the remote_data for isFile flag.
    $is_file = FALSE;

    // Log the raw isFile value for debugging.
    $this->logDebug('Raw isFile value for mapping @mapping_id: @raw_value (type: @type)', [
      '@mapping_id' => $mapping->id(),
      '@raw_value' => isset($remote_data['isFile']) ? var_export($remote_data['isFile'], TRUE) : 'NOT SET',
      '@type' => isset($remote_data['isFile']) ? gettype($remote_data['isFile']) : 'NULL',
    ]);

    // Check if isFile is explicitly set to TRUE (boolean)
    if (isset($remote_data['isFile']) && $remote_data['isFile'] === TRUE) {
      $is_file = TRUE;
      $this->logDebug('isFile check passed: exact TRUE match (boolean)');
    }
    // Also check for string 'TRUE' or '1' or integer 1 (different serialization formats)
    elseif (isset($remote_data['isFile']) &&
           (($remote_data['isFile'] === 'TRUE') ||
            ($remote_data['isFile'] === '1') ||
            ($remote_data['isFile'] === 1) ||
            ($remote_data['isFile'] === 'true'))) {
      $is_file = TRUE;
      $this->logDebug('isFile check passed: alternative format match (@format)', [
        '@format' => is_string($remote_data['isFile']) ? 'string: ' . $remote_data['isFile'] : 'integer: ' . $remote_data['isFile'],
      ]);
    }

    // If isFile is not set, check for other indicators that this might be a file mapping.
    if (!$is_file) {
      // Check if source_fid and datakey are set, which would indicate a file mapping.
      if (!empty($remote_data['source_fid']) && !empty($remote_data['datakey'])) {
        $is_file = TRUE;
        $this->logWarn('Mapping for job part @job_part_id has source_fid and datakey but isFile is not set. Treating as file mapping.', [
          '@job_part_id' => $job_part_id,
        ]);

        // Update the isFile flag using standard Entity API.
        try {
          $mapping->addRemoteData('isFile', TRUE);
          $mapping->save();

          $this->logDebug('Successfully updated isFile to TRUE using Entity API for mapping @map_id', [
            '@map_id' => $mapping->id(),
          ]);
        } catch (\Exception $e) {
          $this->logError('Exception during isFile update for mapping @map_id: @error', [
            '@map_id' => $mapping->id(),
            '@error' => $e->getMessage(),
          ]);
        }
      }

      // Check if this job part ID appears in the job item's translator_state as a file.
      $translator_state = $job_item->get('translator_state')->getValue();
      if (!empty($translator_state[0]['value']['tmgmt_memsource_files'])) {
        $files_data = $translator_state[0]['value']['tmgmt_memsource_files'];

        // Check if we have any files in the translator state.
        if (count($files_data) > 0) {
          // First, check if we can find a direct match by job_part_id.
          $found_match = FALSE;
          foreach ($files_data as $file_info) {
            if (isset($file_info['job_part_id']) && $file_info['job_part_id'] === $job_part_id) {
              $is_file = TRUE;
              $found_match = TRUE;
              $this->logWarn('Found job part @job_part_id in translator_state files data. Treating as file mapping.', [
                '@job_part_id' => $job_part_id,
              ]);

              // Update the mapping using standard Entity API.
              try {
                $mapping->addRemoteData('isFile', TRUE);
                $mapping->addRemoteData('source_fid', $file_info['file_id']);
                $mapping->addRemoteData('datakey', $file_info['datakey']);
                $mapping->save();

                $this->logDebug('Successfully updated mapping data using Entity API for mapping @map_id', [
                  '@map_id' => $mapping->id(),
                ]);
              } catch (\Exception $e) {
                $this->logError('Exception during mapping update for mapping @map_id: @error', [
                  '@map_id' => $mapping->id(),
                  '@error' => $e->getMessage(),
                ]);
              }
              break;
            }
          }

          // If we didn't find a direct match but we have only one file in translator_state,
          // assume this is the file mapping (best effort recovery).
          if (!$found_match && !$is_file && count($files_data) === 1) {
            $file_info = reset($files_data);
            $is_file = TRUE;
            $this->logWarn('No direct match found, but have only one file in translator_state. Assuming job part @job_part_id is for file @filename.', [
              '@job_part_id' => $job_part_id,
              '@filename' => $file_info['filename'] ?? 'unknown',
            ]);

            // Update the mapping using standard Entity API.
            try {
              $mapping->addRemoteData('isFile', TRUE);
              $mapping->addRemoteData('source_fid', $file_info['file_id']);
              $mapping->addRemoteData('datakey', $file_info['datakey']);
              $mapping->save();

              $this->logDebug('Successfully updated mapping data using Entity API for mapping @map_id (best effort recovery)', [
                '@map_id' => $mapping->id(),
              ]);
            } catch (\Exception $e) {
              $this->logError('Exception during mapping update for mapping @map_id: @error', [
                '@map_id' => $mapping->id(),
                '@error' => $e->getMessage(),
              ]);
            }
          }
        }
      }
    }

    $this->logDebug('Processing job part @job_part_id for job item @job_item_id. Mapping type: @type (isFile check result: @is_file_result)', [
      '@job_part_id' => $job_part_id,
      '@job_item_id' => $job_item->id(),
      '@type' => $is_file ? 'FILE' : 'XLIFF',
      '@is_file_result' => $is_file ? 'TRUE' : 'FALSE',
    ]);

    if ($is_file) {
      $this->logDebug('Processing file translation for job item @tjiid, project @proj, part @part.', [
        '@tjiid' => $job_item->id(),
        '@proj' => $project_id,
        '@part' => $job_part_id,
      ]);

      // Get file information from the mapping.
      $source_fid_str = $remote_data['source_fid'] ?? NULL; // Might be string now.
      $datakey = $remote_data['datakey'] ?? NULL;

      if (!$source_fid_str || !$datakey) {
        throw new TMGMTException('Missing source file information in remote mapping.');
      }

      $source_fid = (int)$source_fid_str; // Cast back to int for loading.
      $this->logDebug('Attempting to load source file with FID: @fid (from string "@fid_str")', [
          '@fid' => $source_fid,
          '@fid_str' => $source_fid_str,
      ]);

      try {
        $source_file = File::load($source_fid);
        if (!$source_file) {
          $this->logError('Source file with ID @fid not found when processing job part @job_part_id', [
            '@fid' => $source_fid,
            '@job_part_id' => $job_part_id,
          ]);

          $job->addMessage('Source file with ID @fid not found when processing translation. The file may have been deleted.', [
            '@fid' => $source_fid,
          ], 'error');

          throw new TMGMTException('Source file with ID @fid not found.', ['@fid' => $source_fid]);
        }
      }
      catch (\Exception $e) {
        if (!($e instanceof TMGMTException)) {
          $this->logError('Exception while loading source file with ID @fid: @error', [
            '@fid' => $source_fid,
            '@error' => $e->getMessage(),
          ]);

          $job->addMessage('Exception while loading source file with ID @fid: @error', [
            '@fid' => $source_fid,
            '@error' => $e->getMessage(),
          ], 'error');
        }

        throw $e;
      }

      // Download the translated file content.
      try {
        // Set a longer timeout for file downloads.
        $timeout = 180; // 3 minutes.
        $translated_content = $this->sendApiRequest("/api2/v1/projects/$project_id/jobs/$job_part_id/targetFile", 'GET', [], TRUE, FALSE, NULL, $timeout);

        if (empty($translated_content)) {
          throw new TMGMTException('Empty translated file content received from Phrase TMS.');
        }

        $this->logDebug('Downloaded translated file content for job part @job_part_id (size: @size bytes)', [
          '@job_part_id' => $job_part_id,
          '@size' => is_string($translated_content) ? strlen($translated_content) : 'N/A (not a string)',
        ]);
      }
      catch (TMGMTException $e) {
        $this->logError('Failed to download translated file content: @error', [
          '@error' => $e->getMessage(),
        ]);
        throw $e;
      }

      // First, check if we already have a translated file for this entity field.
      // This requires looking at the entity data to see if there's already a translated file.
      $existing_translated_file = NULL;
      $target_fid_from_mapping = NULL;

      // Check if we have a target_fid in the mapping.
      if (!empty($remote_data['target_fid'])) {
        $target_fid_from_mapping = $remote_data['target_fid'];
        $this->logDebug('Found target_fid @fid in mapping @map_id', [
          '@fid' => $target_fid_from_mapping,
          '@map_id' => $mapping->id(),
        ]);
      }

      // Get the entity and field information to check for existing translations.
      try {
        // Get the job item data.
        $job_item_data = $job_item->getData();

        // Navigate to the correct position in the data array using the datakey.
        // First, properly parse the datakey format (e.g., "field_msoffice][1][target_id").
        $this->logDebug('Parsing datakey: @datakey', [
          '@datakey' => $datakey,
        ]);

        // Handle the datakey format properly.
        if (strpos($datakey, '][') !== FALSE) {
          // Format like "field_msoffice][1][target_id".
          $keys = [];
          $parts = explode('][', $datakey);

          // Handle the first part which might not have a leading [.
          $first_part = $parts[0];
          $keys[] = $first_part;

          // Handle the remaining parts.
          for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            // Remove trailing ] from the last part if present.
            if ($i == count($parts) - 1 && substr($part, -1) == ']') {
              $part = substr($part, 0, -1);
            }
            $keys[] = $part;
          }
        } else {
          // Simple key without brackets.
          $keys = [$datakey];
        }

        $this->logDebug('Parsed keys from datakey: @keys', [
          '@keys' => implode(', ', $keys),
        ]);

        // Now navigate through the data structure.
        $data_ref = &$job_item_data;
        $path_so_far = '';

        foreach ($keys as $key) {
          $path_so_far .= ($path_so_far ? '>' : '') . $key;

          if (!isset($data_ref[$key])) {
            $this->logWarn('Could not find key @key in job item data structure (path: @path) when checking for existing translations', [
              '@key' => $key,
              '@path' => $path_so_far,
            ]);
            break;
          }
          $data_ref = &$data_ref[$key];
        }

        // If we have a #translation value, it might be an existing translated file.
        if (isset($data_ref['#translation']) && is_numeric($data_ref['#translation'])) {
          $existing_translated_fid = (int) $data_ref['#translation'];
          $existing_translated_file = File::load($existing_translated_fid);

          if ($existing_translated_file) {
            $this->logDebug('Found existing translated file (FID: @fid) in entity data for field @datakey', [
              '@fid' => $existing_translated_file->id(),
              '@datakey' => $datakey,
            ]);
          }
        }
      }
      catch (\Exception $e) {
        $this->logWarn('Exception while checking for existing translated file: @error', [
          '@error' => $e->getMessage(),
        ]);
        // Continue with normal processing.
      }

      // If we found an existing translated file, use it.
      if ($existing_translated_file) {
        $target_file = $existing_translated_file;
        $this->logDebug('Using existing translated file (FID: @fid) from entity data', [
          '@fid' => $target_file->id(),
        ]);

        // Update the existing translated file.
        $file_uri = '';

        // Try to get the file URI using different methods.
        try {
          if (isset($target_file->uri)) {
            $file_uri = $target_file->uri->value;
          }
          else {
            // Last resort: try to get it from the database.
            $file_data = \Drupal::database()->select('file_managed', 'fm')
              ->fields('fm', ['uri'])
              ->condition('fid', $target_file->id())
              ->execute()
              ->fetchField();

            if ($file_data) {
              $file_uri = $file_data;
            }
          }
        }
        catch (\Exception $e) {
          $this->logWarn('Exception while getting file URI: @error', [
            '@error' => $e->getMessage(),
          ]);
        }

        if (empty($file_uri)) {
          throw new TMGMTException('Could not determine file URI for file with ID @fid', [
            '@fid' => $target_file->id(),
          ]);
        }

        try {
          // First, make sure the directory exists.
          $directory = dirname($file_uri);
          \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

          // Write directly to the destination file.
          $bytes_written = @file_put_contents($file_uri, $translated_content);
          if ($bytes_written === FALSE) {
            $error_message = error_get_last() ? error_get_last()['message'] : 'Unknown error';
            $this->logError('Failed to write translated content to file @uri: @error', [
              '@uri' => $file_uri,
              '@error' => $error_message,
            ]);

            // Add a job message for user visibility.
            $job->addMessage('Failed to write translated content to file @filename: @error', [
              '@filename' => basename($file_uri),
              '@error' => $error_message,
            ], 'error');

            throw new TMGMTException('Could not write translated content to file: @uri. Error: @error', [
              '@uri' => $file_uri,
              '@error' => $error_message,
            ]);
          }

          // Verify the file was written correctly.
          if (!file_exists($file_uri)) {
            $this->logError('File @uri does not exist after writing', [
              '@uri' => $file_uri,
            ]);

            $job->addMessage('File @filename does not exist after writing', [
              '@filename' => basename($file_uri),
            ], 'error');

            throw new TMGMTException('File @uri does not exist after writing', [
              '@uri' => $file_uri,
            ]);
          }

          // Verify the file size.
          $expected_size = strlen($translated_content);
          $actual_size = filesize($file_uri);
          if ($actual_size !== $expected_size) {
            $this->logWarn('File size mismatch after writing to @uri. Expected: @expected bytes, Actual: @actual bytes', [
              '@uri' => $file_uri,
              '@expected' => $expected_size,
              '@actual' => $actual_size,
            ]);
          }

          $this->logDebug('Successfully wrote translated content directly to file: @uri', [
            '@uri' => $file_uri,
          ]);

          try {
            $file_id = $target_file->id(); // Store the ID before reloading.
            $target_file = File::load($file_id); // Reload to ensure we have the latest version.
            if (!$target_file) {
              $this->logError('Failed to reload file entity with ID @fid after writing content', [
                '@fid' => $file_id,
              ]);

              $job->addMessage('Failed to reload file entity after writing content', [], 'error');
              throw new TMGMTException('Failed to reload file entity after writing content');
            }

            $save_result = $target_file->save(); // This will update the changed time automatically.
            if ($save_result === FALSE) {
              $this->logError('Failed to save updated file entity with ID @fid', [
                '@fid' => $target_file->id(),
              ]);

              $job->addMessage('Failed to save updated file entity @filename', [
                '@filename' => $target_file->getFilename(),
              ], 'error');

              throw new TMGMTException('Failed to save updated file entity');
            }
          }
          catch (\Exception $e) {
            if (!($e instanceof TMGMTException)) {
              $this->logError('Exception while saving file entity: @error', [
                '@error' => $e->getMessage(),
              ]);

              $job->addMessage('Exception while saving file entity: @error', [
                '@error' => $e->getMessage(),
              ], 'error');

              throw new TMGMTException('Exception while saving file entity: ' . $e->getMessage());
            }
            throw $e;
          }

          $this->logDebug('Successfully updated existing translated file @uri (FID: @fid)', [
            '@uri' => $file_uri,
            '@fid' => $target_file->id(),
          ]);
        }
        catch (\Exception $e) {
          $this->logError('Exception while updating file content: @error', [
            '@error' => $e->getMessage(),
          ]);
          throw new TMGMTException('Failed to update file content: ' . $e->getMessage());
        }

        // Update the mapping with the target_fid if it's not already set.
        if (empty($target_fid_from_mapping)) {
          try {
            $mapping->addRemoteData('target_fid', $target_file->id());
            $mapping->save();

            $this->logDebug('Updated target_fid to @fid in mapping @map_id using Entity API', [
              '@fid' => $target_file->id(),
              '@map_id' => $mapping->id(),
            ]);
          }
          catch (\Exception $e) {
            $this->logWarn('Exception while updating target_fid in mapping: @error', [
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
      // If we have a target_fid in the mapping but couldn't find the file in the entity data.
      elseif ($target_fid_from_mapping) {
        $target_file = File::load($target_fid_from_mapping);

        if ($target_file) {
          $this->logDebug('Using existing translated file (FID: @fid) from mapping', [
            '@fid' => $target_file->id(),
          ]);

          // Update the existing translated file.
          $file_uri = '';

          // Try to get the file URI using different methods.
          try {
            if (isset($target_file->uri)) {
              $file_uri = $target_file->uri->value;
            }
            else {
              // Last resort: try to get it from the database.
              $file_data = \Drupal::database()->select('file_managed', 'fm')
                ->fields('fm', ['uri'])
                ->condition('fid', $target_file->id())
                ->execute()
                ->fetchField();

              if ($file_data) {
                $file_uri = $file_data;
              }
            }
          }
          catch (\Exception $e) {
            $this->logWarn('Exception while getting file URI: @error', [
              '@error' => $e->getMessage(),
            ]);
          }

          if (empty($file_uri)) {
            throw new TMGMTException('Could not determine file URI for file with ID @fid', [
              '@fid' => $target_file->id(),
            ]);
          }

          try {
            // First, make sure the directory exists.
            $directory = dirname($file_uri);
            \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

            // Write directly to the destination file.
            $bytes_written = @file_put_contents($file_uri, $translated_content);
            if ($bytes_written === FALSE) {
              $error_message = error_get_last() ? error_get_last()['message'] : 'Unknown error';
              $this->logError('Failed to write translated content to file @uri: @error', [
                '@uri' => $file_uri,
                '@error' => $error_message,
              ]);
              $job->addMessage('Failed to write translated content to file @filename: @error', [
                '@filename' => basename($file_uri),
                '@error' => $error_message,
              ], 'error');

              throw new TMGMTException('Could not write translated content to file: @uri. Error: @error', [
                '@uri' => $file_uri,
                '@error' => $error_message,
              ]);
            }
            // Verify the file was written correctly.
            if (!file_exists($file_uri)) {
              $this->logError('File @uri does not exist after writing', [
                '@uri' => $file_uri,
              ]);

              $job->addMessage('File @filename does not exist after writing', [
                '@filename' => basename($file_uri),
              ], 'error');

              throw new TMGMTException('File @uri does not exist after writing', [
                '@uri' => $file_uri,
              ]);
            }

            // Verify the file size.
            $expected_size = strlen($translated_content);
            $actual_size = filesize($file_uri);
            if ($actual_size !== $expected_size) {
              $this->logWarn('File size mismatch after writing to @uri. Expected: @expected bytes, Actual: @actual bytes', [
                '@uri' => $file_uri,
                '@expected' => $expected_size,
                '@actual' => $actual_size,
              ]);
            }

            $this->logDebug('Successfully wrote translated content directly to file: @uri', [
              '@uri' => $file_uri,
            ]);
            try {
              $file_id = $target_file->id(); // Store the ID before reloading.
              $target_file = File::load($file_id); // Reload to ensure we have the latest version.
              if (!$target_file) {
                $this->logError('Failed to reload file entity with ID @fid after writing content', [
                  '@fid' => $file_id,
                ]);

                $job->addMessage('Failed to reload file entity after writing content', [], 'error');
                throw new TMGMTException('Failed to reload file entity after writing content');
              }

              $save_result = $target_file->save(); // This will update the changed time automatically.
              if ($save_result === FALSE) {
                $this->logError('Failed to save updated file entity with ID @fid', [
                  '@fid' => $target_file->id(),
                ]);

                $job->addMessage('Failed to save updated file entity @filename', [
                  '@filename' => $target_file->getFilename(),
                ], 'error');

                throw new TMGMTException('Failed to save updated file entity');
              }
            }
            catch (\Exception $e) {
              if (!($e instanceof TMGMTException)) {
                $this->logError('Exception while saving file entity: @error', [
                  '@error' => $e->getMessage(),
                ]);

                $job->addMessage('Exception while saving file entity: @error', [
                  '@error' => $e->getMessage(),
                ], 'error');

                throw new TMGMTException('Exception while saving file entity: ' . $e->getMessage());
              }
              throw $e;
            }

            $this->logDebug('Successfully updated existing translated file @uri (FID: @fid) from mapping', [
              '@uri' => $file_uri,
              '@fid' => $target_file->id(),
            ]);
          }
          catch (\Exception $e) {
            $this->logError('Exception while updating file content: @error', [
              '@error' => $e->getMessage(),
            ]);
            throw new TMGMTException('Failed to update file content: ' . $e->getMessage());
          }
        }
        else {
          // The file referenced in the mapping doesn't exist anymore, create a new one.
          $this->logWarn('File with FID @fid referenced in mapping no longer exists, creating new file', [
            '@fid' => $target_fid_from_mapping,
          ]);

          // Create a new translated file (see below)
          $target_file = NULL;
        }
      }
      else {
        // No existing translated file found, create a new one.
        $target_file = NULL;
      }

      // If we still don't have a target file, create a new one.
      if (!$target_file) {
        $this->logDebug('Creating new translated file for source file @filename (FID: @fid)', [
          '@filename' => $source_file->getFilename(),
          '@fid' => $source_file->id(),
        ]);

        try {
          // Get the target language code.
          $target_langcode = $job->getTargetLangcode();

          // Get the source filename and extension.
          $source_filename = $source_file->getFilename();
          $filename_parts = pathinfo($source_filename);
          $extension = $filename_parts['extension'] ?? '';
          $basename = $filename_parts['filename'] ?? $source_filename;

          // Create a filename for the translated file.
          // Format: original_name_targetlang.ext (e.g., document_de.docx)
          // No suffix - just the language code.
          $translated_filename = $basename . '_' . $target_langcode . '.' . $extension;

          // Get the directory where the source file is stored.
          $source_directory = dirname($source_file->getFileUri());

          // Create the destination URI.
          $destination_uri = $source_directory . '/' . $translated_filename;

          // Check if a file with this name already exists.
          $existing_files = \Drupal::entityTypeManager()
            ->getStorage('file')
            ->loadByProperties(['uri' => $destination_uri]);

          if (!empty($existing_files)) {
            // File already exists, use it.
            $target_file = reset($existing_files);
            $this->logDebug('Found existing file with same URI: @uri (FID: @fid)', [
              '@uri' => $destination_uri,
              '@fid' => $target_file->id(),
            ]);

            // Update the existing file.
            $file_uri = '';

            // Try to get the file URI using different methods.
            try {
              if (isset($target_file->uri)) {
                $file_uri = $target_file->uri->value;
              }
              else {
                // Last resort: try to get it from the database.
                $file_data = \Drupal::database()->select('file_managed', 'fm')
                  ->fields('fm', ['uri'])
                  ->condition('fid', $target_file->id())
                  ->execute()
                  ->fetchField();

                if ($file_data) {
                  $file_uri = $file_data;
                }
              }
            }
            catch (\Exception $e) {
              $this->logWarn('Exception while getting file URI: @error', [
                '@error' => $e->getMessage(),
              ]);
            }

            if (empty($file_uri)) {
              throw new TMGMTException('Could not determine file URI for file with ID @fid', [
                '@fid' => $target_file->id(),
              ]);
            }

            // First, make sure the directory exists.
            $directory = dirname($file_uri);
            \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

            // Write directly to the destination file.
            $bytes_written = @file_put_contents($file_uri, $translated_content);
            if ($bytes_written === FALSE) {
              $error_message = error_get_last() ? error_get_last()['message'] : 'Unknown error';
              $this->logError('Failed to write translated content to file @uri: @error', [
                '@uri' => $file_uri,
                '@error' => $error_message,
              ]);

              // Add a job message for user visibility.
              $job->addMessage('Failed to write translated content to file @filename: @error', [
                '@filename' => basename($file_uri),
                '@error' => $error_message,
              ], 'error');

              throw new TMGMTException('Could not write translated content to file: @uri. Error: @error', [
                '@uri' => $file_uri,
                '@error' => $error_message,
              ]);
            }

            // Verify the file was written correctly.
            if (!file_exists($file_uri)) {
              $this->logError('File @uri does not exist after writing', [
                '@uri' => $file_uri,
              ]);

              $job->addMessage('File @filename does not exist after writing', [
                '@filename' => basename($file_uri),
              ], 'error');

              throw new TMGMTException('File @uri does not exist after writing', [
                '@uri' => $file_uri,
              ]);
            }

            // Verify the file size.
            $expected_size = strlen($translated_content);
            $actual_size = filesize($file_uri);
            if ($actual_size !== $expected_size) {
              $this->logWarn('File size mismatch after writing to @uri. Expected: @expected bytes, Actual: @actual bytes', [
                '@uri' => $file_uri,
                '@expected' => $expected_size,
                '@actual' => $actual_size,
              ]);
            }

            $this->logDebug('Successfully wrote translated content directly to file: @uri', [
              '@uri' => $file_uri,
            ]);

            // Update the file entity.
            try {
              $file_id = $target_file->id(); // Store the ID before reloading.
              $target_file = File::load($file_id); // Reload to ensure we have the latest version.
              if (!$target_file) {
                $this->logError('Failed to reload file entity with ID @fid after writing content', [
                  '@fid' => $file_id,
                ]);

                $job->addMessage('Failed to reload file entity after writing content', [], 'error');
                throw new TMGMTException('Failed to reload file entity after writing content');
              }

              $save_result = $target_file->save(); // This will update the changed time automatically.
              if ($save_result === FALSE) {
                $this->logError('Failed to save updated file entity with ID @fid', [
                  '@fid' => $target_file->id(),
                ]);

                $job->addMessage('Failed to save updated file entity @filename', [
                  '@filename' => $target_file->getFilename(),
                ], 'error');

                throw new TMGMTException('Failed to save updated file entity');
              }
            }
            catch (\Exception $e) {
              if (!($e instanceof TMGMTException)) {
                $this->logError('Exception while saving file entity: @error', [
                  '@error' => $e->getMessage(),
                ]);

                $job->addMessage('Exception while saving file entity: @error', [
                  '@error' => $e->getMessage(),
                ], 'error');

                throw new TMGMTException('Exception while saving file entity: ' . $e->getMessage());
              }
              throw $e;
            }

            $this->logDebug('Successfully updated existing file with same URI @uri (FID: @fid)', [
              '@uri' => $file_uri,
              '@fid' => $target_file->id(),
            ]);
          }
          else {
            // Create a new file using the TMGMT core service.
            $this->logDebug('Creating new translated file using TMGMT core service');

            // Get the target language code.
            $target_langcode = $job->getTargetLangcode();

            // Use our refactored createFileTranslation method which uses the TMGMT core service.
            $target_file = $this->createFileTranslation(
              $source_file,
              $target_langcode,
              $translated_content,
              $destination_uri
            );

            if (!$target_file) {
              throw new TMGMTException('Failed to create translated file: @uri', [
                '@uri' => $destination_uri,
              ]);
            }

            // Set the file as permanent.
            try {
              $target_file->setPermanent();
              $save_result = $target_file->save();

              if ($save_result === FALSE) {
                $this->logError('Failed to save file entity with ID @fid after setting as permanent', [
                  '@fid' => $target_file->id(),
                ]);

                $job->addMessage('Failed to save file entity @filename after setting as permanent', [
                  '@filename' => $target_file->getFilename(),
                ], 'error');

                throw new TMGMTException('Failed to save file entity after setting as permanent');
              }
            }
            catch (\Exception $e) {
              if (!($e instanceof TMGMTException)) {
                $this->logError('Exception while setting file as permanent: @error', [
                  '@error' => $e->getMessage(),
                ]);

                $job->addMessage('Exception while setting file as permanent: @error', [
                  '@error' => $e->getMessage(),
                ], 'error');

                throw new TMGMTException('Exception while setting file as permanent: ' . $e->getMessage());
              }
              throw $e;
            }

            $this->logDebug('Successfully created new translated file @uri (FID: @fid)', [
              '@uri' => $target_file->getFileUri(),
              '@fid' => $target_file->id(),
            ]);
          }

          // Store the target file ID in the mapping for future reference.
          try {
            // Use the Entity API to add the target_fid.
            $mapping->addRemoteData('target_fid', $target_file->id());
            $save_result = $mapping->save();

            if ($save_result === FALSE) {
              $this->logError('Failed to save mapping @map_id after adding target_fid @fid', [
                '@map_id' => $mapping->id(),
                '@fid' => $target_file->id(),
              ]);

              $job->addMessage('Failed to save mapping data with translated file reference. Future translation attempts may create duplicate files.', [], 'warning');

              // Try fallback to direct DB update.
              throw new \Exception('Entity API save returned FALSE');
            }

            $this->logDebug('Added target_fid @fid to mapping @map_id using Entity API', [
              '@fid' => $target_file->id(),
              '@map_id' => $mapping->id(),
            ]);
          }
          catch (\Exception $e) {
            $this->logWarn('Exception while adding target_fid to mapping: @error', [
              '@error' => $e->getMessage(),
            ]);

            // Fallback to direct database update if Entity API fails.
            try {
              $db_data = \Drupal::database()->select('tmgmt_remote', 'tr')
                ->fields('tr', ['remote_data'])
                ->condition('trid', $mapping->id())
                ->execute()
                ->fetchField();

              if (!empty($db_data) && is_string($db_data)) {
                $unserialized = @unserialize($db_data);
                if (is_array($unserialized)) {
                  $unserialized['target_fid'] = (string) $target_file->id();
                  $serialized = serialize($unserialized);

                  $update_result = \Drupal::database()->update('tmgmt_remote')
                    ->fields(['remote_data' => $serialized])
                    ->condition('trid', $mapping->id())
                    ->execute();

                  if ($update_result) {
                    $this->logDebug('Added target_fid @fid to mapping @map_id via direct DB update', [
                      '@fid' => $target_file->id(),
                      '@map_id' => $mapping->id(),
                    ]);
                  } else {
                    $this->logError('Failed to update mapping @map_id via direct DB update', [
                      '@map_id' => $mapping->id(),
                    ]);

                    $job->addMessage('Failed to save mapping data with translated file reference. Future translation attempts may create duplicate files.', [], 'warning');
                  }
                } else {
                  $this->logError('Failed to unserialize remote_data for mapping @map_id', [
                    '@map_id' => $mapping->id(),
                  ]);
                }
              } else {
                $this->logError('Empty or invalid remote_data for mapping @map_id', [
                  '@map_id' => $mapping->id(),
                ]);
              }
            }
            catch (\Exception $db_e) {
              $this->logError('DB Exception while adding target_fid to mapping: @error', [
                '@error' => $db_e->getMessage(),
              ]);

              $job->addMessage('Failed to save mapping data with translated file reference. Future translation attempts may create duplicate files.', [], 'warning');
            }
            // Continue with normal processing.
          }
        }
        catch (\Exception $e) {
          $this->logError('Exception while creating translated file: @error', [
            '@error' => $e->getMessage(),
          ]);
          throw new TMGMTException('Failed to create translated file: ' . $e->getMessage());
        }
      }

      // Update the job item data with the translated file reference.
      try {
        $this->logDebug('Updating job item data with translated file reference for key @datakey', [
          '@datakey' => $datakey,
        ]);

        // --- Create a properly structured data array for addTranslatedData ---.
        $job_item_data_to_add = [];
        $keys = [];

        // Parse the datakey format properly.
        if (strpos($datakey, '][') !== FALSE) {
          // Format like "field_msoffice][1][target_id".
          $parts = explode('][', $datakey);

          // Handle the first part which might not have a leading [.
          $first_part = $parts[0];
          $keys[] = $first_part;

          // Handle the remaining parts.
          for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            // Remove trailing ] from the last part if present.
            if ($i == count($parts) - 1 && substr($part, -1) == ']') {
              $part = substr($part, 0, -1);
            }
            $keys[] = $part;
          }
        } else {
          // Simple key without brackets.
          $keys = [$datakey];
        }

        $this->logDebug('Parsed keys from datakey: @keys', [
          '@keys' => implode(', ', $keys),
        ]);

        // Build the nested array structure for addTranslatedData.
        $temp_ref = &$job_item_data_to_add;

        // Create the nested structure.
        for ($i = 0; $i < count($keys) - 1; $i++) {
          $key = $keys[$i];
          $temp_ref[$key] = [];
          $temp_ref = &$temp_ref[$key];
        }

        // Set the value at the deepest level.
        $last_key = $keys[count($keys) - 1];
        $temp_ref[$last_key] = [
          '#file' => $target_file->id(),
          '#translation' => $target_file->id(),
          // Status will be handled by the job item state.
        ];

        unset($temp_ref); // Unset the reference.
        // Add the translated data to the job item.
        try {
          // Set the appropriate status based on the state.
          $target_data_item_state = $this->resolveTargetDataItemState($tms_job_status, $job->getTranslator()->isAutoAccept());
          $job_item->addTranslatedData($job_item_data_to_add, [], $target_data_item_state);

          // Log successful addition of translated data.
          $this->logDebug('Successfully added translated data for key @key to job item @id', [
            '@key' => $datakey,
            '@id' => $job_item->id(),
          ]);

          // Verify the data was added correctly by checking the job item data.
          $updated_data = $job_item->getData();
          $data_keys = array_keys($updated_data);
          $this->logDebug('Job item data after addTranslatedData - Keys: @keys', [
            '@keys' => implode(', ', array_slice($data_keys, 0, 5)) . (count($data_keys) > 5 ? '... (and ' . (count($data_keys) - 5) . ' more)' : ''),
          ]);
        }
        catch (\Exception $e) {
          $this->logError('Exception while adding translated file data to job item for key @key: @error', [
            '@key' => $datakey,
            '@error' => $e->getMessage(),
          ]);

          $job->addMessage('Exception while adding translated file data to job item: @error', [
            '@error' => $e->getMessage(),
          ], 'error');

          throw new TMGMTException('Exception while adding translated file data to job item: ' . $e->getMessage());
        }

        $this->logDebug('Added file reference to job item data for key @datakey with FID: @fid', [
          '@datakey' => $datakey,
          '@fid' => $target_file->id(),
        ]);

        // Note: We do NOT save the job item here. The save will happen once at the end.
      }
      catch (\Exception $e) {
        $this->logError('Exception while updating job item data for key @key: @error', [
          '@key' => $datakey,
          '@error' => $e->getMessage(),
        ]);
        throw new TMGMTException('Failed to update job item data with translated file reference: ' . $e->getMessage());
      }
    }
    else {
      // This is an XLIFF content mapping, use the original logic with enhanced error handling.
      $this->logDebug('Processing XLIFF content for job part @job_part_id', [
        '@job_part_id' => $job_part_id,
      ]);

      try {
        // Set a longer timeout for XLIFF downloads.
        $timeout = 180; // 3 minutes.
        try {
          $data = $this->sendApiRequest("/api2/v1/projects/$project_id/jobs/$job_part_id/targetFile", 'GET', [], TRUE, FALSE, NULL, $timeout);

          if (empty($data)) {
            $this->logError('Empty XLIFF content received from Phrase TMS for job part @job_part_id', [
              '@job_part_id' => $job_part_id,
            ]);
            throw new TMGMTException('Empty XLIFF content received from Phrase TMS.');
          }

          // Check if data is not a string (could be an integer status code)
          if (!is_string($data)) {
            $this->logError('XLIFF content is not a string. Type: @type, Value: @value', [
              '@type' => gettype($data),
              '@value' => var_export($data, TRUE),
            ]);

            // Try an alternative approach - use exportOriginal instead of targetFile.
            $data = $this->sendApiRequest("/api2/v1/projects/$project_id/jobs/$job_part_id/exportOriginal", 'GET', [], TRUE, FALSE, NULL, $timeout);

            if (!is_string($data)) {
              $this->logError('Alternative API endpoint also returned non-string data. Type: @type, Value: @value', [
                '@type' => gettype($data),
                '@value' => var_export($data, TRUE),
              ]);
              throw new TMGMTException('Failed to get XLIFF content as string from Phrase TMS.');
            }
          }
        } catch (\Exception $e) {
          $this->logError('Exception while downloading XLIFF content: @error', [
            '@error' => $e->getMessage(),
          ]);
          throw $e;
        }

        // Check if the data looks like XML.
        $is_xml = FALSE;
        if (is_string($data)) {
          $trimmed_data = trim($data);
          $is_xml = (substr($trimmed_data, 0, 5) === '<?xml' || substr($trimmed_data, 0, 1) === '<');

          if (!$is_xml) {
            // Check if it's a binary file (DOCX, etc.)
            if (substr($data, 0, 2) === 'PK') {
              $this->logError('Downloaded content appears to be a ZIP file (starts with PK). This is likely a binary file, not XLIFF XML.');

              // Try to save the content to a temporary file for inspection.
              $temp_file = tempnam(sys_get_temp_dir(), 'xliff_debug_');
              if ($temp_file) {
                file_put_contents($temp_file, $data);
                // Try to determine the file type using file command.
                mime_content_type($temp_file);
              }

              // Try to download the content again with a different approach.
              try {
                // Use a different API endpoint or parameter to get the XLIFF content.
                $alternate_data = $this->sendApiRequest("/api2/v1/projects/$project_id/jobs/$job_part_id/exportOriginal", 'GET', [], TRUE, FALSE, NULL, 180);

                if (!empty($alternate_data) && is_string($alternate_data)) {


                  // Check if the alternate content is XML.
                  $alt_is_xml = (substr(trim($alternate_data), 0, 5) === '<?xml' || substr(trim($alternate_data), 0, 1) === '<');

                  if ($alt_is_xml) {
                    $data = $alternate_data;
                    $is_xml = TRUE;
                  }
                }
              } catch (\Exception $e) {
                $this->logError('Failed to download alternate content: @error', [
                  '@error' => $e->getMessage(),
                ]);
              }
            }
            // Check if the content contains XLIFF tags despite not starting with XML declaration.
            if (strpos($data, '<xliff') !== FALSE || strpos($data, '<trans-unit') !== FALSE) {
              $is_xml = TRUE;
            }
          }
        } else {
          $this->logError('Downloaded content is not a string. Type: @type', [
            '@type' => gettype($data),
          ]);
        }

        // Simple check for XML content.
        $is_binary = FALSE;

        // Check if the content looks like XML.
        if (is_string($data) && !preg_match('/<\?xml|<xliff|<[a-zA-Z]/s', substr($data, 0, 200))) {
          $is_binary = TRUE;
          $this->logDebug('Content does not appear to be XML/XLIFF format');
        }

        // If we detected binary content but the mapping says this should be XLIFF.
        if ($is_binary && !$is_file) {
          $this->logError('Content type mismatch: Expected XLIFF based on mapping (isFile=FALSE), but received binary data for job part @job_part_id.', [
            '@job_part_id' => $job_part_id,
          ]);

          // Check if we have a database record for this mapping.
          $db_data = \Drupal::database()->select('tmgmt_remote', 'tr')
            ->fields('tr', ['remote_data'])
            ->condition('trid', $mapping->id())
            ->execute()
            ->fetchField();
          // Enhanced recovery logic - try to determine if this should have been a file mapping.
          // First check if we have source_fid and datakey in the remote data.
          $source_fid = $remote_data['source_fid'] ?? NULL;
          $datakey = $remote_data['datakey'] ?? NULL;

          // If we don't have source_fid/datakey in remote_data, try to find it in the job item's translator_state
          if (!$source_fid || !$datakey) {
            $translator_state = $job_item->get('translator_state')->getValue();
            if (!empty($translator_state[0]['value']['tmgmt_memsource_files'])) {
              $files_data = $translator_state[0]['value']['tmgmt_memsource_files'];
              // Try to match the job part ID with a file in translator_state.
              // This is a best-effort approach.
              foreach ($files_data as $file_info) {
                // If we find a match or this is the only file, use it.
                if (count($files_data) === 1) {
                  $source_fid = $file_info['file_id'];
                  $datakey = $file_info['datakey'];

                  break;
                }
              }
            }
          }

          if ($source_fid && $datakey && ($source_file = File::load($source_fid))) {
            $this->logWarn('Attempting recovery: Processing binary data as a file. Source FID=@fid, datakey=@key', [
              '@fid' => $source_fid,
              '@key' => $datakey,
            ]);

            try {
              // Use our refactored createFileTranslation method which uses the TMGMT core service.
              $target_langcode = $job->getTargetLangcode();
              $target_file = $this->createFileTranslation($source_file, $target_langcode, $data);

              if (!$target_file) {
                throw new TMGMTException('Recovery failed: Could not create translated file.');
              }

              // Store the target file ID in the mapping for future reference.
              // Also update isFile to TRUE for consistency.
              $mapping->removeRemoteData('isFile');
              $mapping->addRemoteData('isFile', TRUE);
              $mapping->addRemoteData('source_fid', (string) $source_fid);
              $mapping->addRemoteData('datakey', $datakey);
              $mapping->addRemoteData('target_fid', (string) $target_file->id());
              $mapping->save();
              // Update job item data reference.
              $job_item_data = $job_item->getData();

              // Handle the datakey format properly.
              if (strpos($datakey, '][') !== FALSE) {
                // Format like "field_msoffice][1][target_id".
                $keys = [];
                $parts = explode('][', $datakey);

                // Handle the first part which might not have a leading [.
                $first_part = $parts[0];
                $keys[] = $first_part;

                // Handle the remaining parts.
                for ($i = 1; $i < count($parts); $i++) {
                  $part = $parts[$i];
                  // Remove trailing ] from the last part if present.
                  if ($i == count($parts) - 1 && substr($part, -1) == ']') {
                    $part = substr($part, 0, -1);
                  }
                  $keys[] = $part;
                }
              } else {
                // Simple key without brackets.
                $keys = [$datakey];
              }

              // Now navigate through the data structure.
              $data_ref = &$job_item_data;
              $path_so_far = '';

              foreach ($keys as $key) {
                $path_so_far .= ($path_so_far ? '>' : '') . $key;

                if (!isset($data_ref[$key])) {
                  throw new TMGMTException('Recovery failed: Invalid data key path: @datakey - key @key not found (path: @path)', [
                    '@datakey' => $datakey,
                    '@key' => $key,
                    '@path' => $path_so_far,
                  ]);
                }
                $data_ref = &$data_ref[$key];
              }

              if (isset($data_ref['#file'])) {
                // Update the file reference using standard Entity API.
                $data_ref['#file'] = $target_file->id();
                $data_ref['#translation'] = $target_file->id();

                // Add the translated data to the job item.
                $job_item->addTranslatedData([$datakey => $data_ref]);
                $this->logWarn('Recovery successful: Processed binary data as file and updated reference.');
                // Set is_file locally for the aggregation check logic.
                $is_file = TRUE;
              } else {
                throw new TMGMTException('Recovery failed: Could not find #text in data structure for key @key.', [
                  '@key' => $datakey,
                ]);
              }
            } catch (\Exception $recovery_e) {
              $this->logError('Recovery attempt failed for job part @job_part_id: @error', [
                '@job_part_id' => $job_part_id,
                '@error' => $recovery_e->getMessage(),
              ]);
              // Throw the original mismatch error if recovery fails.
              throw new TMGMTException('Content type mismatch: Expected XLIFF, received non-XML data for job part @id. Recovery failed: @error', [
                '@id' => $job_part_id,
                '@error' => $recovery_e->getMessage(),
              ]);
            }
          } else {
            // Cannot recover, throw original mismatch error with more details.
            throw new TMGMTException('Content type mismatch: Expected XLIFF, received non-XML data for job part @id. Cannot recover (missing file info in mapping). Source FID: @fid, Datakey: @key', [
              '@id' => $job_part_id,
              '@fid' => $source_fid ?? 'NOT FOUND',
              '@key' => $datakey ?? 'NOT FOUND',
            ]);
          }
        } else {
          // Parse the XLIFF content (original logic)
          $file_data = $this->parseTranslationData($data);

          if ($file_data === FALSE) {
            $this->logError('Failed to parse XLIFF content from Phrase TMS for job part @job_part_id', [
              '@job_part_id' => $job_part_id,
            ]);
            throw new TMGMTException('Failed to parse XLIFF content from Phrase TMS.');
          }
          // This happens when the XLIFF file has a different structure than expected.
          if (is_array($file_data) && count($file_data) === 1 && isset($file_data[$job_item->id()])) {

            // Get the data stored under the job item ID key.
            $job_item_data = $file_data[$job_item->id()];

            // Replace the file_data with the job item data.
            $file_data = $job_item_data;
          }
        }

        if (empty($file_data)) {
          $this->logWarn('Parsed XLIFF content is empty for job part @job_part_id', [
            '@job_part_id' => $job_part_id,
          ]);
        }

        // Check for identical source/target warning
        if (isset($file_data['_meta']) && !empty($file_data['_meta']['all_translations_identical'])) {
          $job->addMessage('Warning: All translations in the XLIFF are identical to source text. This may indicate a translation issue.', [], 'warning');
        }

        // Set the appropriate status based on the state.
        $target_data_item_state = $this->resolveTargetDataItemState($tms_job_status, $job->getTranslator()->isAutoAccept());
        // Check if file_data is empty.
        if (empty($file_data)) {
          $this->logError('Empty file_data for job item @job_item_id. Cannot add translated data.', [
            '@job_item_id' => $job_item->id(),
          ]);
        } else {
          if (is_array($file_data)) {
            foreach ($file_data as $key => $unit) {
              if (is_array($unit) && isset($unit['#translation']) && is_array($unit['#translation'])) {
                // Make sure the translation has the required fields.
                if (!isset($unit['#translation']['#text'])) {
                  $this->logWarn('Translation unit @key is missing #text in #translation. Adding empty value.', [
                    '@key' => $key
                  ]);
                  $file_data[$key]['#translation']['#text'] = '';
                }

                // Make sure origin is set.
                if (!isset($unit['#translation']['#origin'])) {
                  $this->logWarn('Translation unit @key is missing #origin in #translation. Setting to "remote".', [
                    '@key' => $key
                  ]);
                  $file_data[$key]['#translation']['#origin'] = 'remote';
                }

                // Make sure timestamp is set.
                if (!isset($unit['#translation']['#timestamp'])) {
                  $this->logWarn('Translation unit @key is missing #timestamp in #translation. Setting to current time.', [
                    '@key' => $key
                  ]);
                  $file_data[$key]['#translation']['#timestamp'] = \Drupal::time()->getRequestTime();
                }
              } else if (is_array($unit) && isset($unit['#text']) && !isset($unit['#translation'])) {
                // If there's a #text but no #translation, create a proper #translation structure.
                $this->logWarn('Translation unit @key has #text but no #translation structure. Creating proper structure.', [
                  '@key' => $key
                ]);

                // Create a proper translation structure.
                $file_data[$key]['#translation'] = [
                  '#text' => isset($unit['#text']) ? $unit['#text'] : '',
                  '#origin' => 'remote',
                  '#timestamp' => \Drupal::time()->getRequestTime()
                ];
              }
            }
          }
          // Add the translated data.
          $job_item->addTranslatedData($file_data, [], $target_data_item_state);

          // Force a save of the job item to ensure the data is persisted.
          try {
            $save_result = $job_item->save();

            if ($save_result === FALSE) {
              $this->logError('Failed to save job item @job_item_id after adding translated data', [
                '@job_item_id' => $job_item->id(),
              ]);

              $job->addMessage('Failed to save job item data after adding translated content. Some translations may not be properly saved.', [], 'error');
            }
          }
          catch (\Exception $e) {
            $this->logError('Exception while saving job item @job_item_id after adding translated data: @error', [
              '@job_item_id' => $job_item->id(),
              '@error' => $e->getMessage(),
            ]);

            $job->addMessage('Exception while saving job item data: @error', [
              '@error' => $e->getMessage(),
            ], 'error');
          }
        }

        // Note: We still do a final save at the end after all parts have been processed,
        // but we also save immediately to ensure the data is persisted.
      }
      catch (TMGMTException $e) {
        $this->logError('Error processing XLIFF content: @error', [
          '@error' => $e->getMessage(),
        ]);
        throw $e;
      }
      catch (\Exception $e) {
        $this->logError('Unexpected error processing XLIFF content: @error', [
          '@error' => $e->getMessage(),
        ]);
        throw new TMGMTException('Failed to process XLIFF content: ' . $e->getMessage());
      }
    }

    // Update the mapping state using standard Entity API.
    try {
      $mapping->removeRemoteData('TmsState');
      $mapping->addRemoteData('TmsState', $tms_job_status);
      $save_result = $mapping->save();

      if ($save_result === FALSE) {
        $this->logError('Failed to save mapping @map_id after updating TmsState to @state', [
          '@map_id' => $mapping->id(),
          '@state' => $tms_job_status,
        ]);

        // Try fallback to direct database update.
        throw new \Exception('Entity API save returned FALSE');
      }

      $this->logDebug('Successfully updated TmsState to @state using Entity API for mapping @map_id', [
        '@state' => $tms_job_status,
        '@map_id' => $mapping->id(),
      ]);
    } catch (\Exception $e) {
      $this->logError('Exception during TmsState update for mapping @map_id: @error', [
        '@map_id' => $mapping->id(),
        '@error' => $e->getMessage(),
      ]);

      // Fallback to direct database update if Entity API fails.
      try {
        $db_data = \Drupal::database()->select('tmgmt_remote', 'tr')
          ->fields('tr', ['remote_data'])
          ->condition('trid', $mapping->id())
          ->execute()
          ->fetchField();

        if (!empty($db_data) && is_string($db_data)) {
          $unserialized = @unserialize($db_data);
          if (is_array($unserialized)) {
            // Remove existing TmsState if present.
            if (isset($unserialized['TmsState'])) {
              unset($unserialized['TmsState']);
            }

            // Add the new state.
            $unserialized['TmsState'] = $tms_job_status;
            $serialized = serialize($unserialized);

            $update_result = \Drupal::database()->update('tmgmt_remote')
              ->fields(['remote_data' => $serialized])
              ->condition('trid', $mapping->id())
              ->execute();

            if ($update_result) {
              $this->logDebug('Updated TmsState to @state for mapping @map_id via direct DB update', [
                '@state' => $tms_job_status,
                '@map_id' => $mapping->id(),
              ]);
            } else {
              $this->logError('Failed to update TmsState for mapping @map_id via direct DB update', [
                '@map_id' => $mapping->id(),
              ]);
            }
          } else {
            $this->logError('Failed to unserialize remote_data for mapping @map_id during TmsState update', [
              '@map_id' => $mapping->id(),
            ]);
          }
        } else {
          $this->logError('Empty or invalid remote_data for mapping @map_id during TmsState update', [
            '@map_id' => $mapping->id(),
          ]);
        }
      }
      catch (\Exception $db_e) {
        $this->logError('DB Exception during TmsState update for mapping @map_id: @error', [
          '@map_id' => $mapping->id(),
          '@error' => $db_e->getMessage(),
        ]);
      }
    }

    // Check if all parts for this job item are completed.
    if ($this->remoteTranslationCompleted($tms_job_status)) {
      try {
        $all_mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());

        if (empty($all_mappings)) {
          return;
        }

        $all_completed = TRUE;
        $completed_count = 0;
        $total_count = count($all_mappings);
        $incomplete_parts = [];

        foreach ($all_mappings as $m) {
          $m_id = $m->id();
          $m_remote_id = $m->getRemoteIdentifier3();

          // Get the raw remote_data field value.
          $m_data_field = $m->get('remote_data')->getValue();
          $m_data = [];

          // Check if we have any data.
          if (!empty($m_data_field) && is_array($m_data_field)) {
            // The field value is an array of values, we need the first one.
            $m_data = reset($m_data_field);
          }

          $is_file = FALSE;
          $m_state = '';

          if (!empty($m_data)) {
            try {
              // Check for TmsState.
              $m_state = $m_data['TmsState'] ?? '';

              // Check for isFile with multiple possible formats.
              if (isset($m_data['isFile'])) {
                if ($m_data['isFile'] === TRUE ||
                    $m_data['isFile'] === 'TRUE' ||
                    $m_data['isFile'] === '1' ||
                    $m_data['isFile'] === 1) {
                  $is_file = TRUE;
                }
              }

              // Also check for source_fid and datakey as indicators of a file mapping.
              if (!$is_file && !empty($m_data['source_fid']) && !empty($m_data['datakey'])) {
                $is_file = TRUE;
                $this->logDebug('Aggregation check: Mapping @map_id has source_fid and datakey but isFile is not set. Treating as file mapping.', [
                  '@map_id' => $m_id,
                ]);

                // Update the isFile flag using standard Entity API.
                try {
                  $m->addRemoteData('isFile', TRUE);
                  $m->save();

                  $this->logDebug('Successfully updated isFile to TRUE using Entity API for mapping @map_id during aggregation check', [
                    '@map_id' => $m->id(),
                  ]);
                } catch (\Exception $e) {
                  $this->logError('Exception during isFile update in aggregation check for mapping @map_id: @error', [
                    '@map_id' => $m->id(),
                    '@error' => $e->getMessage(),
                  ]);
                }
              }
            } catch (\Exception $e) {
              $this->logError('Exception while processing remote_data for mapping @map_id during aggregation check: @error', [
                '@map_id' => $m_id,
                '@error' => $e->getMessage(),
              ]);
            }
          }

          $this->logDebug('Aggregation check: isFile value for mapping @map_id: @is_file (raw: @raw)', [
              '@map_id' => $m_id,
              '@is_file' => $is_file ? 'TRUE' : 'FALSE',
              '@raw' => var_export($m_data['isFile'] ?? 'NOT SET', TRUE),
          ]);

          $this->logDebug('Part @part_id (mapping ID: @mapping_id) has state: @state (isFile: @is_file)', [
            '@part_id' => $m_remote_id,
            '@mapping_id' => $m_id,
            '@state' => $m_state,
            '@is_file' => $is_file ? 'yes' : 'no',
          ]);

          if ($this->remoteTranslationCompleted($m_state)) {
            $completed_count++;
          }
          else {
            $all_completed = FALSE;
            $incomplete_parts[] = $m_remote_id;
          }
        }

        $this->logDebug('@completed_count of @total_count parts completed for job item @job_item_id', [
          '@completed_count' => $completed_count,
          '@total_count' => $total_count,
          '@job_item_id' => $job_item->id(),
        ]);

        // If all parts are completed, set the job item to review state.
        if ($all_completed) {
          try {
            $old_state = $job_item->getState();

            // Force update the state directly in the database to ensure it takes effect.
            \Drupal::database()->update('tmgmt_job_item')
              ->fields(['state' => JobItemInterface::STATE_REVIEW])
              ->condition('tjiid', $job_item->id())
              ->execute();

            // Get the updated state directly from the database.
            $new_state = \Drupal::database()->select('tmgmt_job_item', 'tji')
              ->fields('tji', ['state'])
              ->condition('tjiid', $job_item->id())
              ->execute()
              ->fetchField();

            $this->logDebug('Job item state transition (DB update): @old_state -> @new_state', [
              '@old_state' => $old_state,
              '@new_state' => $new_state,
            ]);

            $job->addMessage('All @count translation parts for job item @job_item_id are complete.', [
              '@count' => $total_count,
              '@job_item_id' => $job_item->id(),
            ], 'status');
          }
          catch (\Exception $e) {
            $this->logError('Exception during job item state transition: @error', [
              '@error' => $e->getMessage(),
            ]);
          }
        }
        else {
          $this->logDebug('Not all parts completed for job item @job_item_id. Waiting for parts: @parts', [
            '@job_item_id' => $job_item->id(),
            '@parts' => implode(', ', $incomplete_parts),
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logError('Error during job item completion aggregation check: @error', [
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Unescapes HTML entities, converting escaped symbols back to original form.
   *
   * Inverse of toEntities(), converting &amp; back to &, &gt; to >, &lt; to <.
   * toEntities() implemented in tmgmt:src/Plugin/tmgmt_file/Format/Xliff.php.
   *
   * @param string $string
   *   The string containing escaped HTML entities.
   *
   * @return string
   *   The unescaped string.
   */
  protected function fromEntities($string) {
    return str_replace(['&amp;', '&gt;', '&lt;'], ['&', '>', '<'], $string);
  }

  /**
   * Parses received translation from Memsource and returns unflatted data.
   *
   * @param string $data
   *   Xliff data, received from Memsource Cloud.
   *
   * @return array|false
   *   Unflatted data or FALSE if parsing fails.
   */
  protected function parseTranslationData($data) {
    // Check if data is empty.
    if (empty($data)) {
      $this->logError('Invalid input provided to parseTranslationData: Data is empty.');
      return FALSE;
    }

    // Check if data is not a string.
    if (!is_string($data)) {
      $this->logError('Invalid input provided to parseTranslationData: Data is not a string.');
      return FALSE;
    }

    // Check if the data looks like XML.
    $trimmed_data = trim($data);
    $is_xml = (substr($trimmed_data, 0, 5) === '<?xml' || substr($trimmed_data, 0, 1) === '<');

    if (!$is_xml) {
      $this->logError('Data does not appear to be XML.');

      // Check if it's a binary file.
      if (substr($data, 0, 2) === 'PK') {
        $this->logError('Data appears to be a ZIP file (starts with PK). This is likely a binary file, not XLIFF XML.');
      }
    }

    // Simple check for XLIFF content.
    $is_binary = FALSE;

    // Check if the content looks like XLIFF.
    if (is_string($data) && !preg_match('/<\?xml|<xliff|<[a-zA-Z]/s', substr($data, 0, 200))) {
      $is_binary = TRUE;
    }

    if ($is_binary) {
      $this->logError('Invalid XML data received. Data does not appear to be XLIFF XML.');
      return FALSE; // Explicitly return FALSE if it's binary data.
    }

    // Check if the XLIFF contains both source and target elements.
    if (!preg_match('/<source/i', $data)) {
      $this->logError('XLIFF data is missing required <source> elements. Preview: @preview', [
          '@preview' => substr($data, 0, 300)
      ]);
      return FALSE;
    }

    if (!preg_match('/<target/i', $data)) {
      $this->logError('XLIFF data is missing required <target> elements. Preview: @preview', [
          '@preview' => substr($data, 0, 300)
      ]);
      return FALSE;
    }

    try {
      // Check if source and target are identical by examining the raw XML.
      $identical_count = 0;
      $total_units = 0;

      // Use a simple regex to extract source and target pairs.
      // This is a basic check before full parsing.
      preg_match_all('/<source[^>]*>(.*?)<\/source>\s*<target[^>]*>(.*?)<\/target>/s', $data, $matches, PREG_SET_ORDER);

      foreach ($matches as $match) {
        $source_text = trim($match[1]);
        $target_text = trim($match[2]);
        $total_units++;

        if ($source_text === $target_text) {
          $identical_count++;
        }
      }

      if ($identical_count > 0 && $identical_count === $total_units && $total_units > 0) {
        $this->logError('CRITICAL: ALL translation units in the XLIFF have identical source and target text. No actual translation appears to have been performed.');
      }

      // Try to unescape CDATA sections with error handling.
      try {
        $unescaped = preg_replace_callback('/<!\[CDATA\[(.*?)\]\]>/s',
          function ($matches) {
            // Add check for array index existence.
            if (isset($matches[1])) {
                return '<![CDATA[' . $this->fromEntities($matches[1]) . ']]>';
            }
            // Return original CDATA block if index 1 is not set (should not happen with valid regex)
            return $matches[0];
          },
          $data
        );


        if ($unescaped === null) {
          // preg_replace_callback returned null, which means an error occurred.
          $preg_error = preg_last_error();
          $error_message = 'Unknown error';

          // Map error code to message.
          switch ($preg_error) {
            case PREG_BACKTRACK_LIMIT_ERROR:
              $error_message = 'Backtrack limit error';
              break;
            case PREG_RECURSION_LIMIT_ERROR:
              $error_message = 'Recursion limit error';
              break;
            case PREG_BAD_UTF8_ERROR:
              $error_message = 'Bad UTF-8 error';
              break;
            case PREG_BAD_UTF8_OFFSET_ERROR:
              $error_message = 'Bad UTF-8 offset error';
              break;
            case PREG_JIT_STACKLIMIT_ERROR:
              $error_message = 'JIT stack limit error';
              break;
          }

          $this->logError('PREG error during CDATA unescaping: @error (code: @code)', [
            '@error' => $error_message,
            '@code' => $preg_error
          ]);

          // Use original data as fallback.
          $unescaped = $data;
        }
      }
      catch (\Exception $e) {
        $this->logError('Exception during CDATA unescaping: @error', [
          '@error' => $e->getMessage()
        ]);
        // Use original data as fallback.
        $unescaped = $data;
      }

      /** @var \Drupal\tmgmt_file\Format\FormatInterface $xliff_converter */
      $xliff_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');

      // Check if the data looks like a binary file (ZIP, etc.)
      if (substr($unescaped, 0, 2) === 'PK') {
        $this->logError('Data appears to be a ZIP file (starts with PK). This is likely a binary file, not XLIFF XML.');
      }

      // Import given data using XLIFF converter. Specify content is not a file.
      try {
        // Check if the data is XML.
        if (substr(trim($unescaped), 0, 5) !== '<?xml') {
          $this->logError('Data does not start with XML declaration.');
        }

        $result = $xliff_converter->import($unescaped, FALSE);

        // Add logging after import.
        if ($result === FALSE) {
          $this->logError('XLIFF converter import() returned FALSE. Parsing failed.');
          // Explicitly return FALSE.
          return FALSE;
        }

        // Check if the result is empty.
        if (empty($result)) {
          $this->logError('XLIFF converter returned empty result. No data to import.');
          return FALSE;
        }

        // ENHANCEMENT: Ensure all translation data has proper structure.
        foreach ($result as $key => $unit) {
          if (is_array($unit) && isset($unit['#translation']) && is_array($unit['#translation'])) {
            // Make sure the translation has the required fields.
            if (!isset($unit['#translation']['#text'])) {
              $this->logWarn('Translation unit @key is missing #text in #translation. Adding empty value.', [
                '@key' => $key
              ]);
              $result[$key]['#translation']['#text'] = '';
            }

            // Make sure origin is set.
            if (!isset($unit['#translation']['#origin'])) {
              $this->logWarn('Translation unit @key is missing #origin in #translation. Setting to "remote".', [
                '@key' => $key
              ]);
              $result[$key]['#translation']['#origin'] = 'remote';
            }

            // Make sure timestamp is set.
            if (!isset($unit['#translation']['#timestamp'])) {
              $this->logWarn('Translation unit @key is missing #timestamp in #translation. Setting to current time.', [
                '@key' => $key
              ]);
              $result[$key]['#translation']['#timestamp'] = \Drupal::time()->getRequestTime();
            }
          } else if (is_array($unit) && isset($unit['#text']) && !isset($unit['#translation'])) {
            // If there's a #text but no #translation, create a proper #translation structure.
            $this->logWarn('Translation unit @key has #text but no #translation structure. Creating proper structure.', [
              '@key' => $key
            ]);

            // Create a proper translation structure.
            $result[$key]['#translation'] = [
              '#text' => isset($unit['#text']) ? $unit['#text'] : '',
              '#origin' => 'remote',
              '#timestamp' => \Drupal::time()->getRequestTime()
            ];
          }
        }
      }
      catch (\Exception $e) {
        $this->logError('Exception in xliff_converter->import(): @error', [
          '@error' => $e->getMessage()
        ]);
        return FALSE;
      }

      // Check for identical source/target in the parsed data
      $identical_count = 0;
      $total_units = 0;

      // Added check to ensure $result is an array before iterating.
      if (is_array($result)) {
        foreach ($result as $key => $unit) {
          // Ensure $unit is an array and keys exist before accessing.
          if (is_array($unit) && isset($unit['#text']) && isset($unit['#translation'])) {
            $total_units++;

            // Get the translation text, handling both string and array formats.
            $translation_text = '';
            if (is_array($unit['#translation']) && isset($unit['#translation']['#text'])) {
              $translation_text = $unit['#translation']['#text'];
            } else if (is_string($unit['#translation'])) {
              $translation_text = $unit['#translation'];
            }

            if (trim($unit['#text']) === trim($translation_text)) {
              $identical_count++;
            }
          }
        }

        if ($identical_count === $total_units && $total_units > 0) {
          $this->logError('CRITICAL: ALL parsed translation units have identical source and target text. No actual translation appears to have been performed.');

          // Store this information in the result for later use.
          $result['_meta'] = [
            'all_translations_identical' => TRUE,
            'identical_count' => $identical_count,
            'total_units' => $total_units
          ];
        }
      }

      return $result;
    }
    catch (\Exception $e) {
      $this->logError('Exception during XLIFF parsing: @error', [
          '@error' => $e->getMessage()
      ]);
      return FALSE; // Return FALSE on any exception during parsing.
    }
  }

  /**
   * Check that given status represents completed state.
   *
   * @param string $status
   *   Given status.
   *
   * @return bool
   *   Return true if status is conmleted.
   */
  public function remoteTranslationCompleted($status) {
    return in_array($status, ['COMPLETED_BY_LINGUIST', 'COMPLETED', 'DELIVERED']);
  }

  /**
   * Process mappings for a job item, fetching statuses and processing completed parts.
   *
   * This method handles the common functionality between fetchTranslatedFiles and
   * pullRemoteTranslation methods. It fetches the status for all job parts,
   * updates the TmsState in the mappings, processes completed parts, and checks
   * if all mappings are complete.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item to process.
   * @param array $errors
   *   An array to collect error messages (passed by reference).
   * @param bool $update_tms_status
   *   Whether to update the job status in Phrase TMS if configured.
   *
   * @return int
   *   The number of processed parts.
   */
  private function processJobItemMappings(JobItemInterface $job_item, array &$errors = [], $update_tms_status = FALSE) {
    $job = $job_item->getJob();
    $mappings = RemoteMapping::loadByLocalData($job->id(), $job_item->id());
    $processed_count = 0;

    if (empty($mappings)) {
      return 0;
    }

    // Phase 1: Fetch status for all job parts and update the TmsState in the mappings.
    $job_part_statuses = [];

    foreach ($mappings as $mapping) {
      $job_uid = $mapping->getRemoteIdentifier3();
      $project_id = $mapping->getRemoteIdentifier2();

      try {
        $info = $this->sendApiRequest("/api2/v1/projects/$project_id/jobs/$job_uid");

        if (isset($info['status'])) {
          $job_part_statuses[$job_uid] = [
            'status' => $info['status'],
            'mapping' => $mapping,
            'project_id' => $project_id,
          ];

          // Update the TmsState using the Entity API's standard method.
          try {
            $mapping->removeRemoteData('TmsState');
            $mapping->addRemoteData('TmsState', $info['status']);
            $mapping->save();

            $this->logDebug('Successfully updated TmsState to @state using Entity API for mapping @map_id', [
              '@state' => $info['status'],
              '@map_id' => $mapping->id(),
            ]);
          } catch (\Exception $e) {
            $this->logError('Exception during TmsState update for mapping @map_id: @error', [
              '@map_id' => $mapping->id(),
              '@error' => $e->getMessage(),
            ]);
          }

          $this->logDebug('Updated TmsState for job part @job_part to @status', [
            '@job_part' => $job_uid,
            '@status' => $info['status'],
          ]);
        }
      }
      catch (TMGMTException $e) {
        $job->addMessage('Error fetching job part @job_part for job item @job_item: @error', [
          '@job_part' => $job_uid,
          '@job_item' => $job_item->label(),
          '@error' => $e->getMessage(),
        ], 'error');
        $errors[] = 'Phrase TMS job ' . $job_uid . ' not found, it was probably deleted.';
      }
    }

    // Phase 2: Process completed job parts.
    $item_processed_flag = FALSE; // Track if any part was processed.
    foreach ($job_part_statuses as $job_uid => $info) {
      $status = $info['status'];
      $mapping = $info['mapping'];
      $project_id = $info['project_id'];

      if ($this->remoteTranslationCompleted($status)) {
        try {
          $this->logDebug('Processing completed job part @job_part with status @status', [
            '@job_part' => $job_uid,
            '@status' => $status,
          ]);

          // Call the enhanced addFileDataToJob method with the mapping.
          $this->addFileDataToJob($mapping, $status, $project_id, $job_uid);
          $item_processed_flag = TRUE; // Mark that we processed something.
          $processed_count++;

          // Update the job status in Phrase TMS if configured and requested.
          if ($update_tms_status && $this->translator->getSetting('memsource_update_job_status') === 1) {
            $this->sendApiRequest(
              "/api2/v1/projects/$project_id/jobs/$job_uid/setStatus",
              'POST',
              ["Content-Type" => "application/json"],
              FALSE,
              204,
              '{"requestedStatus": "DELIVERED"}'
            );
          }
        }
        catch (TMGMTException $e) {
          $job->addMessage('Error processing job part @job_part for job item @job_item: @error', [
            '@job_part' => $job_uid,
            '@job_item' => $job_item->label(),
            '@error' => $e->getMessage(),
          ], 'error');
          continue;
        }
      }
    }

    // Check Aggregation.
    if ($item_processed_flag) {
      try {
        // Use the centralized method to check if all mappings are complete.
        $all_completed = $this->checkAllMappingsComplete($job_item);

        if ($all_completed && $job_item->getTranslator()->isAutoAccept()) {
          $refreshed_job_item = \Drupal::entityTypeManager()
            ->getStorage($job_item->getEntityTypeId())
            ->load($job_item->id());
          $refreshed_job_item->acceptTranslation();
          $refreshed_job_item->save();
        }
      } catch (\Exception $e) {
        $this->logError('Error checking aggregation: @error', [
          '@id' => $job_item->id(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $processed_count;
  }

  /**
   * Checks if all mappings for a job item are complete and updates the state.
   *
   * This method checks all remote mappings associated with a job item to see if
   * they all have a completed TMS state. If so, it updates the job item state
   * to STATE_REVIEW.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item to check.
   *
   * @return bool
   *   TRUE if all mappings are complete, FALSE otherwise.
   */
  public function checkAllMappingsComplete(JobItemInterface $job_item) {
    // Get all remote mappings for this job item.
    $mappings = $job_item->getRemoteMappings();

    if (empty($mappings)) {
      $this->logWarn('No remote mappings found for job item @id', [
        '@id' => $job_item->id(),
      ]);
      return FALSE;
    }

    // Check if all mappings have a completed TMS state.
    $all_complete = TRUE;

    foreach ($mappings as $mapping) {
      // Get the raw remote_data field value.
      $m_data_field = $mapping->get('remote_data')->getValue();
      $m_data = [];

      // Check if we have any data.
      if (!empty($m_data_field) && is_array($m_data_field)) {
        // The field value is an array of values, we need the first one.
        $m_data = reset($m_data_field);
      }

      $m_state = $m_data['TmsState'] ?? '';

      if (!$this->remoteTranslationCompleted($m_state)) {
        $all_complete = FALSE;
        break;
      }
    }

    // If all mappings are complete, update the job item state.
    if ($all_complete) {
      // Only update if not already in REVIEW state.
      $old_state = $job_item->getState(); // Get state before update.
      if (!in_array($old_state, [JobItemInterface::STATE_REVIEW, JobItemInterface::STATE_ACCEPTED], TRUE)) {
        $target_job_item_state = $job_item->getTranslator()->isAutoAccept()
          ? JobItemInterface::STATE_ACCEPTED
          : JobItemInterface::STATE_REVIEW;

        try {
          // Try Entity API first.
          $job_item->setState($target_job_item_state);
          $job_item->save();

          // Reload to check if the state was updated.
          \Drupal::entityTypeManager()->getStorage('tmgmt_job_item')->resetCache([$job_item->id()]);
          $reloaded_item = \Drupal\tmgmt\Entity\JobItem::load($job_item->id());
          $entity_api_state = $reloaded_item ? $reloaded_item->getState() : NULL;

          // If Entity API failed, fall back to direct DB update.
          if (!in_array($entity_api_state, [JobItemInterface::STATE_REVIEW, JobItemInterface::STATE_ACCEPTED], TRUE)) {
            $this->logDebug('Entity API state update failed, falling back to direct DB update for job item @id', [
              '@id' => $job_item->id(),
            ]);

            // Perform the direct DB update.
            \Drupal::database()->update('tmgmt_job_item')
              ->fields(['state' => $target_job_item_state])
              ->condition('tjiid', $job_item->id())
              ->execute();
          }
        }
        catch (\Exception $e) {
          $this->logError('Exception during state update for job item @id: @error', [
            '@id' => $job_item->id(),
            '@error' => $e->getMessage(),
          ]);

          // Try direct DB update as a last resort.
          try {
            \Drupal::database()->update('tmgmt_job_item')
              ->fields(['state' => $target_job_item_state])
              ->condition('tjiid', $job_item->id())
              ->execute();

            $this->logDebug('Successfully updated job item state via direct DB update after Entity API failure');
          }
          catch (\Exception $db_e) {
            $this->logError('DB Exception updating job item state: @error', [
              '@error' => $db_e->getMessage(),
            ]);
            return FALSE;
          }
        }
      }
    }

    return $all_complete;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings() {
    return [
      'service_url' => 'https://cloud.memsource.com/web',
      'memsource_user_name' => '',
      'memsource_password' => '',
      'project_template' => '0',
      'due_date' => '',
      'group_jobs' => FALSE,
      'force_new_project' => FALSE,
      'enable_file_translation' => FALSE,
      'memsource_update_job_status' => FALSE,
      'memsource_connector_token' => NULL,
    ];
  }

  /**
   * Get version of Memsource plugin.
   *
   * @return string
   *   Current version.
   */
  private function getMemsourceModuleVersion() {
    if ($this->moduleVersion === NULL) {
      $file = dirname(dirname(dirname(dirname(__DIR__)))) . DIRECTORY_SEPARATOR . 'tmgmt_memsource.info.yml';
      try {
        $info = $this->parser->parse($file);
        $this->moduleVersion = ($info['version'] ?? '');
      }
      catch (\Exception $e) {
        $this->moduleVersion = '';
        $this->logDebug('Unable to parse tmgmt_memsource module version from info file: ' . $e->getMessage());
      }
    }

    return $this->moduleVersion;
  }

  /**
   * Gets translatable files from job item data.
   *
   * This method uses the TMGMT core service to get translatable files and then
   * also looks for file references in entity reference fields. It filters all
   * found files based on the configured allowed MIME types in TMGMT settings.
   *
   * @param array $data
   *   The data structure from a job item.
   *
   * @return array
   *   List of translatable files with their data keys, keyed by data key.
   */
  protected function getTranslatableFiles(array $data) {
    // Get the TMGMT data service.
    $data_service = \Drupal::service('tmgmt.data');

    // Get translatable files using the TMGMT core service.
    $standard_files = $data_service->getTranslatableFiles($data);

    // Get allowed MIME types from TMGMT settings.
    $allowed_mime_types = \Drupal::config('tmgmt.settings')->get('file_mimetypes');

    // If no MIME types are configured, log a warning and use our default list.
    if (empty($allowed_mime_types)) {
      $this->logWarn('No MIME types configured in TMGMT settings. Using default list of translatable MIME types.');
      $allowed_mime_types = $this->getDefaultTranslatableMimeTypes();
    }

    // Filter files by allowed MIME types and prepare the result format.
    $translatable_files = [];

    // First, process standard files found by TMGMT core service.
    foreach ($standard_files as $key => $file) {
      if ($file instanceof \Drupal\file\FileInterface) {
        $mime_type = $file->getMimeType();

        // Check if the MIME type is in the allowed list.
        if (in_array($mime_type, $allowed_mime_types)) {
          $translatable_files[$key] = [
            'datakey' => $key,
            'file' => $file,
          ];
        }
      }
    }

    // Now, look for file references in entity reference fields.
    $flattened_data = $data_service->flatten($data);

    // First, look for target_id properties in flattened data that might be file references.
    foreach ($flattened_data as $key => $item) {
      // Look for target_id properties that might be file references.
      if (isset($item['target_id']['#text']) && is_numeric($item['target_id']['#text'])) {
        $file_id = (int) $item['target_id']['#text'];

        // Skip if we've already processed this file.
        $already_processed = FALSE;
        foreach ($translatable_files as $existing_file) {
          if ($existing_file['file']->id() == $file_id) {
            $already_processed = TRUE;
            break;
          }
        }

        if ($already_processed) {
          continue;
        }

        // Try to load the file entity.
        try {
          $file = \Drupal\file\Entity\File::load($file_id);
          if ($file) {
            $mime_type = $file->getMimeType();

            // Check if the MIME type is in the allowed list.
            if (in_array($mime_type, $allowed_mime_types)) {
              $translatable_files[$key] = [
                'datakey' => $key,
                'file' => $file,
              ];
            }
          }
        }
        catch (\Exception $e) {
          unset($e); // Silently continue.
        }
      }
    }

    // Also look for file fields specifically (like field_msoffice)
    foreach ($data as $key => $value) {
      if (strpos($key, 'field_') === 0) {
        // Process each delta in the field.
        foreach (\Drupal\Core\Render\Element::children($value) as $delta) {
          if (isset($value[$delta]['target_id']['#text']) && is_numeric($value[$delta]['target_id']['#text'])) {
            $file_id = (int) $value[$delta]['target_id']['#text'];

            // Skip if we've already processed this file.
            $already_processed = FALSE;
            foreach ($translatable_files as $existing_file) {
              if ($existing_file['file']->id() == $file_id) {
                $already_processed = TRUE;
                break;
              }
            }

            if ($already_processed) {
              continue;
            }

            // Try to load the file entity.
            try {
              $file = \Drupal\file\Entity\File::load($file_id);
              if ($file) {
                $mime_type = $file->getMimeType();

                // Check if the MIME type is in the allowed list.
                if (in_array($mime_type, $allowed_mime_types)) {
                  $datakey = "$key][$delta][target_id";
                  $translatable_files[$datakey] = [
                    'datakey' => $datakey,
                    'file' => $file,
                  ];
                }
              }
            }
            catch (\Exception $e) {
              unset($e); // Silently continue.
            }
          }
        }
      }
    }

    return $translatable_files;
  }

  /**
   * Returns a default list of translatable MIME types.
   *
   * This is used as a fallback when no MIME types are configured in TMGMT settings.
   *
   * @return array
   *   Array of MIME types that are translatable by default.
   */
  protected function getDefaultTranslatableMimeTypes() {
    return [
      // Microsoft Office formats.
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // (.xlsx)
      'application/vnd.openxmlformats-officedocument.wordprocessingml.template', // (.dotx)
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // (.docx)
      'application/vnd.openxmlformats-officedocument.presentationml.presentation', // (.pptx)
      'application/vnd.ms-excel', // (.xls)
      'application/msword', // (.doc)
      'application/vnd.ms-powerpoint', // (.ppt)

      // // OpenDocument formats.
      // 'application/vnd.oasis.opendocument.text', // (.odt)
      // 'application/vnd.oasis.opendocument.spreadsheet', // (.ods)
      // 'application/vnd.oasis.opendocument.presentation', // (.odp)
    ];
  }

  /**
   * Determines if a MIME type is translatable based on TMGMT settings.
   *
   * @param string $mime_type
   *   The MIME type to check.
   *
   * @return bool
   *   TRUE if the MIME type is translatable, FALSE otherwise.
   */
  protected function isTranslatableMimeType($mime_type) {
    // Get allowed MIME types from TMGMT settings.
    $allowed_mime_types = \Drupal::config('tmgmt.settings')->get('file_mimetypes');

    // If no MIME types are configured, use our default list.
    if (empty($allowed_mime_types)) {
      $allowed_mime_types = $this->getDefaultTranslatableMimeTypes();
    }

    return in_array($mime_type, $allowed_mime_types, TRUE);
  }

  /**
   * Log ERROR message.
   */
  private function logError($message, array $context = []) {
    $context['%actionId'] = $this->getMemsourceActionId();
    \Drupal::logger('tmgmt_memsource')->error($message . " \nmemsource-action-id=%actionId", $context);
  }

  /**
   * Log WARN message if debug mode enabled.
   */
  private function logWarn($message, array $context = []) {
    if ($this->isDebugEnabled()) {
      $context['%actionId'] = $this->getMemsourceActionId();
      \Drupal::logger('tmgmt_memsource')->warning($message . " \nmemsource-action-id=%actionId", $context);
    }
  }

  /**
   * Log DEBUG message if debug mode enabled.
   */
  private function logDebug($message, array $context = []) {
    if ($this->isDebugEnabled()) {
      $context['%actionId'] = $this->getMemsourceActionId();
      \Drupal::logger('tmgmt_memsource')->debug($message . " \nmemsource-action-id=%actionId", $context);
    }
  }

  /**
   * Creates a translation for a source file using the TMGMT core service.
   *
   * This method delegates to the TMGMT core service to create a translated file
   * with a consistent naming pattern: original_name_targetlang.ext (e.g., document_de.docx)
   *
   * @param \Drupal\file\FileInterface $source_file
   *   The source file entity.
   * @param string $target_langcode
   *   The target language code.
   * @param string $translation_content
   *   The raw content of the translated file.
   * @param string|null $destination
   *   Optional destination URI.
   *
   * @return \Drupal\file\FileInterface|null
   *   The created file entity or NULL on failure.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   Thrown when file creation fails.
   */
  protected function createFileTranslation(\Drupal\file\FileInterface $source_file, string $target_langcode, string $translation_content, string $destination = NULL) {
    $this->logDebug('Creating translated file for source file @filename (FID: @fid) using TMGMT core service', [
      '@filename' => $source_file->getFilename(),
      '@fid' => $source_file->id(),
    ]);

    try {
      // Use the TMGMT core service to create the translated file.
      $data_service = \Drupal::service('tmgmt.data');
      $target_file = $data_service->createFileTranslation(
        $source_file,
        $target_langcode,
        $translation_content,
        $destination
      );

      if (!$target_file) {
        throw new TMGMTException('TMGMT core service returned NULL when creating file translation for @filename', [
          '@filename' => $source_file->getFilename(),
        ]);
      }

      $this->logDebug('Successfully created translated file @uri (FID: @fid) using TMGMT core service', [
        '@uri' => $target_file->getFileUri(),
        '@fid' => $target_file->id(),
      ]);

      return $target_file;
    }
    catch (\Exception $e) {
      $this->logError('Exception in createFileTranslation: @error', [
        '@error' => $e->getMessage(),
      ]);

      if ($e instanceof TMGMTException) {
        throw $e;
      }

      throw new TMGMTException('Failed to create file translation: @error', [
        '@error' => $e->getMessage(),
      ], 0, $e);
    }
  }

  /**
   * Ensures a response is properly decoded from JSON.
   *
   * This helper method provides consistent JSON decoding with error handling.
   * It will attempt to decode the response if it's a string, or return the
   * original response if it's already an array/object.
   *
   * @param mixed $response
   *   The response to decode, either a JSON string or already decoded data.
   *
   * @return array|object
   *   The decoded response data.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   If the response cannot be decoded as valid JSON.
   */
  protected function ensureDecodedResponse($response) {
    // If response is already an array or object, return as is.
    if (is_array($response) || is_object($response)) {
      return $response;
    }

    // If it's a string, try to decode it.
    if (is_string($response)) {
      return $this->decodeJson($response);
    }

    // If we get here, the response is neither an array/object nor a string
    throw new TMGMTException('Invalid response format: expected JSON string, array, or object.');
  }

  /**
   * Decodes a JSON string with robust error handling.
   *
   * @param string $json
   *   The JSON string to decode.
   *
   * @return array|object
   *   The decoded JSON data.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   *   If the JSON string cannot be decoded.
   */
  protected function decodeJson($json) {
    if (empty($json)) {
      throw new TMGMTException('Empty JSON string provided for decoding.');
    }

    // Decode with error handling.
    $data = json_decode($json, TRUE);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $error = json_last_error_msg();
      $preview = substr($json, 0, 100) . (strlen($json) > 100 ? '...' : '');

      $this->logError('JSON decode error: @error. JSON preview: @preview', [
        '@error' => $error,
        '@preview' => $preview,
      ]);

      throw new TMGMTException('Failed to decode JSON response: ' . $error);
    }

    return $data;
  }

  /**
   * Check status of debug mode.
   *
   * @return bool
   *   Returns TRUE of debug mode is enabled, FALSE otherwise.
   */
  private function isDebugEnabled() {
    return \Drupal::configFactory()->get('tmgmt_memsource.settings')->get('debug');
  }

  /**
   * Returns generated MemsourceActionId, unique per instance.
   *
   * @return string|null
   *   Returns generated memsource action id
   */
  public function getMemsourceActionId(): string {
    if ($this->memsourceActionId === NULL) {
      $this->memsourceActionId = sprintf("%s-%s",
        (new \DateTime('now'))->format('u'),
        bin2hex(random_bytes(6))
      );
    }

    return $this->memsourceActionId;
  }

    /**
     * @return int|null
     */
  private function resolveTargetDataItemState(string $tms_job_status, bool $auto_accept_translations) {
      if (!$this->remoteTranslationCompleted($tms_job_status)) {
          return NULL;
      }

      return $auto_accept_translations ? TMGMT_DATA_ITEM_STATE_ACCEPTED : TMGMT_DATA_ITEM_STATE_TRANSLATED;
  }

}
