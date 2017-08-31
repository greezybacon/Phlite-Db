<?php

namespace Phlite\Db;

use Phlite\Project;

class Manager {
    protected $routers = array();
    protected $backends = array();
    protected $session;

    const READ    = 1;
    const WRITE   = 2;
    const MIGRATE = 3;

    static function getManager() {
        static $manager;

        if (!isset($manager)) {
            $manager = new Manager();
        }
        return $manager;
    }

    protected function addConnection(array $info, $key='default') {
        if (!isset($info['BACKEND']))
            throw new \Exception("'BACKEND' must be set in the database options.");
        $backendClass = $info['BACKEND'];
        // Allow for abbreviated database backend names (lacking the trailing \Backend)
        if (!is_subclass_of($info['BACKEND'], Backend::class))
            $backendClass .= '\Backend';
        if (!class_exists($backendClass))
            throw new \Exception($backendClass
                . ': Specified database backend class does not exist');
        $this->backends[$key] = new $backendClass($info);
    }

    protected function removeConnection($key) {
        $bk = $this->backends[$key];
        $bk->close();
        unset($this->backends[$key]);
    }

    /**
     * tryAddConnection
     *
     * Attempt to add a new connection, by name, from the current project's
     * configuration settings.
     *
     * Returns:
     * <bool> TRUE upon success.
     */
    protected function tryAddConnection($key) {
        $databases = Project::getCurrent()->getSetting('DATABASES');
        if ($databases && is_array($databases) && isset($databases[$key])) {
            $this->addConnection($databases[$key], $key);
            return true;
        }
    }

    protected function getConnection($key) {
        if (!isset($this->backends[$key]) && !$this->tryAddConnection($key))
            throw new \Exception($backend
                . ': Backend returned from routers does not exist.');
        return $this->backends[$key];
    }

    /**
     * Perform a database migration. The migration can be played forwards or
     * backwards (undone). This might include creating a table for a new model,
     * adding, renaming, or removing a field from a table, loading initial
     * data, or running some PHP code to migrate existing data.
     */
    static function migrate(Migrations\Migration $migration,
        $direction=Migrations\Migration::FORWARDS
    ) {
        $router = function($class) {
            return Router::getBackend($class, Router::MIGRATE);
        };
        if (!$migration->verify($router, $direction))
            return false;
        if ($direction == Migrations\Migration::FORWARDS)
            return $migration->apply($router);
        else
            return $migration->revert($router);
    }

    protected function getSession() {
        // XXX: Should this session persist? Or be something the caller
        //      keeps up with?
        if (!isset($this->session))
            $this->session = new Session();
        return $this->session;
    }

    // Allow "static" access to instance methods of the Manager singleton. All
    // static instance methods are hidden to allow routing through this
    // singleton handler
    static function __callStatic($name, $args) {
        $manager = static::getManager();
        return call_user_func_array(array($manager, $name), $args);
    }
}
