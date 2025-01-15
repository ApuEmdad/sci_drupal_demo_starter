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
  public function buildForm(array $form, FormStateInterface $form_state, $donation_id = NULL): array {
    // Load the node based on the donation_id parameter.
    if ($donation_id && is_numeric($donation_id)) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($donation_id);
    } else {
      $node = NULL;
    }

    // Add a custom theme suggestion for the form.
    $form['#theme'] = 'donor_manage_form';



    if ($node instanceof \Drupal\node\Entity\Node) {
      $form['donation_info'] = [
        '#markup' => $this->t(' @title', [
          '@title' => $node->get('field_donation_title')->value,
        ]),
      ];

     // Add the donation ID as a hidden field.
     $form['donation_id'] = [
      '#type' => 'hidden',
      '#value' => $donation_id,
    ];

    $form['donor_name'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#element_validate' => ['::validateName'],
      '#attributes' => [
        'class' => ['form-control'],
        'placeholder' => $this->t('Full Name'),
        'id' => 'donor_name',
  ],
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
      '#required' => TRUE,
      '#element_validate' => ['::validateEmail'],
      '#attributes' => [
        'class' => ['form-control'],
        'placeholder' => $this->t('Email Address'),
        'id' => 'donor_email',
  ],
    ];
    $form['address'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#element_validate' => ['::validateAddress'],
      '#attributes' => [
        'class' => ['form-control'],
        'placeholder' => $this->t('Address'),
        'id' => 'donor_address',
  ],
    ];

    $form['amount'] = [
      '#type' => 'number',
      '#required' => TRUE,
      '#element_validate' => ['::validateAmount'],
      '#attributes' => [
        'class' => ['form-control'],
        'placeholder' => $this->t('Donation Amount'),
        'id' => 'donation_amount',
  ],
    ];


    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Donate'),
    ];
  }
    return $form;
  }

  /**
   * {@inheritdoc}
   */

  public function validateName(array &$element, FormStateInterface $form_state) {
    $donor_name = $form_state->getValue($element['#name']);
    if (strlen(trim($donor_name)) < 3) {
      $form_state->setError($element, $this->t('Name must be at least 3 characters long.'));
    }
  }
  public function validateEmail(array &$element, FormStateInterface $form_state) {
    $email = $form_state->getValue($element['#name']);
    if (!\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setError($element, $this->t('Please enter a valid email address.'));
    }
  }

  public function validateAddress(array &$element, FormStateInterface $form_state) {
    $address = $form_state->getValue($element['#name']);
    if (strlen(trim($address)) < 5) {
      $form_state->setError($element, $this->t('Address must be at least 5 characters long.'));
    }
  }

  public function validateAmount(array &$element, FormStateInterface $form_state) {
    $amount = $form_state->getValue($element['#name']);
    if ($amount <= 0) {
      $form_state->setError($element, $this->t('Donation amount must be greater than 0.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state){
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
      $this->messenger()->addStatus($this->t('Thank you for your donation!'), TRUE);

    }
    else {
      // Log the error and display a message if the table does not exist.
      $this->messenger()->addError($this->t('An error occurred: donor_data table does not exist. Please contact the site administrator.'));
      \Drupal::logger('donor_manage')->error('The donor_data table does not exist in the database.');
    }


    $form_state->setRedirect('donor_manage.donor', ['donation_id' => $donation_id]);

  }
}
