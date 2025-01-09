<?php

declare(strict_types=1);

namespace Drupal\donor_manage\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a DonorForm form.
 */
final class DonorForm extends FormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'donor_manage_donor';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $donation_id = NULL): array
  {
    $form['donation_id'] = [
      '#type' => 'item',
      '#title' => $this->t('Donation ID: @id', ['@id' => $donation_id]),
    ];

    $form['hidden_donation_id'] = [
      '#type' => 'hidden',
      '#value' => $donation_id,
    ];
    $form['donor_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Donor Name'),
      '#required' => TRUE,
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#required' => TRUE,
    ];
    $form['address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address'),
      '#required' => TRUE,
    ];

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount Donated'),
      '#required' => TRUE,
    ];


    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Donate'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $formField = $form_state->getValues();
    $donation = trim($formField['donation_id']);
    $donor_name = trim($formField['donor_name']);
    $email = trim($formField['email']);
    $address = trim($formField['address']);
    $amount = trim($formField['amount']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('The email address is not valid.'));
    }

    // Check if name length is at least 3 characters.
    if (strlen($donor_name) < 3) {
      $form_state->setErrorByName('donor_name', $this->t('The name must be at least 3 characters long.'));
    }
    // Validate donation ID is not empty.
    if (empty($donation)) {
      $form_state->setErrorByName('donation_id', $this->t('The donation ID cannot be empty.'));
    }

    // Validate address is not empty and has a minimum length.
    if (empty($address) || strlen($address) < 10) {
      $form_state->setErrorByName('address', $this->t('The address must be at least 10 characters long.'));
    }

    // Validate amount is a valid number and greater than 0.
    if (!is_numeric($amount) || $amount <= 0) {
      $form_state->setErrorByName('amount', $this->t('The donation amount must be a positive number.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $this->messenger()->addStatus($this->t('The message has been sent.'));
    $form_state->setRedirect('/donor-form/{donation}');
  }
}
