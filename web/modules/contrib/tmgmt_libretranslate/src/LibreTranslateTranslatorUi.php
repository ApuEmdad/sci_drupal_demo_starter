<?php

namespace Drupal\tmgmt_libretranslate;

use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * LibreTranslate translator UI.
 */
class LibreTranslateTranslatorUi extends TranslatorPluginUiBase {

  /**
   * Overrides TMGMTDefaultTranslatorUIController::pluginSettingsForm().
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => t('LibreTranslate URL'),
      '#required' => TRUE,
      '#default_value' => $translator->getSetting('api_url'),
      '#description' => $this->t('Please enter your LibreTranslate URL'),
    ];
    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LibreTranslate API key'),
      '#required' => TRUE,
      '#default_value' => $translator->getSetting('api_key'),
      '#description' => $this->t('Please enter your LibreTranslate API key'),
    ];
    $form['url'] = [
      '#type' => 'hidden',
      '#default_value' => $translator->getSetting('url'),
    ];
    $form += parent::addConnectButton();
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $supported_remote_languages = $translator->getPlugin()->getSupportedRemoteLanguages($translator);
    if (empty($supported_remote_languages)) {
      $form_state->setErrorByName('settings][api_key', $this->t('The "LibreTranslate API key or URL" is not correct.'));
    }
  }

}
