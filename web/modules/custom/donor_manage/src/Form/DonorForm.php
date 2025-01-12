<?php

declare(strict_types=1);

namespace Drupal\donor_manage\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use DrupalCodeGenerator\Command\PhpStormMeta\Database as PhpStormMetaDatabase;

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
  // public function buildForm(array $form, FormStateInterface $form_state, $donation_id = NULL): array
  // {
  //   if ($donation_id=== NULL) {
  //     $donation_id = \Drupal::routeMatch()->getParameter('donation_id');
  //   }

  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL): array {
    // Retrieve the current node from the route if not passed explicitly.
    if ($node === NULL) {
      $node = \Drupal::routeMatch()->getParameter('node');
    }

    $node_title = $node ? $node->getTitle() : $this->t('Unknown');
    $donation_id = $node ? $node->id() : NULL;
    $field_donation_title = $node->get('field_donation_title')->value;

    $form['donation_info'] = [
      '#markup' => $this->t('You are donating to : @title', [
        '@title' => $field_donation_title,
      ]),
    ];
     // Add the donation ID as a hidden field.
     $form['donation_id'] = [
      '#type' => 'hidden',
      '#value' => $donation_id,
    ];

    // Donation_id ID field (read-only).
    // $form['donation_id'] = [
    //   '#type' => 'textfield',
    //   '#title' => $this->t('Donation ID'),
    //   '#default_value' => $donation_id,
    //   '#attributes' => ['readonly' => 'readonly'], // Make the field read-only.
    // ];

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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $donation_id = $form_state->getValue('donation_id');
    $donor_name = $form_state->getValue('donor_name');
    $email = $form_state->getValue('email');
    $address = $form_state->getValue('address');
    $amount = $form_state->getValue('amount');

    $connection = \Drupal::database();

    // Check if the table exists before inserting data.
    if ($connection->schema()->tableExists('donor_data')) {
      $connection->insert('donor_data')
        ->fields([
          'donation_id' => $donation_id,
          'donor_name' => $donor_name,
          'email' => $email,
          'address' => $address,
          'amount' => $amount,
          'created' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();

      // Show a success message.
      $this->messenger()->addStatus($this->t('Thank you for your donation!'));
    }
    else {
      // Log the error and display a message if the table does not exist.
      $this->messenger()->addError($this->t('An error occurred: donor_data table does not exist. Please contact the site administrator.'));
      \Drupal::logger('donor_manage')->error('The donor_data table does not exist in the database.');
    }

    $form_state->setRedirect('donor_manage.donor', ['donation_id' => $donation_id]);

  }
}
