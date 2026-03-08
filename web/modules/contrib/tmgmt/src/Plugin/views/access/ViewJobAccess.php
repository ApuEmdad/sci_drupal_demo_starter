<?php

namespace Drupal\tmgmt\Plugin\views\access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsAccess;
use Drupal\views\Plugin\views\access\AccessPluginBase;
use Symfony\Component\Routing\Route;

/**
 * Access plugin that checks multiple permissions.
 */
#[ViewsAccess(
  id: 'tmgmt_job',
  title: new TranslatableMarkup('View translation jobs'),
)]
class ViewJobAccess extends AccessPluginBase {

  /**
   * List of permissions that allow view access.
   *
   * @var array
   */
  protected $permissions = ['administer tmgmt', 'create translation jobs', 'accept translation jobs'];

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Job view access');
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    foreach ($this->permissions as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterRouteDefinition(Route $route) {
    $route->setRequirement('_permission', (string) implode('+', $this->permissions));
  }
}
