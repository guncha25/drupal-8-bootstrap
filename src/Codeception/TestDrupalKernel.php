<?php
/**
 * Created by PhpStorm.
 * User: dustinleblanc
 * Date: 6/9/16
 * Time: 7:52 AM
 */

namespace Codeception;

use Drupal\Core\DependencyInjection\Container;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;

class TestDrupalKernel extends DrupalKernel
{

    /**
     * TestDrupalKernel constructor.
     */
    public function __construct($env, $class_loader, $root)
    {
        $this->root = $root;
        parent::__construct($env, $class_loader);

    }

    public function bootTestEnvironment($sitePath)
    {
        static::bootEnvironment();
        $this->setSitePath($sitePath);
        $this->loadLegacyIncludes();
        Settings::initialize($this->root, $sitePath, $this->classLoader);
        $this->boot();
    }

}