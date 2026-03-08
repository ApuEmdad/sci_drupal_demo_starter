<?php

namespace Drupal\tmgmt_test\Plugin\tmgmt\Source;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\Attribute\SourcePlugin;
use Drupal\tmgmt\JobItemInterface;

/**
 * Test source plugin implementation.
 */
#[SourcePlugin(
  id: 'test_html_source',
  label: new TranslatableMarkup('Test HTML Source'),
)]
class TestHtmlSource extends TestSource {

  /**
   * {@inheritdoc}
   */
  public function getData(JobItemInterface $job_item) {
    return array(
      'dummy' => array(
        'deep_nesting' => array(
          '#text' => file_get_contents(\Drupal::service('extension.list.module')->getPath('tmgmt') . '/tests/testing_html/sample.html'),
          '#label' => 'Label for job item with type ' . $job_item->getItemType() . ' and id ' . $job_item->getItemId() . '.',
        ),
      ),
    );
  }
}
