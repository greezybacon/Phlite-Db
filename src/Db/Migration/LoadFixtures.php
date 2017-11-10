<?php
namespace Phlite\Db\Migrations;

use Phlite\Db\Exception;
use Phlite\Db\Model;
use Phlite\Db\Model\Migrations\Migration;
use Phlite\Db\Transaction;

class LoadFixtures
extends Operation {
    protected $loader;
    protected $router;
    protected $verified = [];
    protected $backends = [];

    function __construct(Model\Fixture\LoaderBase $loader) {
        $this->loader = $loader;
    }
    
    function verify($router, $direction=Migration::FORWARDS) {
        // This has to be deferred until we start loading
        return true;
    }
    
    protected function verifyModel($model, $backend) {
        $class = get_class($model);
        if (isset($this->verified[$class]))
            return true;

        // Ensure the table for the model exists
        // TODO: Consider the backend for migration?
        $fields = $model->getMeta()->getFields();

        // For either direction, the table has to exist, which will require
        // at least one field. Migration cannot continue if the backing table
        // does not exist
        if (!($this->verified[$class] = count($fields) > 0))
            throw new Exception\OrmError(
                'Database table for model fixtures does not exist');

        if (!isset($this->backends[get_class($backend)])) {
            $this->backends[get_class($backend)] = $backend;
            if ($backend instanceof Transaction) {
                $backend->beginTransaction();
            }
        }

        return true;
    }

    protected function commit() {
        foreach ($this->backends as $backend) {
            if ($backend instanceof Transaction)
                if (!$backend->commit())
                    return false;
        }
        return true;
    }

    protected function rollback() {
        foreach ($this->backends as $backend) {
            if ($backend instanceof Transaction)
                if (!$backend->rollback())
                    return false;
        }
        return true;
    }

    function apply($router) {
        $loaded = 0;

        // TODO: Use a transaction
        foreach ($this->loader as $model) {
            $backend = $router(get_class($model));
            $this->verifyModel($model, $backend);
            if (!$backend->saveModel($model)) {
                $this->rollback();
                return false;
            }
            // XXX: This is code duplication. Perhaps the model should be
            //      saved with its own save() method.
            $model->__new__ = false;
            Model\ModelInstanceManager::cache($model);
            $model->__dirty__ = array();
            $loaded++;
        }

        if (!$this->commit())
            return false;

        return $loaded;
    }

    function revert($router) {
        $loaded = 0;
        // TODO: Use a transaction
        foreach ($this->loader as $model) {
            $this->verifyModel($model);
            $backend = $router(get_class($model));
            if (!$backend->deleteModel($model))
                return false;
            $loaded++;
        }
        return $loaded;
    }
}