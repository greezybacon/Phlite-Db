<?php
namespace Phlite\Db\Migrations;

use Phlite\Db\Exception;
use Phlite\Db\Model;

abstract class Migration {
    const FORWARDS  = 1;
    const BACKWARDS = 2;

    /**
     * Verify that this migration can be safely applied to the database. Use
     * the passed $backend callable to fetch the backend for each model to be
     * migrated in this migration.
     */
    function verify($router, $direction=self::FORWARDS) {
        return true;
    }

    function apply($router) {
        foreach ($this->getOperations() as $oper) {
            $oper->apply($router);
        }
        // Dump the model structure cache
    }

    /**
     * From the current state of the database, apply this migration in reverse.
     * That is, undo what would be done by the ::apply() method.
     */
    function revert($router) {
        foreach (array_reverse($this->getOperations()) as $oper) {
            $oper->revert($router);
        }
        // Dump the model structure cache
    }

    /**
     * Fetch operations defined in this migration
     */
    abstract function getOperations();
}
