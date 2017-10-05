<?php
namespace Phlite\Db\Compile;

use Phlite\Db\Model\Schema;

/**
 * The DDLCompiler differs from the SqlCompiler in that it uses the visitor
 * pattern in order to support compiling arbitrary field Meta-Data
 */
abstract class DdlCompiler {
    var $columns = array();

    protected $options;
    protected $backend;

    function __construct($backend, $options=false) {
        $this->backend = $backend;
        $this->options = $options;
    }

    /**
     * Return a column definition string, or an array of two items. The
     * first being the column definition string, and the second is extra
     * constraints which should be added to the DDL statement.
     */
    abstract function visit($node);

    function compileCreate($modelClass, $fields, $constraints=array()) {
        $meta = $modelClass::getMeta();
        $extras = (bool) $constraints;
        $columns = array();
        foreach ($constraints as $name => $C) {
            $constraints[$name] = $C->getCreateSql($this);
        }
        foreach ($fields as $name => $F) {
            $coldef = $F->getCreateSql($name, $this);
            if (is_array($coldef)) {
                list($coldef, $extras) = $coldef;
                if (is_array($extras))
                    $constraints = array_merge($constraints, $extras);
                else
                    $constraints[] = $extras;
            }
            $columns[] = sprintf("%s %s", $this->quote($F->column ?? $name), $coldef);
        }
        return new Statement(sprintf('CREATE TABLE %s (%s%s)',
            $this->quote($meta['table']),
            implode(', ', $columns),
            $extras ? (', ' . implode(', ', $constraints)) : ''
        ));
    }

    function compileDrop($modelClass) {
        $meta = $modelClass::getMeta();
        return new Statement(sprintf('DROP TABLE %s',
            $this->quote($meta['table'])
        ));
    }

    function compileAlter($modelClass, Schema\SchemaEditor $editor) {
        $meta = $modelClass::getMeta();
        $changes = [];
        foreach ($editor as $name=>$change) {
            $changes[] = $this->compileFieldDescriptor($name, $change);
        }
        return new Statement(sprintf('ALTER TABLE %s %s',
            $this->quote($meta['table']), implode(', ', $changes)
        ));
    }

    abstract function compileFieldDescriptor($name, Schema\FieldDescriptor $field);

    function escape($what) {
        return $this->backend->escape($what);
    }

    function getBackend() {
        return $this->backend;
    }
}
