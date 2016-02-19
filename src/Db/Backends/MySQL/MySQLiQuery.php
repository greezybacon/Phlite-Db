<?php

namespace Phlite\Db\Backends\MySQL;

use Phlite\Db;
use Phlite\Db\Exception;
use Phlite\Db\Compile\Statement;

/**
 * mysqli_query based executor which sends the escaped SQL queries to the
 * database directly rather than using the prepared statement approach. On
 * MySQL, there is absolutely no performance penalty using this method;
 * however, the datatypes returned from the database on the records are
 * either NULL or string. Therefore, this executor also employs a casting
 * feature which will create numeric types (int and float) from the strings
 * retrieved from the database for numericly typed fields.
 */
class MySQLiQuery
extends MySQLiPrepared {

    // Timing
    var $time_start;

    function execute() {
        // Lazy connect to the database
        if (!isset($this->conn))
            $this->conn = $this->backend->getConnection();

        // TODO: Detect server/client abort, pause and attempt reconnection

        $start = $this->time_start = microtime(true);

        // Drop the parameters from the query
        $sql = $this->_unparameterize();
        if (!($this->res = $this->conn->query($sql, MYSQLI_STORE_RESULT)))
            throw new Exception\InconsistentModel(
                'Unable to execute query: '.$this->conn->error.': '.$sql);

        // mysqli_query() returns TRUE for UPDATE queries and friends
        if ($this->res !== true)
            $this->_setupCast();

        $this->time_prepare = microtime(true) - $start;
        return true;
    }

    function _setupCast() {
        $fields = $this->res->fetch_fields();
        $this->types = array();
        foreach ($fields as $F) {
            $this->types[] = $F->type;
        }
    }

    function _cast($record) {
        $i=0;
        foreach ($record as &$f) {
            switch ($this->types[$i++]) {
            case MYSQLI_TYPE_DECIMAL:
            case MYSQLI_TYPE_NEWDECIMAL:
            case MYSQLI_TYPE_LONGLONG:
            case MYSQLI_TYPE_FLOAT:
            case MYSQLI_TYPE_DOUBLE:
                $f = isset($f) ? (double) $f : $f;
                break;

            case MYSQLI_TYPE_BIT:
            case MYSQLI_TYPE_TINY:
            case MYSQLI_TYPE_SHORT:
            case MYSQLI_TYPE_LONG:
            case MYSQLI_TYPE_INT24:
                $f = isset($f) ? (int) $f : $f;
                break;

            default:
                // No change (leave as string)
            }
        }
        unset($f);
        return $record;
    }

    /**
     * Remove the parameters from the string and replace them with escaped
     * values. This is reportedly faster for MySQL and equally as safe.
     */
    function _unparameterize() {
        if (!isset($this->conn))
            $this->conn = $this->backend->getConnection();
        $conn = $this->conn;
        return $this->stmt->toString(function($i) use ($conn) {
            // TODO: Detect database timezone and convert accordingly
            // for a non-naive DateTime instance
            if ($i instanceof \DateTime)
                $i = $i->format('Y-m-d H:i:s');
            elseif (is_object($i))
                $i = (string) $i;
            $q = $self->conn->real_escape($i);
            if (is_string($i))
                return "'$i'";
            return $i;
        });
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->res))
            $this->execute();
        $this->res->data_seek(0);
    }

    function fetchArray() {
        if (!isset($this->res))
            $this->execute();

        if (!($row = $this->res->fetch_assoc()))
            return $this->close();

        return $this->_cast($row);
    }

    function fetchRow() {
        if (!isset($this->res))
            $this->execute();

        if (!($row = $this->res->fetch_row()))
            return $this->close();

        return $this->_cast($row);
    }

    function close() {
        if (!$this->res)
            return;

        $total = microtime(true) - $this->time_start;
        $this->time_fetch = $total - $this->time_prepare;
        $this->stmt->log(['time'=>$total, 'fetch'=>$this->time_fetch, 'prepare'=>$this->time_prepare]);

        $this->res->close();
        $this->res = null;
    }

    function affected_rows() {
        return $this->conn->affected_rows;
    }

    function insert_id() {
        return $this->conn->insert_id;
    }
}
