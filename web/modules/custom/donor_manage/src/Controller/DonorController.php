<?php

namespace Drupal\donor_manage\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides methods for managing donors.
 */
class DonorController extends ControllerBase {

  /**
   * Displays the donor list page.
   */
  public function index() {
    return [
      '#markup' => $this->t('This is the donor list page.'),
    ];
  }

  /**
   * Renders the form to edit a donor.
   */
  public function edit($id) {
    $block = \Drupal::service('plugin.manager.block')
      ->createInstance('donor_form_block', []);
    return $block->build(['id' => $id]);
  }

  /**
   * Deletes a donor entry from the database.
   */
  public function delete($id) {
    \Drupal::database()->delete('donor')
      ->condition('id', $id)
      ->execute();

    $this->messenger()->addMessage($this->t('Donor deleted successfully.'));
    return $this->redirect('donor_manage.donor_list');
  }
}

