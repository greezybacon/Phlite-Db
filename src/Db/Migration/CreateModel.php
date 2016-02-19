<?php
namespace Phlite\Db\Migrations;

/**
 * Migration operation to create a new table for a new model. The operation
 * should be constructed with the model class (for meta data access), the
 * list of fields (which may eventually be placed directly into the model)
 * meta data), and some extra options, received as a hash array.
 *
 * Options:
 * 'drop' - <bool> if the table should be quietly dropped when migrating
 *      forward and the table already exists. Default is FALSE. That is, fail
 *      the pre-migration check if the table already exists.
 */
class CreateModel
extends Operation {
    var $modelClass;
    var $fields;
    var $options = array();

    function __construct($modelClass, array $fields, $options=array()) {
        $this->modelClass = $modelClass;
        $this->fields = $fields;
        $this->options = $options;
    }

    function verify($manager, $direction=Migration::FORWARDS) {
        # Check if table exists
        $class = $this->modelClass;
        $table = $class::getMeta('table');
        $bk = $manager->getConnection($class, Router::MIGRATE);
        $columns = $bk->getCompiler()->inspectTable($table);
        $exists = count($columns) > 0;

        if ($direction == Migration::FORWARDS) {
            if ($exists) {
                if (!$this->options['drop'])
                    return false;
                $this->revert();
            }
            return true;
        }
        // Otherwise, the table must exists to need dropping
        else return $exists;
    }

    function apply($manager) {
        $class = $this->modelClass;
        $table = $class::getMeta('table');
        $connection = $manager->getConnection($class, Router::MIGRATE);
        $compiler = $connection->getCompiler();
        $statement = $compiler->compileCreate($meta, $fields, $options);
        $connection->execute($statement);
    }

    function revert($manager) {
        $class = $this->modelClass;
        $table = $class::getMeta('table');
        $connection = $manager->getConnection($class, Router::MIGRATE);
        $compiler = $connection->getCompiler();
        $statement = $compiler->compileDrop($meta);
        $connection->execute($statement);
    }
}
