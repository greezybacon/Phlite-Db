<?php
namespace Phlite\Db\Migrations;

use Phlite\Db\Model;
use Phlite\Db\Model\Migrations\Migration;
use Phlite\Db\Router;

class LoadFixtures
extends Operation {
    protected $loader;
    protected $router;
    protected $verified = [];

    function __construct(Model\Fixture\LoaderBase $loader) {
        $this->loader = $loader;
    }
    
    function verify($router, $direction=Migration::FORWARDS) {
        // This has to be deferred until we start loading
        return true;
    }
    
    protected function verifyModel($model) {
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
        
        return true;
    }

    function apply($router) {
        $loaded = 0;
        // TODO: Use a transaction
        foreach ($this->loader as $model) {
            $this->verifyModel($model);
            $backend = $router(get_class($model));
            if (!$backend->saveModel($model))
                return false;
            $loaded++;
        }
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