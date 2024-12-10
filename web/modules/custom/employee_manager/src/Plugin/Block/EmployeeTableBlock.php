<?php

namespace Drupal\employee_manager\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a block for the Employee Table.
 *
 * @Block(
 *   id = "employee_table_block",
 *   admin_label = @Translation("Employee Table Block"),
 * )
 */
class EmployeeTableBlock extends BlockBase
{

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $query = \Drupal::database()->select('employee_data', 'e');
    $query->fields('e', ['id', 'name', 'email', 'role']);
    $employees = $query->execute()->fetchAll();

    $header = [
      $this->t('Name'),
      $this->t('Email'),
      $this->t('Role'),
      $this->t('Actions'),
    ];

    $rows = [];
    foreach ($employees as $employee) {
      $rows[] = [
        $employee->name,
        $employee->email,
        $employee->role,
        [
          'data' => [
            [
              '#type' => 'link',
              '#title' => $this->t('Edit'),
              '#url' => \Drupal\Core\Url::fromRoute('employee_manager.edit', ['id' => $employee->id]),
            ],
            [
              '#markup' => ' | ', // Make the separator renderable
            ],
            [
              '#type' => 'link',
              '#title' => $this->t('Delete'),
              '#url' => \Drupal\Core\Url::fromRoute('employee_manager.delete', ['id' => $employee->id]),
            ],
          ],
        ],
      ];
    }

    return [
      '#theme' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No employees found.'),
    ];
  }
}
