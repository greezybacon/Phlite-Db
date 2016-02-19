<?php
namespace Phlite\Db\Migrations;

use Phlite\Db\Exception;

abstract class Migration {
    static $operations = array();

    const FORWARDS  = 1;
    const BACKWARDS = 2;

    /**
     * Verify that this migration can be safely applied to the database. Use
     * the passed $backend callable to fetch the backend for each model to be
     * migrated in this migration.
     */
    function verify($backend, $direction=self::FORWARDS) {
        return true;
    }

    abstract function apply($backend);

    /**
     * From the current state of the database, apply this migration in reverse.
     * That is, undo what would be done by the ::apply() method.
     */
    function revert($backend) {
        throw new Exception\OrmError('Reverting not implemented');
    }
}
