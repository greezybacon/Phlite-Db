<?php
namespace Phlite\Db\Backends\SQLite;

use Phlite\Db;
use Phlite\Db\Compile\Statement;
use Phlite\Db\Fields;
use Phlite\Db\Model;

class Compiler
extends Db\Backends\MySQL\Compiler {
    function quote($what) {
        return sprintf('"%s"', $what);
    }

    function compileDelete(Model\ModelBase $model, $limit1=false) {
        return parent::compileDelete($model, false);
    }

    function compileUpdate(Model\ModelBase $model, $limit1=false) {
        return parent::compileUpdate($model, false);
    }

    // SQLite doesn't support the INSERT INTO ... SET like MySQL does
    function compileInsert(Model\ModelBase $model) {
        $pk = $model::getMeta('pk');
        $fields = array();
        foreach ($model->__dirty__ as $field=>$old)
            $fields[$this->quote($field)] = $this->input($model->get($field));
        $sql = 'INSERT INTO '.$this->quote($model::getMeta('table')).' ('
            . implode(', ', array_keys($fields)).') VALUES ('
            . implode(', ', $fields) . ')';
        return new Statement($sql, $this->params);
    }

    function inspectTable($table, $details=false, $cacheable=true) {
        static $cache = array();

        // XXX: Assuming schema is not changing — add support to track
        //      current schema
        if ($cacheable && isset($cache[$table]))
            return $cache[$table];

        $stmt = new Statement('SELECT * FROM "'.$table.'" WHERE 1=0');
        $driv = $this->conn->getDriver($stmt);
        $driv->execute();
        $columns = $driv->getColumnNames();

        return $cacheable ? ($cache[$table] = $columns) : $columns;
    }

    function getTypeName($field) {
        switch (true) {
        case $field instanceof Db\Fields\TextField:
            return 'TEXT';
        case $field instanceof Db\Fields\AutoIdField:
            return 'INTEGER PRIMARY KEY AUTOINCREMENT';
        case $field instanceof Db\Fields\IntegerField:
            return 'INTEGER';
        }
    }

    function getExtraCreateConstraints($modelClass, $fields) {
        foreach ($fields as $F) {
            if ($F instanceof Fields\AutoIdField)
                // PRIMARY KEY is required with the auto-increment token and
                // cannot be repeated
                return [];
        }
        return parent::getExtraCreateConstraints($modelClass, $fields);
    }
}
