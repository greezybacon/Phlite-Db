<?php

namespace Phlite\Db;

use Phlite\Project;

class Manager {
    protected $routers = array();
    protected $backends = array();

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
        $backendClass = $info['BACKEND'] . '\Backend';
        if (!class_exists($backendClass))
            throw new \Exception($backendClass
                . ': Specified database backend does not exist');
        $this->backends[$key] = new $backendClass($info);
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

    /**
     * getBackend
     *
     * Fetch a connection object for a particular model and for a particular
     * reason. The reason code is selected from the constants defined in the
     * Router class.
     *
     * Returns:
     * (Connection) object which can handle queries for this model.
     * Optionally, the $key passed to ::addConnection() can be returned and
     * this Manager will lookup the Connection automatically.
     */
    protected function getBackend($model, $reason=Router::READ) {
        if ($model instanceof Model\ModelBase)
            $model = get_class($model);
        foreach ($this->routers as $R) {
            if ($C = $R->getBackendForModel($model, $reason)) {
                if (is_string($C)) {
                    if (!isset($this->backends[$C]) && !$this->tryAddConnection($C))
                        throw new \Exception($backend
                            . ': Backend returned from routers does not exist.');
                    $C = $this->backends[$C];
                }
                return $C;
            }
        }
        if (!isset($this->backends['default']) && !$this->tryAddConnection('default'))
            throw new \Exception("'default' database not specified");
        return $this->backends['default'];
    }

    protected function addRouter(Router $router) {
        $this->routers[] = $router;
    }

    protected function getCompiler(Model\ModelBase $model) {
        return $this->getBackend($model)->getCompiler();
    }

    /**
     * delete
     *
     * Delete a model object from the underlying database. This method will
     * also clear the model cache of the specified model so future lookups
     * would mean database lookups or NULL.
     *
     * Returns:
     * <SqlExecutor> — an instance of SqlExecutor which can perform the
     * actual execution (via ::execute())
     */
    protected static function delete(Model\ModelBase $model) {
        Model\ModelInstanceManager::uncache($model);
        $backend = static::getManager()->getBackend($model, Router::WRITE);
        $stmt = $backend->getCompiler()->compileDelete($model);
        return $backend->getDriver($stmt);
    }

    /**
     * save
     *
     * Commit model changes to the database. This method will compile an
     * insert or an update as necessary.
     *
     * Returns:
     * <SqlExecutor> — an instance of SqlExecutor which can perform the
     * actual save of the model (via ::execute()). Thereafter, query
     * ::insert_id() for an auto id value and ::affected_rows() for the
     * count of affected rows by the update (should be 1).
     */
    static function save(Model\ModelBase $model) {
        $backend = static::getManager()->getBackend($model, Router::WRITE);
        $compiler = $backend->getCompiler();
        if ($model->__new__)
            $stmt = $compiler->compileInsert($model);
        else
            $stmt = $compiler->compileUpdate($model);

        return $backend->getDriver($stmt);
    }

    /**
     * Create a table for a new model. If the table already exists, then the
     * table should be
     */
    static function migrate(Migrations\Migration $migration,
        $direction=Migrations\Migration::FORWARDS
    ) {
        $manager = static::getManager();
        $router = function($class) use ($manager) {
            return $manager->getBackend($class, Router::MIGRATE);
        };
        if (!$migration->verify($router, $direction))
            return false;
        if ($direction == Migrations\Migration::FORWARDS)
            return $migration->apply($router);
        else
            return $migration->revert($router);
    }

    // Allow "static" access to instance methods of the Manager singleton. All
    // static instance methods are hidden to allow routing through this
    // singleton handler
    static function __callStatic($name, $args) {
        $manager = static::getManager();
        return call_user_func_array(array($manager, $name), $args);
    }
}
