<?php

namespace Drupal\employee_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Database\Connection;

class EmployeeController extends ControllerBase
{

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new EmployeeController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   */
  public function __construct(Connection $database, MessengerInterface $messenger)
  {
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * Deletes an employee.
   *
   * @param int $id
   *   The employee ID.
   *
   * @return \Drupal\Core\GeneratedUrl
   */
  public function delete($id)
  {
    $this->database->delete('employee_data')
      ->condition('id', $id)
      ->execute();

    $this->messenger->addMessage($this->t('Employee deleted successfully.'));
    return $this->redirect('<front>');
  }

  /**
   * Updates or adds an employee record.
   *
   * @param int $id
   *   The employee ID.
   * @param string $name
   *   The employee name.
   * @param string $email
   *   The employee email.
   * @param string $role
   *   The employee role.
   */
  public function update($id, $name, $email, $role)
  {
    $employee = [
      'name' => $name,
      'email' => $email,
      'role' => $role,
    ];

    if ($id) {
      // Update existing employee
      $this->database->update('employee_data')
        ->fields($employee)
        ->condition('id', $id)
        ->execute();

      $this->messenger->addMessage($this->t('Employee updated successfully.'));
    } else {
      // Add new employee
      $this->database->insert('employee_data')
        ->fields($employee)
        ->execute();

      $this->messenger->addMessage($this->t('Employee added successfully.'));
    }

    return $this->redirect('<current>'); // Redirect back to the current page
  }
}
