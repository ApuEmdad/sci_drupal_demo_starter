<?php

declare(strict_types=1);

namespace Drupal\tmgmt\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\SourcePluginUiBase;

/**
 * Defines a SourcePlugin attribute.
 *
 * Plugin Namespace: Plugin\tmgmt\Source
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class SourcePlugin extends Plugin {

  /**
   * Constructs a source plugin attribute object.
   *
   * @param string $id
   *   A unique identifier for the source plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The source label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   The description.
   * @param string $ui
   *   Source UI class.
   */
  public function __construct(
    public readonly string $id,
    public ?TranslatableMarkup $label = NULL,
    public ?TranslatableMarkup $description = NULL,
    public readonly string $ui = SourcePluginUiBase::class,
  ) {}

}
