<?php

namespace Drupal\employee_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\employee_manager\Controller\EmployeeController;

class EmployeeForm extends FormBase
{

  /**
   * The employee manager controller.
   *
   * @var \Drupal\employee_manager\Controller\EmployeeController
   */
  protected $employeeController;

  /**
   * {@inheritdoc}
   */
  public function __construct(EmployeeController $employeeController)
  {
    $this->employeeController = $employeeController;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('controller.employee_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'employee_manager_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL)
  {
    $employee = ['name' => '', 'email' => '', 'role' => ''];

    if ($id) {
      $employee = \Drupal::database()->select('employee_data', 'e')
        ->fields('e', ['name', 'email', 'role'])
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc() ?? $employee;
    }

    $form['id'] = [
      '#type' => 'hidden',
      '#value' => $id,
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Employee Name'),
      '#default_value' => $employee['name'],
      '#required' => TRUE,
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Employee Email'),
      '#default_value' => $employee['email'],
      '#required' => TRUE,
    ];
    $form['role'] = [
      '#type' => 'select',
      '#title' => $this->t('Employee Role'),
      '#options' => [
        'Software Engineer' => $this->t('Software Engineer'),
        'QA Engineer' => $this->t('QA Engineer'),
        'Data Analyst' => $this->t('Data Analyst'),
      ],
      '#default_value' => $employee['role'],
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $id ? $this->t('Update') : $this->t('Add'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $email = $form_state->getValue('email');
    $name = $form_state->getValue('name');

    // Validate email format.
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('The email address is not valid.'));
    }

    // Check if name length is at least 3 characters.
    if (strlen($name) < 3) {
      $form_state->setErrorByName('name', $this->t('The name must be at least 3 characters long.'));
    }

    // Ensure the role is selected.
    $role = $form_state->getValue('role');
    if (empty($role)) {
      $form_state->setErrorByName('role', $this->t('Please select a role.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $id = $form_state->getValue('id');
    $name = $form_state->getValue('name');
    $email = $form_state->getValue('email');
    $role = $form_state->getValue('role');

    // Call the controller's update method
    $this->employeeController->update($id, $name, $email, $role);

    $form_state->setRedirect('<current>');
  }
}
