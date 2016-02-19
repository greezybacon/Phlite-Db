<?php
namespace Phlite\Db\Backends\SQLite;

use Phlite\Db;

class Compiler
extends Db\Backends\MySQL\Compiler {
    function quote($what) {
        return sprintf('"%s"', $what);
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
        $columns = $driv->getColumnsNames();

        return $cacheable ? ($cache[$table] = $columns) : $columns;
    }
}
