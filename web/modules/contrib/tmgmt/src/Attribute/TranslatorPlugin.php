<?php

declare(strict_types=1);

namespace Drupal\tmgmt\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tmgmt\TranslatorPluginUiBase;

/**
 * Defines a TranslatorPlugin attribute.
 *
 * Plugin Namespace: Plugin\tmgmt\Translator
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class TranslatorPlugin extends Plugin {

  /**
   * Constructs a translator plugin attribute object.
   *
   * @param string $id
   *   A unique identifier for the translator plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The translator label.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   The description.
   * @param array $default_settings
   *   An array with default values for this translator.
   * @param string $ui
   *   Translator UI class.
   * @param string|null $logo
   *   Path to the logo file.
   * @param bool $files
   *   Whether the translation provider supports files.
   * @param bool $map_remote_languages
   *   Whether to allow to map language codes.
   */
  public function __construct(
    public readonly string $id,
    public ?TranslatableMarkup $label = NULL,
    public ?TranslatableMarkup $description = NULL,
    public readonly array $default_settings = [],
    public readonly string $ui = TranslatorPluginUiBase::class,
    public readonly ?string $logo = NULL,
    public readonly bool $files = FALSE,
    public readonly bool $map_remote_languages = TRUE,
  ) {}

}
