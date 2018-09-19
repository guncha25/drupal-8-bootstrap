<?php

namespace Codeception\Module;

use Codeception\Configuration;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\DrupalKernel;
use Faker\Factory as Faker;

/**
 * Class DrupalBootstrap.
 */
class DrupalBootstrap extends Module {

  /**
   * A list of user ids created during test suite.
   *
   * @var array
   */
  protected $users;

  /**
   * DrupalBootstrap constructor.
   */
  public function __construct(ModuleContainer $container, $config = NULL) {
    $this->config = array_merge(
      [
        'drupal_root' => Configuration::projectDir() . 'web',
        'site_path' => 'sites/default',
        'check_logs' => FALSE,
      ],
      (array) $config
    );

    $_SERVER['SERVER_PORT'] = NULL;
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SERVER_SOFTWARE'] = NULL;
    $_SERVER['HTTP_USER_AGENT'] = NULL;
    $_SERVER['PHP_SELF'] = $_SERVER['REQUEST_URI'] . 'index.php';
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'];
    $_SERVER['SCRIPT_FILENAME'] = $this->config['drupal_root'] . '/index.php';
    if (isset($this->config['http_host'])) {
      $_SERVER['HTTP_HOST'] = $this->config['http_host'];
    }
    $request = Request::createFromGlobals();
    $autoloader = require $this->config['drupal_root'] . '/autoload.php';
    $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
    $kernel->boot();
    $kernel->prepareLegacyRequest($request);
    parent::__construct($container);
  }

  /**
   * Setup Test environment.
   */
  public function _beforeSuite($settings = []) {
    if ($this->config['check_logs'] && \Drupal::moduleHandler()->moduleExists('dblog')) {
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

    if ($this->config['check_logs']) {
      if (\Drupal::moduleHandler()->moduleExists('dblog')) {
        // @todo Make log levels configurable
        // Load any database log entries of level WARNING or more serious.
        $query = \Drupal::database()->select('watchdog', 'w');
        $query->fields('w', ['type', 'severity', 'message', 'variables'])
          ->condition('severity', RfcLogLevel::NOTICE, '<=')
          ->condition('type', 'php');
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
  }

  /**
   * Adds user id to user list created during test.
   *
   * @param string $uid
   *   User id.
   */
  public function addUsers($uid) {
    $this->users[] = $uid;
  }

  /**
   * Returns drupal root path.
   *
   * @return mixed
   *   Returns path.
   *
   * @deprecated
   *   Variable usage too specific.
   *   Use getConfig($key) instead.
   */
  public function getDrupalRoot() {
    return $this->getConfig('drupal_root');
  }

  /**
   * Returns nginx url variable.
   *
   * @return string
   *   Returns url.
   *
   * @deprecated
   *   Variable usage too specific.
   *   Use getConfig($key) instead.
   */
  public function getNginxUrl() {
    return $this->getConfig('nginx_url');
  }

  /**
   * Returns configuration.
   *
   * @param string $key
   *   Name of configuration variable.
   *
   * @return mixed
   *   Returns configuration variable or FALSE.
   */
  public function getConfig($key) {
    if (isset($this->config[$key])) {
      return $this->config[$key];
    }
    return FALSE;
  }

  /**
   * Create test user with specified roles.
   *
   * @param array $roles
   *   List of user roles.
   * @param mixed $password
   *   Password.
   *
   * @return \Drupal\user\Entity\User
   *   User object.
   */
  public function createUserWithRoles(array $roles = ['authenticated'], $password = FALSE) {
    $faker = Faker::create();
    /** @var \Drupal\user\Entity\User $user */
    $user = User::create([
      'name' => $faker->userName,
      'mail' => $faker->email,
      'roles' => $roles,
      'pass' => $password ? $password : $faker->password(12, 14),
      'status' => 1,
    ]);

    $user->save();

    $this->users[] = $user->id();

    return $user;
  }

  /**
   * Deletes user by username.
   *
   * @param string $username
   *   Username.
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
   * Enables module.
   *
   * @param string $module_name
   *   Module name.
   */
  public function enableModule($module_name) {
    \Drupal::service('module_installer')->install([$module_name]);
  }

}
