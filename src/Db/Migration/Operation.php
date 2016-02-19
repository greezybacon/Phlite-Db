<?php
namespace Phlite\Db\Migrations;

abstract class Operation {
    /**
     * Verify if the operation is safe to perform. Return TRUE if migration
     * should continue, and FALSE otherwise.
     *
     * Parameters:
     * $router - <callable> function which will receive a model classname
     *      and return a corresponding database backend used to serve for
     *      the model's migration.
     */
    function verify($router, $direction=Migration::FORWARDS) {
        return true;
    }

    abstract function apply($router);
    abstract function revert($router); 
}
