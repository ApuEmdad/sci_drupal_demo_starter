<?php

namespace Drupal\donor_manage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;

class DonorController extends ControllerBase {
  public function donor()
  {
    return [
      '#markup' => $this->t('This is the donor form page.'),
    ];
  }
  public function donorDataTable() {
    // Fetch donor data.
    $query = Database::getConnection()->select('donor_data', 'd');
    $query->fields('d', ['donation_id', 'donor_name', 'email', 'address', 'amount', 'created']);
    $donors = $query->execute()->fetchAll();

    // Define table headers.
    $header = [
      $this->t('Donor Name'),
      $this->t('Email Address'),
      $this->t('Address'),
      $this->t('Amount Donated'),
      $this->t('Date'),
    ];

    // Build table rows.
    $rows = [];
    foreach ($donors as $donor) {
      $rows[] = [
        $donor->donor_name,
        $donor->email,
        $donor->address,
        $donor->amount,
        \Drupal::service('date.formatter')->format($donor->created, 'short'),
      ];
    }

    return [
      '#theme' => 'donor_manage_table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No donor data found.'),
    ];
  }
}
