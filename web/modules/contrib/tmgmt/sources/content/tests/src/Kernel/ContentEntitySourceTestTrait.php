<?php

namespace Drupal\Tests\tmgmt_content\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\RandomGeneratorTrait;

trait ContentEntitySourceTestTrait {

  use RandomGeneratorTrait;

  /**
   * Creates a custom content type based on default settings.
   *
   * @param $settings
   *   An array of settings to change from the defaults.
   *   Example: 'type' => 'foo'.
   * @return
   *   Created content type.
   */
  protected function drupalCreateContentType($settings = array()) {
    $name = strtolower($this->randomMachineName(8));
    $values = array(
      'type' => $name,
      'name' => $name,
      'base' => 'node_content',
      'title_label' => 'Title',
      'body_label' => 'Body',
      'has_title' => 1,
      'has_body' => 1,
    );

    $type = NodeType::create($values);
    $type->save();

    $storage = FieldStorageConfig::loadByName('node', 'body');
    if (!$storage) {
      $storage = FieldStorageConfig::create([
        'field_name' => 'body',
        'type' => 'text_with_summary',
        'entity_type' => 'node',
        'cardinality' => 1,
        'persist_with_no_fields' => FALSE,
      ]);
      $storage->save();
    }

    $field = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $type->id(),
      'label' => 'Body',
      'settings' => [
        'display_summary' => TRUE,
        'allowed_formats' => [],
      ],
    ]);
    $field->save();
    return $type;
  }

}
