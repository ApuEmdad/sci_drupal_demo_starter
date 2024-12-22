<?php

namespace Drupal\employee_manager\Controller;

use Drupal\Core\Controller\ControllerBase;

class EmployeeController extends ControllerBase
{
  public function edit($id)
  {
    $block = \Drupal::service('plugin.manager.block')
      ->createInstance('employee_form_block', []);
    $block_content = $block->build(['id' => $id]);
    return $block_content;
  }

  public function delete($id)
  {
    \Drupal::database()->delete('employee_data')
      ->condition('id', $id)
      ->execute();

    $this->messenger()->addMessage($this->t('Employee deleted successfully.'));
    return $this->redirect('<front>');
  }
}
