<?php

namespace Drupal\employee_manager\Controller;

use Drupal\Core\Controller\ControllerBase;

class EmployeeController extends ControllerBase
{
  public function edit($id)
  {
    $form = \Drupal::formBuilder()->getForm('Drupal\employee_manager\Form\EmployeeForm', $id);
    return $form;
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
