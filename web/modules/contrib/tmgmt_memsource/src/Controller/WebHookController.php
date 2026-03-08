<?php

namespace Drupal\tmgmt_memsource\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tmgmt\Entity\JobItem;
use function batch_process;
use function batch_set;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route controller of the remote callbacks for the tmgmt_memsource module.
 */
class WebHookController extends ControllerBase {
  use StringTranslationTrait;
  use LoggerChannelTrait;

  /**
   * The configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity;

  /**
   * Constructs a WebHookController object.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The configuration.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity
   *   The entity type manager.
   */
  public function __construct(ImmutableConfig $config, EntityTypeManagerInterface $entity) {
    $this->config = $config;
    $this->entity = $entity;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tmgmt_memsource.settings'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Handles the notifications of changes in the files states.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request to handle.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function callback(Request $request) {

    $json_content = json_decode($request->getContent());
    $jobParts = $json_content->jobParts;
    foreach ($jobParts as $jobPart) {
      $project_id = $jobPart->project->id;
      $job_part_id = $jobPart->id;
      $status = $jobPart->status;
      $workflow_level = $jobPart->workflowLevel;
      $last_workflow_level = $jobPart->project->lastWorkflowLevel;
      if (isset($project_id) && isset($job_part_id) && isset($status)) {
        // Get mappings between the job items and the file IDs, for the project.
        $remotes = RemoteMapping::loadByRemoteIdentifier('tmgmt_memsource', $project_id);
        if (empty($remotes)) {
          $this->getLogger('tmgmt_memsource')->warning('Project %id not found.', ['%id' => $project_id]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 404);
        }
        $remote = NULL;
        /** @var \Drupal\tmgmt\Entity\RemoteMapping $remote_candidate */
        foreach ($remotes as $remote_candidate) {
          if ($remote_candidate->getRemoteIdentifier3() == $job_part_id) {
            $remote = $remote_candidate;
          }
        }
        if (!$remote) {
          $this->getLogger('tmgmt_memsource')->warning('File %id not found.', ['%id' => $job_part_id]);
          return new Response(new FormattableMarkup('File %id not found.', ['%id' => $job_part_id]), 404);
        }
        if ($workflow_level != $last_workflow_level) {
          $this->getLogger('tmgmt_memsource')->warning('Workflow level %workflow_level is not the last workflow level %last_workflow_level: project %project_id, job part %job_part_id',
            [
              '%workflow_level' => $workflow_level,
              '%last_workflow_level' => $last_workflow_level,
              '%project_id' => $project_id,
              '%job_part_id' => $job_part_id,
            ]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 400);
        }
        /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
        $translator_plugin = $remote->getJob()->getTranslator()->getPlugin();
        $translator_plugin->setTranslator($remote->getJob()->getTranslator());
        if (!$translator_plugin->remoteTranslationCompleted($status)) {
          $this->getLogger('tmgmt_memsource')->warning('Invalid job part status %status: project %project_id, job part %job_part_id',
            [
              '%status' => $status,
              '%project_id' => $project_id,
              '%job_part_id' => $job_part_id,
            ]);
          return new Response(new FormattableMarkup('Project %id not found.', ['%id' => $project_id]), 400);
        }

        $job = $remote->getJob();
        $job_item = $remote->getJobItem();

        // Update the TmsState in the mapping.
        $remote->removeRemoteData('TmsState');
        $remote->addRemoteData('TmsState', $status);
        $remote->save();

        try {
          // Call the enhanced addFileDataToJob method with the mapping.
          $translator_plugin->addFileDataToJob($remote, $status, $project_id, $job_part_id);

          // Check if all mappings for this job item are complete.
          $this->checkAllMappingsComplete($job_item, $translator_plugin);
        }
        catch (TMGMTException $e) {
          $job->addMessage('Error processing job part @job_part for job item @job_item: @error', [
            '@job_part' => $job_part_id,
            '@job_item' => $job_item->label(),
            '@error' => $e->getMessage(),
          ], 'error');
        }
      }
    }
    return new Response();
  }

  /**
   * Returns a no preview response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function noPreview() {
    return new Response('No preview url available for this file.');
  }

  /**
   * Pull all remote translations.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response to return.
   */
  public function pullAllRemoteTranslations() {
    $translators = Translator::loadMultiple();
    $items = [];
    $limit = 50;
    $operations = [];

    /** @var \Drupal\tmgmt\Entity\Translator $translator */
    foreach ($translators as $translator) {
      $translator_plugin = $translator->getPlugin();
      if ($translator_plugin instanceof MemsourceTranslator) {
        $query = $this->entity->getStorage('tmgmt_job')->getQuery('AND')
          ->condition('translator', $translator->id());
        $jobs = $query->accessCheck(FALSE)->execute();
        $query = $this->entity->getStorage('tmgmt_job_item')->getQuery('AND')
          ->condition('tjid', $jobs, 'IN');
        $or = $query->orConditionGroup()
          ->condition('state', JobItemInterface::STATE_ACTIVE)
          ->condition('state', JobItemInterface::STATE_REVIEW);
        $query->condition($or);
        $additionalItems = $query->accessCheck(FALSE)->execute();
        $items = array_merge($additionalItems, $items);
      }
    }

    $chunks = array_chunk($items, $limit);

    foreach ($chunks as $chunk) {
      $operations[] = [
        [self::class, 'pullRemoteTranslations'],
        [$chunk],
      ];
    }
    $batch = [
      'title' => $this->t('Pulling translations'),
      'operations' => $operations,
      'finished' => 'tmgmt_memsource_pull_translations_batch_finished',
    ];
    batch_set($batch);
    return batch_process('view.tmgmt_translation_all_job_items.page_1');
  }

  /**
   * Creates continuous job items for entity.
   *
   * Batch callback function.
   */
  public static function pullRemoteTranslations(array $items, &$context) {
    if (!isset($context['results']['translated'])) {
      $context['results']['translated'] = 0;
    }
    $translated = $context['results']['translated'];
    /** @var \Drupal\tmgmt\JobItemInterface[] $job_items */
    $job_items = JobItem::loadMultiple($items);
    foreach ($job_items as $item) {
      /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
      $translator_plugin = $item->getJob()->getTranslatorPlugin();
      $translated += $translator_plugin->pullRemoteTranslation($item);
    }
    $context['results']['translated'] = $translated;
  }

  /**
   * Checks if all mappings for a job item are complete and updates the state.
   *
   * This method delegates to the translator plugin's checkAllMappingsComplete method.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item to check.
   * @param \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin
   *   The translator plugin.
   */
  protected function checkAllMappingsComplete(JobItemInterface $job_item, MemsourceTranslator $translator_plugin) {
    // Call the translator plugin's method to check if all mappings are complete.
    // This is now a simple delegation to avoid code duplication.
    $result = $translator_plugin->checkAllMappingsComplete($job_item);

    if (!$result) {
      $this->getLogger('tmgmt_memsource')->debug('Not all mappings are complete for job item @id', [
        '@id' => $job_item->id(),
      ]);
    }
  }

}
