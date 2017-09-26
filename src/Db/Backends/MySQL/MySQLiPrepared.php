<?php

namespace Phlite\Db\Backends\MySQL;

use Phlite\Db;
use Phlite\Db\Exception;
use Phlite\Db\Compile\SqlDriver;
use Phlite\Db\Compile\Statement;

class MySQLiExecutor
implements SqlDriver {

    var $stmt;
    var $fields = array();

    var $backend;
    var $conn;
    var $res;   // Server resource / cursor

    // Timing
    var $time_prepare;
    var $time_fetch;

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

        // TODO: Detect server/client abort, pause and attempt reconnection

        $start = microtime(true);
        list($sql, $params) = $this->fixupParams($this->stmt);
        if (!($this->res = $this->conn->prepare($sql)))
            throw new Exception\InconsistentModel(
                'Unable to prepare query: '.$this->conn->error
                .': '.$sql);

        // TODO: Implement option to drop parameters

        if (count($params))
            $this->_bind($params, $this->res);
        if (!$this->res->execute() || !$this->res->store_result()) {
            throw new Exception\DbError('Unable to execute query: ' . $this->res->error);
        }
        $this->_setup_output();

        $this->time_prepare = microtime(true) - $start;
        return true;
    }

    function fixupParams() {
        $self = $this;
        $params = array();
        $sql = preg_replace_callback("/:(\d+)/",
        function($m) use ($self, &$params) {
            $params[] = $self->stmt->params[$m[1]-1];
            return '?';
        }, $this->stmt->sql);
        return array($sql, $params);
    }

    function _bind($params, $res) {
        if (count($params) != $res->param_count)
            throw new Exception\OrmError('Parameter count does not match query');

        $types = '';
        $ps = array();
        foreach ($params as &$p) {
            if (is_int($p) || is_bool($p))
                $types .= 'i';
            elseif (is_float($p))
                $types .= 'd';
            elseif (is_string($p))
                $types .= 's';
            elseif ($p instanceof \DateTime) {
                // TODO: Detect database timezone and convert accordingly
                // for a non-naive DateTime instance
                $types .= 's';
                $p = $p->format('Y-m-d H:i:s');
            }
            elseif (is_object($p)) {
                $types .= 's';
                $p = (string) $p;
            }
            // FIXME: Emit error if param is null
            $ps[] = &$p;
        }
        unset($p);
        array_unshift($ps, $types);
        call_user_func_array(array($res, 'bind_param'), $ps);
    }

    function _setup_output() {
        if (!($meta = $this->res->result_metadata()) && $this->res->errno)
            throw new Exception\DbError(
                'Unable to fetch statment metadata: '. $this->res->error);
        if ($meta) {
            $this->fields = $meta->fetch_fields();
            $meta->free_result();
        }
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->res))
            $this->execute();
        $this->res->data_seek(0);
    }

    function next() {
        $status = $this->res->fetch();
        if ($status === false)
            throw new Exception\DbError($this->res->error);
        elseif ($status === null) {
            $this->close();
            return false;
        }
        return true;
    }

    function fetchArray() {
        $output = array();
        $variables = array();

        if (!isset($this->res))
            $this->execute();

        foreach ($this->fields as $f)
            $variables[] = &$output[$f->name]; // pass by reference

        if (!call_user_func_array(array($this->res, 'bind_result'), $variables))
            throw new Exception\OrmError('Unable to bind result: ' . $this->res->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function fetchRow() {
        $output = array();
        $variables = array();

        if (!isset($this->res))
            $this->execute();

        foreach ($this->fields as $f)
            $variables[] = &$output[]; // pass by reference

        if (!call_user_func_array(array($this->res, 'bind_result'), $variables))
            throw new Exception\OrmError('Unable to bind result: ' . $this->res->error);

        if (!$this->next())
            return false;
        return $output;
    }

    function close() {
        if (!$this->res)
            return;

        $this->time_fetch = microtime(true) - $this->time_prepare;
        $total = $this->time_prepare + $this->time_fetch;
        $this->stmt->log(['time'=>$total, 'fetch'=>$this->time_fetch, 'prepare'=>$this->time_prepare]);

        $this->res->close();
        $this->res = null;
    }

    function affected_rows() {
        return $this->res->affected_rows;
    }

    function insert_id() {
        return $this->res->insert_id;
    }
}
