<?php
namespace Drupal\donor_manage\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\node\Entity\Node;

/**
 * Provides a block for the Donor Form.
 *
 * @Block(
 *   id = "donor_form_block",
 *   admin_label = @Translation("Donor Form Block")
 * )
 */
class DonorFormBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  // public function build() {
  //   $donation_id = \Drupal::routeMatch()->getParameter('donation_id');

  //   // Check if the donation ID corresponds to a valid node.
  //   if ($donation_id instanceof Node) {
  //     return \Drupal::service('form_builder')->getForm('Drupal\donor_manage\Form\DonorForm', $donation_id->id());
  //   }
  //   else {
  //     return [
  //       '#markup' => $this->t('Invalid donation ID or donation node not found.'),
  //     ];
  //   }
  // }
  public function build($parameters = [])
  {
    $donation_id = \Drupal::routeMatch()->getParameter('donation_id');
    
    return \Drupal::formBuilder()->getForm('Drupal\donor_manage\Form\DonorForm',  $donation_id->id());
  }


}

