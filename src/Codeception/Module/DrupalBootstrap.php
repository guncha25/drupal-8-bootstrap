<?php

namespace Helper;

use Codeception\Configuration;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\Request;
use Codeception\TestDrupalKernel;

/**
 * Class DrupalBootstrap
 */
class DrupalBootstrap extends Module {
  /**
   * A list of all of the available roles on our Drupal site.
   * @var \Drupal\Core\Entity\EntityInterface[]|static[]
   */
  protected $roles;

  /**
   * An output helper so we can add some custom output when tests run.
   * @var \Symfony\Component\Console\Output\ConsoleOutput
   */
  protected $output;

  /**
   * DrupalBootstrap constructor.
   */
  public function __construct(ModuleContainer $container, $config = null) {
    $this->config = array_merge(
      [
        'drupal_root' => Configuration::projectDir() . 'web',
        'site_path' => 'sites/test',
        'create_users' => true,
        'destroy_users' => true,
        'test_user_pass' => 'test',
      ],
      (array)$config
    );

    $autoloader = require $this->config['drupal_root'] . '/autoload.php';
    $kernel = new TestDrupalKernel('prod',$autoloader, $this->config['drupal_root']);
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    $this->output = new ConsoleOutput();

    $this->roles = Role::loadMultiple();

    parent::__construct($container);
  }

  /**
   * Setup Test environment.
   */
  public function _beforeSuite($settings = []) {

    if (\Drupal::moduleHandler()->isLoaded('dblog')) {
      // Clear log entries from the database log.
      \Drupal::database()->truncate('watchdog')->execute();
    }

    if ($this->config['create_users']) {
      $this->scaffoldTestUsers();
    }
  }

  /**
   * Tear down after tests.
   */
  public function _afterSuite() {
    if ($this->config['destroy_users']) {
      $this->tearDownTestUsers();
    }

    if (\Drupal::moduleHandler()->isLoaded('dblog')) {

      // Load any database log entries of level WARNING or more serious.
      $query = \Drupal::database()->select('watchdog', 'w');
      $query->fields('w', ['type', 'severity', 'message', 'variables']);

      $php_notices = $query->andConditionGroup()
        ->condition('severity', RfcLogLevel::NOTICE, '<=')
        ->condition('type', 'php');
      $other_warnings = $query->andConditionGroup()
        ->condition('severity', RfcLogLevel::WARNING, '<=')
        ->condition('type', 'php', '<>');
      $group = $query->orConditionGroup()
        ->condition($php_notices)
        ->condition($other_warnings);

      $query->condition($group);
      $result = $query->execute();

      foreach ($result as $row) {
        // Build a readable message and declare a failure.
        $variables = @unserialize($row->variables);
        $message = $row->type . ' - ';
        $message .= RfcLogLevel::getLevels()[$row->severity] . ': ';
        $message .= t(Xss::filterAdmin($row->message), $variables)->render();
        $this->fail($message);
      }
    }
  }

  public function getNgnixUrl(){
    return $this->config['ngnix_url'];
  }

  /**
   * Create a test user based on a role.
   *
   * @param string $role
   *
   * @return $this
   */
  public function createTestUser($role = 'administrator') {
    if ($role != 'anonymous' && !$this->userExists($role)) {
//      $this->output->writeln("creating test{$role}User...");
      User::create([
        'name' => "test{$role}User",
        'mail' => "test{$role}User@example.com",
        'roles' => [$role],
        'pass' => "test{$role}User",
        'status' => 1,
      ])->save();
    }
    return $this;
  }

  /**
   * Destroy a user that matches a test user name.
   *
   * @param $role
   * @return $this
   */
  public function destroyTestUser($role) {
    if ($role != 'anonymous') {
      $this->deleteUser("test{$role}User");
    }
    return $this;
  }

  /**
   * @param $username
   */
  public function deleteUser($username) {
    $users = \Drupal::entityQuery('user')
      ->condition("name", $username)
      ->execute();

    /** @var \Drupal\user\Entity\User $users */
    $users = User::loadMultiple($users);
    foreach ($users as $user) {
      $user->delete();
    }
  }

  /**
   * Create a test user for each role in Drupal database.
   *
   * @return $this
   */
  public function scaffoldTestUsers() {
    array_map([$this, 'createTestUser'], array_keys($this->roles));
    return $this;
  }

  /**
   * Remove all users matching test user names.
   *
   * @return $this
   */
  public function tearDownTestUsers() {
    array_map([$this, 'destroyTestUser'], array_keys($this->roles));
    return $this;
  }

  /**
   * @param $role
   * @return bool
   */
  private function userExists($role) {
    return !empty(\Drupal::entityQuery('user')
      ->condition('name', "test{$role}User")
      ->execute());
  }
}