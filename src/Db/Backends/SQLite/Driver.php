<?php
namespace Phlite\Db\Backends\SQLite;

use Phlite\Db;
use Phlite\Db\Compile\SqlDriver;
use Phlite\Db\Exception;
use Phlite\Db\Compile\Statement;

class Driver
implements SqlDriver {
    var $stmt;
    var $fields = array();

    var $backend;
    var $conn;
    var $dbstmt;
    var $cursor;   // Server resource / cursor

    function __construct(Statement $stmt, Db\Backend $bk) {
        $this->stmt = $stmt;
        $this->backend = $bk;
    }

    function __destruct() {
        $this->close();
    }

    function execute() {
        // Lazy connect to the database
        if (!isset($this->conn))
            $this->conn = $this->backend->getConnection();

        if (!($this->dbstmt = $this->conn->prepare($this->stmt->sql))) {
            throw new Exception\InconsistentModel(
                sprintf('Unable to prepare query: %s: %s',
                    substr($this->stmt->sql, 0, 400),
                    $this->conn->lastErrorMsg()));
        }

        // TODO: Implement option to drop parameters

        if ($this->stmt->hasParameters())
            $this->bind($this->dbstmt);
        if (!($this->cursor = $this->dbstmt->execute())) {
            throw new Exception\DbError('Unable to execute query: '
                . $this->conn->lastErrorMsg());
        }

        return true;
    }

    protected function bind($res) {
        $params = $this->stmt->getParameters();
        if (count($params) != $res->paramCount())
            throw new Exception\OrmError('Parameter count does not match query');

        $types = '';
        $ps = array();
        foreach ($params as $idx=>$p) {
            $name = ":" . ($idx+1);
            switch (true) {
            case is_int($p) || is_bool($p):
                $type = \SQLITE3_INTEGER;
                break;
            case is_float($p):
                $type = \SQLITE3_FLOAT;
                break;
            case $p instanceof \DateTime:
                $p = $p->format('Y-m-d H:i:s');
            case is_object($p):
                $p = (string) $p;
            case is_string($p):
                $type = \SQLITE3_BLOB;
                break;
            default:
                // FIXME: Emit error if param is null
            }
            $res->bindValue($name, $p, $type);
        }
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->cursor))
            $this->execute();
        $this->cursor->reset();
    }

    function fetchArray() {
        if (!isset($this->cursor))
            $this->execute();

        return $this->cursor->fetchArray(\SQLITE3_ASSOC) ?: false;
    }

    function fetchRow() {
      if (!isset($this->cursor))
          $this->execute();

      return $this->cursor->fetchArray(\SQLITE3_NUM) ?: false;
    }

    function close() {
        if (!$this->dbstmt)
            return;

        $this->dbstmt->close();
        $this->dbstmt = null;

        if ($this->cursor)
            @$this->cursor->finalize();
    }

    function affected_rows() {
        return $this->conn->changes();
    }

    function insert_id() {
        return $this->conn->lastInsertRowID();
    }

    function getColumnNames() {
        $cols = array();
        if (!isset($this->cursor))
            return $cols;

        $count = $this->cursor->numColumns();
        for ($i = 0; $i < $count; $i++)
            $cols[] = $this->cursor->columnName($i);

        return $cols;
    }
}
