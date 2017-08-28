<?php

namespace Phlite\Db;

use Phlite\Project;

class Manager {
    protected $routers = array();
    protected $backends = array();
    protected $transaction;

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

    /**
     * getBackend
     *
     * Fetch a connection object for a particular model and for a particular
     * reason. The reason code is selected from the constants defined in the
     * Router class.
     *
     * Parameters:
     * $model - (string) model class (fully qualified) for which a database 
     *      backend is requested. Class should extend from ModelBase. A 
     *      ModelBase instance can also be passed.
     * $reason - (int) type of activity for which a database connection is
     *      requestd. Defaults to Router::READ. Useful if backend routers use
     *      varying backends based on read/write activity.
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

    /**
     * Perform a database migration. The migration can be played forwards or
     * backwards (undone). This might include creating a table for a new model,
     * adding, renaming, or removing a field from a table, loading initial
     * data, or running some PHP code to migrate existing data.
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

    /**
     * Fetch the current transaction optionally beginning a new transaction
     * if not already started.
     */
    protected function getTransaction($autostart=true, $flags=0) {
        if (!isset($this->transaction) && $autostart)
            $this->transaction = $this->openTransaction($flags);
        return $this->transaction;
    }

    /**
     * Add a model to a new or current transaction. The model will be
     * inserted or updated depending on whether or not the model is marked
     * as new. The transaction will need to be flushed before the model
     * will be sent to the database, and a commit will need to be made
     * before the change will be permanent.
     */
    protected function add(Model\ModelBase $model, $callback=null, $args=null) {
        return $this->getTransaction()->add($model, $callback, $args);
    }

    /**
     * Delete a model inside the current transaction. The model will be
     * marked as deleted immediately, but will be deleted from the database
     * when the transaction is flushed. It will be permanently removed after
     * the transaction is committed.
     */
    protected function remove(Model\ModelBase $model, $callback=null, $args=null) {
        return $this->getTransaction()->delete($model, $callback, $args);
    }

    /**
     * Start all model updates in a transaction. All future calls to
     * ::save() and ::delete() will be placed in the transaction and
     * commited with the transaction.
     *
     * Parameters:
     * $mode - Operation mode for the transaction. See the FLAG_* flags on
     *      the TransactionCoordinator class for valid flag settings.
     *
     * Returns:
     * <TransactionCoordinatory> newly created transaction
     */
    protected function openTransaction($mode=0) {
        if (isset($this->transaction))
            throw new Exception\OrmError(
                'Transaction already started. Use `commit` or `rollback` to complete the current transaction before staring a new one');

        $this->transaction = new TransactionCoordinator($this, $mode);
        return $this->transaction;
    }

    protected function flush() {
        if ($this->transaction)
            return $this->transaction->flush();
    }

    /**
     * Commit the current transaction. Transactions must be started with
     * ::beginTransaction(). Transactions are automatically coordinated
     * among several databases where supported.
     */
    protected function commit() {
        if (!isset($this->transaction))
            throw new Exception\OrmError('Transaction not started');

        $rv = $this->transaction->commit();
        unset($this->transaction);
        return $rv;
    }

    protected function rollback() {
        if (!isset($this->transaction))
            throw new Exception\OrmError('Transaction not started');

        $rv = $this->transaction->rollback();
        unset($this->transaction);

        // XXX: The internal model cache will likely contain references to
        // model objects whose changes were rolled back.

        return $rv;
    }

    protected function retry($transaction=null) {
        $transaction = $transaction ?: $this->transaction;
        if (!isset($transaction))
            throw new Exception\OrmError('No transaction specified to be retried');

        $transaction->retry($this);
    }
    

    // Allow "static" access to instance methods of the Manager singleton. All
    // static instance methods are hidden to allow routing through this
    // singleton handler
    static function __callStatic($name, $args) {
        $manager = static::getManager();
        return call_user_func_array(array($manager, $name), $args);
    }
}
