<?php

namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Codeception\TestDrupalKernel;
use Faker\Factory as Faker;

/**
 * Class DrupalBootstrap
 */
class DrupalBootstrap extends Module {

  /**
   * A list of user ids created during test suite.
   * @var []
   */
  protected $users;

  /**
   * DrupalBootstrap constructor.
   */
  public function __construct(ModuleContainer $container, $config = null) {
    $this->config = array_merge(
      [
        'drupal_root' => Configuration::projectDir(),
        'site_path' => 'sites/default',
      ],
      (array)$config
    );
    $autoloader = require $this->config['drupal_root'] . '/autoload.php';
    $kernel = new TestDrupalKernel('prod',$autoloader, $this->config['drupal_root']);
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
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
  }

  /**
   * Tear down after tests.
   */
  public function _afterSuite() {

    if (isset($this->users)) {
      $users = User::loadMultiple($this->users);
      /** @var \Drupal\user\Entity\User $user */
      foreach ($users as $user) {
        $user->delete();
      }
    }

    if (\Drupal::moduleHandler()->isLoaded('dblog')) {
      // Load any database log entries of level WARNING or more serious.
      $query = \Drupal::database()->select('watchdog', 'w');
      $query->fields('w', ['type', 'severity', 'message', 'variables']);
      $php_notices = $query->andConditionGroup()
        ->condition('severity', RfcLogLevel::NOTICE, '<=')
        ->condition('type', 'php');
      $group = $query->orConditionGroup()
        ->condition($php_notices);
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

  /**
   * @return string
   */
  public function getNginxUrl() {
    if (isset($this->config['nginx_url'])) {
      return $this->config['nginx_url'];
    }
    return 'undefined';
  }

  /**
   * Create test user with specified roles
   *
   * @param array $roles
   *
   * @return \Drupal\user\Entity\User
   */
  public function createUserWithRoles($roles = ['authenticated'], $password = False) {
    $faker = Faker::create();
    /** @var \Drupal\user\Entity\User $user */
    $user = User::create([
      'name' => $faker->userName,
      'mail' => $faker->email,
      'roles' => $roles,
      'pass' => $password ? $password : $faker->password(12,14),
      'status' => 1,
    ]);

    $user->save();

    $this->users[] = $user->id();

    return $user;
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


}