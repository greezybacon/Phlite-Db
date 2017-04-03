<?php
namespace Phlite\Db\Compile;

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
        $extras = false;
        $columns = array();
        foreach ($fields as $name => $F) {
            $coldef = $F->getCreateSql($name, $this);
            if (is_array($coldef)) {
                list($coldef, $extras) = $coldef;
                if (is_array($extras))
                    $constraints[] = $extras;
                else
                    $constraints = array_merge($constraints, $extras);
            }
            $columns[] = sprintf("%s %s", $this->quote($name), $coldef);
        }
        return new Statement(sprintf('CREATE TABLE %s (%s%s)',
            $this->quote($meta['table']),
            implode(', ', $columns),
            $extras ? (', ' . implode(', ', $constraints)) : ''
        ));
    }

    function compileDrop($modelClass) {
        return new Statement(sprintf('DROP TABLE %s',
            $this->quote($meta['table'])
        ));
    }

    function escape($what) {
        return $this->backend->escape($what);
    }
    
    function getBackend() {
        return $this->backend;
    }
}
