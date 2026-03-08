<?php

namespace Drupal\tmgmt_memsource\Cron;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\State;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\Translator;

/**
 * Cron task, for automatic pull completted translations.
 */
class PullTranslationsTask {

  /**
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * @var \Drupal\Core\State\State
   */
  protected State $state;

  /**
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   */
  public function __construct(QueueFactory $queue_factory, State $state) {
    $this->queueFactory = $queue_factory;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $trans = [];
    $translators = $this->getTranslators();
    if (empty($translators)) {
      return;
    }

    foreach ($translators as $translator) {
      $translName = $translator->id();

      // Active time window (start and end hours).
      $startTime = $translator->getSetting('memsource_cron_start_hour') ?? 8;
      $endTime = $translator->getSetting('memsource_cron_end_hour') ?? 18;
      $nowHour = date('G');
      if ($nowHour < $startTime || $nowHour >= $endTime) {
        continue;
      }

      $cronTime = $translator->getSetting('memsource_cron_time') * 60;
      $lastTime = $this->state->get('cron_las_time.' . $translName, 0);
      if ($_SERVER['REQUEST_TIME'] - $lastTime > $cronTime) {
        $trans[] = $translName;
        // Update last run.
        $this->state->set('cron_las_time.' . $translName, $_SERVER['REQUEST_TIME']);
      }
    }
    if (empty($trans)) {
      return;
    }

    foreach ($trans as $id) {
      $limit = $translators[$id]->getSetting('memsource_cron_limit');
      if (!$limit) {
        $limit = 100; // Default limit.
      }
      // Musime nalezt vsechny joby, od nejstarsiho.
      $ids = \Drupal::entityQuery('tmgmt_job')
        ->accessCheck(FALSE)
        ->condition('state', Job::STATE_ACTIVE)
        ->condition('translator', $id)
        ->sort('created')
        ->range(0, $limit)
        ->execute();

      if (!empty($ids)) {
        $queue = $this->queueFactory->get('pull_translations_queue_worker');
        $storage = \Drupal::entityTypeManager()->getStorage('tmgmt_job');
        /** @var \Drupal\tmgmt\Entity\Job[] $entities */
        $entities = $storage->loadMultiple($ids);
        foreach ($entities as $job) {
          $queue->createItem([
            'job' => $job,
            'translator' => $translators[$id],
            'timestamp' => time(),
          ]);
        }
      }
    }
  }

  /**
   * Get memsource translators with cron use set.
   *
   * @return \Drupal\tmgmt\Entity\Translator[]
   */
  protected function getTranslators() {
    static $translators = NULL;
    if ($translators === NULL) {
      foreach (Translator::loadMultiple() as $translator) {
        if ($translator->getPluginId() == 'memsource' && $translator->getSetting('memsource_cron_use')) {
          $translators[$translator->id()] = $translator;
        }
      }
    }
    return $translators;
  }

}
