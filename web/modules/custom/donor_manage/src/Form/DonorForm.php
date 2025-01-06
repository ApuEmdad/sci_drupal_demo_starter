<?php

namespace Drupal\donor_manage\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class DonorForm extends FormBase {

  public function getFormId() {
    return 'donor_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $donation = NULL) {
    // Fetch the donation node by ID.
    $donation_node = Node::load($donation);
    $donation_id = $donation_node ? $donation_node->id() : NULL;

    // Build the form.
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
    ];

    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
    ];

    $form['address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Address'),
    ];

    $form['donation_id'] = [
      '#type' => 'hidden',
      '#value' => $donation_id, // Automatically set from the donation node.
    ];

    $form['amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount'),
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Donate Now'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Save donor details to the database.
    \Drupal::database()->insert('donor')
      ->fields([
        'name' => $values['name'],
        'email' => $values['email'],
        'address' => $values['address'],
        'donation_id' => $values['donation_id'], // Automatically saved.
        'amount' => $values['amount'],
      ])
      ->execute();

    $this->messenger()->addMessage($this->t('Thank you for your donation!'));
  }
}
