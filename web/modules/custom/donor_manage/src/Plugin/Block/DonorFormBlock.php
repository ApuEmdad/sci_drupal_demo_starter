<?php
namespace Drupal\donor_manage\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a block for the donor form.
 *
 * @Block(
 *   id = "donor_form_block",
 *   admin_label = @Translation("Donor Form Block"),
 * )
 */
class DonorFormBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Load the current node from the route.
    $node = \Drupal::routeMatch()->getParameter('node');

    if ($node) {
      \Drupal::logger('donor_manage')->info('Node ID: @id, Type: @type', [
        '@id' => $node->id(),
        '@type' => $node->getType(),
      ]);

      // Check if the node is of type "donation".
      if ($node->getType() === 'donation') { // Replace 'donation' with the correct machine name.
        // Pass the node to the form.
        $form = \Drupal::formBuilder()->getForm('Drupal\donor_manage\Form\DonorForm', $node);
        return $form;
      }
    } else {
      \Drupal::logger('donor_manage')->error('No node found in route.');
    }

    // Display a fallback message.
    return [
      '#markup' => $this->t('This block is only available on donation nodes.'),
    ];
  }

}
