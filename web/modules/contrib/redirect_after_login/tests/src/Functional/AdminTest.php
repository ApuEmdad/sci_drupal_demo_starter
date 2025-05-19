<?php

namespace Drupal\Tests\redirect_after_login\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * Tests for admin-related functionality.
 *
 * @group redirect_after_login
 */
class AdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * The modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'redirect_after_login',
  ];

  /**
   * Account with admin-level privileges.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Account with editor-level privileges.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editorUser;

  /**
   * Account with authenticated-level privileges.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $simpleUser;

  /**
   * Role that grants admin-level privileges.
   *
   * @var \Drupal\user\RoleInterface
   */
  protected $adminRole;

  /**
   * Role that grants editor-level privileges.
   *
   * @var \Drupal\user\RoleInterface
   */
  protected $editorRole;

  /**
   * Tests access control for the admin settings path.
   */
  public function testAdminSettingsPathAccess() {
    $this->checkUserGetsCode($this->simpleUser, 403);
    $this->checkUserGetsCode($this->editorUser, 200);
    $this->checkUserGetsCode($this->adminUser, 200);
    $this->checkUserGetsCode($this->simpleUser, 403);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/people/permissions');
    $this->assertSession()->pageTextContains('Administer redirect_after_login settings');
  }

  /**
   * Checks that the given user gets a given status code.
   *
   * @param \Drupal\user\UserInterface $user
   *   User to login as, before loading the admin path.
   * @param int $status_code
   *   HTTP status code that is expected, e.g. 200.
   */
  protected function checkUserGetsCode(UserInterface $user, $status_code) {
    $this->drupalLogin($user);
    $this->drupalGet('admin/config/system/redirect');
    $this->assertSession()->statusCodeEquals($status_code);
    $this->drupalLogout();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // TODO: setup tasks here.
    $this->adminRole = Role::create([
      'id'    => 'administrator',
      'label' => 'Administrator',
    ]);
    // This role gets all permissions.
    $this->adminRole->set('is_admin', TRUE)->save();

    $this->editorRole = Role::create([
      'id'    => 'editor',
      'label' => 'Editor',
    ]);
    $this->editorRole
      ->grantPermission('administer redirect_after_login settings')
      ->save();

    $this->adminUser = $this->drupalCreateUser();
    $this->adminUser->setUsername('admin_user');
    $this->adminUser->addRole($this->adminRole->id());
    $this->adminUser->save();

    $this->editorUser = $this->drupalCreateUser();
    $this->editorUser->setUsername('editor_user');
    $this->editorUser->addRole($this->editorRole->id());
    $this->editorUser->save();

    $this->simpleUser = $this->drupalCreateUser();
  }

}
