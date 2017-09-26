<?php
namespace Phlite\Db\Migrations;

use Phlite\Db\Exception;
use Phlite\Db\Model\Schema;

/**
 * Migration operation to change a table for a model. The operation
 * should be constructed with the model class (for meta data access), and a
 * callable, which will receive a SchemaEditor instance to track changes
 * to be made to the model.
 */
class AlterModel
extends Operation {
    var $modelClass;
    var $editor;

    function __construct($modelClass, callable $getEdits) {
        $this->modelClass = $modelClass;
        $this->editor = new Schema\SchemaEditor($modelClass);
        $getEdits($this->editor);
    }

    function verify($router, $direction=Migration::FORWARDS) {
        # Check if table exists
        $bk = $router($this->modelClass);
        $class = $this->modelClass;
        $table = $class::getMeta('table');
        $columns = $bk->getCompiler()->inspectTable($table);
        $exists = count($columns) > 0;

        // To be altered, the table must exist (in either direction)
        // TODO: Consider fields to be changed and dropped—Ensure they exist
        return $exists;
    }

    function apply($router) {
        $bk = $router($this->modelClass);
        $class = $this->modelClass;
        $table = $class::getMeta('table');
        $compiler = $bk->getDdlCompiler();
        $statement = $compiler->compileAlter($class, $this->editor);
        $bk->execute($statement);

        $class::getMeta()->reset();
    }

    function revert($router) {
        $bk = $router($this->modelClass);
        $class = $this->modelClass;
        $table = $class::getMeta('table');
        $compiler = $bk->getCompiler();
        $statement = $compiler->compileAlter($class, $this->editor->getReverse());
        $bk->execute($statement);

        $class::getMeta()->reset();
    }
}
