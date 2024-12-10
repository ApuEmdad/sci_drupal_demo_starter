<?php

namespace Drupal\employee_manager\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a block for the Employee Form.
 *
 * @Block(
 *   id = "employee_form_block",
 *   admin_label = @Translation("Employee Form Block"),
 * )
 */
class EmployeeFormBlock extends BlockBase
{

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    return \Drupal::formBuilder()->getForm('Drupal\employee_manager\Form\EmployeeForm');
  }
}
