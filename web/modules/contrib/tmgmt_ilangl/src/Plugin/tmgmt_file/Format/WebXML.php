<?php

namespace Drupal\tmgmt_ilangl\Plugin\tmgmt_file\Format;

use Drupal\Core\Messenger\MessengerTrait;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt_file\Format\FormatInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Export into Webxml.
 *
 * @FormatPlugin(
 *   id = "webxml",
 *   label = @Translation("WEBXML")
 * )
 */
class WebXML extends \XMLWriter implements FormatInterface {
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * Access control handler for the job entity.
   *
   * @var \Drupal\tmgmt\Entity\Job
   */
  protected $job;

  /**
   * The Simple XMLElement object.
   *
   * @var \SimpleXMLElement
   */
  protected $importedXML;

  /**
   * The imported translated Job Item unit.
   *
   * @var array
   */
  protected $importedTransUnits;

  /**
   * The WebXML configuration.
   *
   * @var array
   */
  protected $configuration;

  /**
   * Constructs an WebXML instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   */
  public function __construct(array $configuration) {
    $this->configuration = $configuration;
  }

  /**
   * Adds a job item to the xml export.
   *
   * @param \Drupal\tmgmt\JobItemInterface $item
   *   The job item entity.
   */
  protected function addItem(JobItemInterface $item): void {
    $this->startElement('JobItem');
    $this->writeAttribute('id', $item->id());

    $data = \Drupal::service('tmgmt.data')->filterTranslatable($item->getData());
    foreach ($data as $key => $element) {
      $this->addTranslationUnit($key, $element, $item);
    }
    $this->endElement();
  }

  /**
   * Adds a single translation unit for a data element.
   *
   * @param string $key
   *   The unique identifier for this data element.
   * @param array $element
   *   Array with the properties #text and optionally #label.
   * @param \Drupal\tmgmt\JobItemInterface $item
   *   Translation job.
   */
  protected function addTranslationUnit(string $key, array $element, JobItemInterface $item): void {
    /** @var \Drupal\tmgmt\Data $data_service */
    $data_service = \Drupal::service('tmgmt.data');

    $element_name = preg_replace(
          '/[^a-zA-Z0-9_-]+/',
          '',
          ucwords(implode('', $element['#parent_label']) ?: $key)
      );

    $key = $item->id() . $data_service::TMGMT_ARRAY_DELIMITER . $key;

    $this->startElement($element_name);
    $this->writeAttribute('id', $key);
    $this->writeAttribute('resname', $key);

    // @todo check if need add text with translation in Drupal
    $this->writeData($element['#text']);

    $this->endElement();
  }

  /**
   * Writes text according to the WebXML export settings.
   *
   * @param string $text
   *   The contents of the text.
   */
  protected function writeData(string $text): bool {
    if ($this->job->getSetting('xliff_cdata')) {
      return $this->writeCdata(trim($text));
    }

    return $this->text($text);
  }

  /**
   * {@inheritdoc}
   */
  public function export(JobInterface $job, $conditions = []): string {
    $this->job = $job;

    $this->openMemory();
    $this->setIndent(TRUE);
    $this->setIndentString('  ');
    $this->startDocument('1.0', 'UTF-8');

    $this->startElement('content');
    $this->writeAttribute('source-language', $job->getRemoteSourceLanguage());
    $this->writeAttribute('target-language', $job->getRemoteTargetLanguage());
    $this->writeAttribute('date', date('Y-m-d\Th:m:i\Z'));
    $this->writeAttribute('tool-id', 'tmgmt');
    $this->writeAttribute('job-id', $job->id());

    foreach ($job->getItems($conditions) as $item) {
      $this->addItem($item);
    }

    $this->endElement();
    $this->endDocument();
    return $this->outputMemory();
  }

  /**
   * {@inheritdoc}
   */
  public function import($imported_file = '', $is_file = TRUE): ?array {
    if ($this->getImportedXml($imported_file, $is_file)) {
      return \Drupal::service('tmgmt.data')->unflatten($this->getImportedTargets());
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateImport($imported_file, $is_file = TRUE): ?Job {
    $xml = $this->getImportedXml($imported_file, $is_file);
    if ($xml === NULL) {
      $this->messenger()->addError($this->t('The imported file is not a valid XML.'));
      return NULL;
    }

    $attributes = $xml->attributes();

    if ($attributes) {
      $attributes = reset($attributes);
    }
    else {
      $this->messenger()->addError($this->t('The imported file is missing required XML attributes.'));
      return NULL;
    }

    // Check if the job has a valid job reference.
    if (!isset($attributes['job-id'])) {
      $this->messenger()->addError($this->t('The imported file does not contain a job reference.'));
      return NULL;
    }

    // Attempt to load the job if none passed.
    $job = (Job::load((int) $attributes['job-id']));
    if (empty($job)) {
      $this->messenger()->addError(
        $this->t('The imported file job id @file_tjid is not available.', ['@file_tjid' => $attributes['job-id']])
        );
      return NULL;
    }

    // Compare source language.
    if (!isset($attributes['source-language']) || $job->getRemoteSourceLanguage() != $attributes['source-language']) {
      $job->addMessage(
        'The imported file source language @file_language does not match the job source language @job_language.',
        [
          '@file_language' => empty($attributes['source-language'])
          ? $this->t('none')
          : $attributes['source-language'],
          '@job_language' => $job->getRemoteSourceLanguage(),
        ],
        'error'
      );
      return NULL;
    }

    // Compare target language.
    if (!isset($attributes['target-language']) || $job->getRemoteTargetLanguage() != $attributes['target-language']) {
      $job->addMessage(
        'The imported file target language @file_language does not match the job target language @job_language.',
        [
          '@file_language' => empty($attributes['target-language'])
          ? $this->t('none')
          : $attributes['target-language'],
          '@job_language' => $job->getRemoteTargetLanguage(),
        ],
        'error'
      );
      return NULL;
    }

    $targets = $this->getImportedTargets();

    if (empty($targets)) {
      $job->addMessage('The imported file seems to be missing translation.', 'error');
      return NULL;
    }

    // Validation successful.
    return $job;
  }

  /**
   * Returns the simple XMLElement object.
   *
   * @param string $imported_file
   *   Path to a file or an XML string to import.
   * @param bool $is_file
   *   (optional) Whether $imported_file is the path to a file or not.
   */
  protected function getImportedXml(string $imported_file, bool $is_file = TRUE): ?\SimpleXMLElement {
    if (empty($this->importedXML)) {
      if ($is_file) {
        $imported_file = file_get_contents($imported_file);
      }

      $this->importedXML = simplexml_load_string($imported_file);
      if ($this->importedXML === FALSE) {
        $this->messenger()->addError($this->t('The imported file is not a valid XML.'));
        return NULL;
      }
    }

    return $this->importedXML;
  }

  /**
   * Returns the imported Job Item unit.
   */
  protected function getImportedTargets(): ?array {
    if (empty($this->importedXML)) {
      return NULL;
    }

    if (empty($this->importedTransUnits)) {
      foreach ($this->importedXML->JobItem->children() as $unit) {
        $this->importedTransUnits[(string) $unit['id']]['#text'] = (string) $unit;
      }
    }

    return $this->importedTransUnits;
  }

}
