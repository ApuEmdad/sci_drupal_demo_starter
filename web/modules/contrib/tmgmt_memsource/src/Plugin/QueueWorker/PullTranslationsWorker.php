<?php

namespace Drupal\tmgmt_memsource\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;

/**
 * Processes queue items.
 *
 * @QueueWorker(
 *   id = "pull_translations_queue_worker",
 *   title = @Translation("Pull Translations Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class PullTranslationsWorker extends QueueWorkerBase {

  /**
   * @inheritDoc
   */
  public function processItem($data) {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = $data['job'];

    $translator = $job->getTranslator();
    /** @var \Drupal\tmgmt_memsource\Plugin\tmgmt\Translator\MemsourceTranslator $translator_plugin */
    $translator_plugin = $translator->getPlugin();

    // Klik na tlacitko PULL TRANSLATIONS.
    try {
      $result = $translator_plugin->fetchTranslatedFiles($job);

      $translated = $result['translated'];
      $untranslated = $result['untranslated'];
      $job->addMessage('Cron fetched translations for @translated job items, @untranslated are not translated yet, @errors errors have occurred.', [
        '@translated' => $translated,
        '@untranslated' => $untranslated,
        '@errors' => count($result['errors']),
      ]);

      // If all is done. Additional check.
      if (!$untranslated && !count($result['errors']) && $translator->isAutoAccept()) {
        // Check if all items are finished.
        if ($job->getState() !== JobInterface::STATE_FINISHED) {
          if (!$job->isContinuous() && tmgmt_job_check_finished($job->id())) {
            // Mark the job as finished in case it is a normal job.
            $job->finished();
          }
        }
      }
    }
    catch (\Exception $e) {
      // Log error?
      $context = [
        '%actionId' => $translator_plugin->getMemsourceActionId(),
      ];
      \Drupal::logger('tmgmt_memsource')->error($e->getMessage() . " \nmemsource-action-id=%actionId", $context);
    }
  }

}
