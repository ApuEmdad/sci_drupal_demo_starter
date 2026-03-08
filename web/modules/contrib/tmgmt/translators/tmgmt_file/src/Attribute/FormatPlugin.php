<?php

declare(strict_types=1);

namespace Drupal\tmgmt_file\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\SourcePluginUiBase;

/**
 * Defines a FormatPlugin attribute.
 *
 * Plugin Namespace: Plugin\tmgmt_file\Format
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class FormatPlugin extends Plugin {

  /**
   * Constructs a format plugin attribute object.
   *
   * @param string $id
   *   A unique identifier for the format plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The source label.
   */
  public function __construct(
    public readonly string $id,
    public ?TranslatableMarkup $label = NULL,
  ) {}

}
