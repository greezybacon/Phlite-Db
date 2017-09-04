<?php

namespace Phlite\Db\Backends\MySQL;

use Phlite\Db;

class Backend
extends Db\Backend
implements Db\Transaction,
           Db\DistributedTransaction {

    static $compiler = 'Phlite\Db\Backends\MySQL\Compiler';
    static $executor = 'Phlite\Db\Backends\MySQL\MySQLiQuery';

    var $info;
    var $conn;

    function __construct(array $info) {
        $this->info = $info;
    }

    function getCompiler($options=false) {
       $class = static::$compiler;
       return new $class($this, $options);
    }

    function getDriver(Db\Compile\Statement $stmt) {
        $class = static::$executor;
        return new $class($stmt, $this);
    }

    function getConnection() {
        $this->connect();
        return $this->conn;
    }

    function connect() {
        if (isset($this->conn))
            // No auto reconnect, use ::disconnect() first
            return;

        $user = $this->info['USER'];
        $passwd = $this->info['PASSWORD'];
        $host = $this->info['HOST'];
        $options = new Util\ArrayObject(@$this->info['OPTIONS'] ?: array());

        // Assertions
        if(!strlen($user) || !strlen($host))
            throw new \Exception('Database settings are missing USER and HOST settings');

        if (!($this->conn = mysqli_init()))
            throw new \Exception('MySQLi extension is missing on this system');

        // Setup SSL if enabled
        if ($options->get('SSL', false)) {
            $this->conn->ssl_set(
                    $options['SSL']['key'],
                    $options['SSL']['cert'],
                    $options['SSL']['ca'],
                    null, null);
        }
        $port = ini_get("mysqli.default_port");
        $socket = ini_get("mysqli.default_socket");
        $persistent = stripos($host, 'p:') === 0;
        if ($persistent)
            $host = substr($host, 2);
        if (strpos($host, ':') !== false) {
            list($host, $portspec) = explode(':', $host);
            // PHP may not honor the port number if connecting to 'localhost'
            if ($portspec && is_numeric($portspec)) {
                if (!strcasecmp($host, 'localhost'))
                    // XXX: Looks like PHP gethostbyname() is IPv4 only
                    $host = gethostbyname($host);
                $port = (int) $portspec;
            }
            elseif ($portspec) {
                $socket = $portspec;
            }
        }

        if ($persistent)
            $host = 'p:' . $host;

        // Connect
        if (!@$this->conn->real_connect($host, $user, $passwd, null, $port, $socket))
            throw new Db\Exception\ConnectionError(sprintf(
                'Unable to connect to MySQL database %s@%s using supplied credentials',
                $user, $host));

        // Select the database, if any.
        if (isset($this->info['NAME'])) {
            if (!$this->conn->select_db($this->info['NAME']))
                throw new Db\Exception\ConnectionError(sprintf(
                '%s: Unable to select database', $this->info['NAME']
                ));
        }

        $this->charset = $options->get('CHARSET', 'utf8');
        $collation = $options->get('COLLATION', 'utf8_general_ci');

        // Set desired encoding according to settings.php
        // Thanks to FreshMedia
        $this->set_all(array(
            // TODO: Consider options in settings.php
            'NAMES'                 => 'utf8',
            'CHARACTER SET'         => $this->charset,
            'COLLATION_CONNECTION'  => $collation,
            'SQL_MODE'              => '',
        ));
        $this->conn->set_charset($this->charset);

        // Start a new transaction -- disable autocommit. XXX: This could be
        // sent in the set_all() call above as AUTOCOMMIT => 0|1
        if (isset($this->info['OPTIONS']['autocommit']))
            $this->conn->autocommit($this->info['OPTIONS']['autocommit']);
    }

    function get_variable($variable, $type='session') {
        $sql = sprintf('SELECT @@%s.%s', $type, $variable);
        return db_result(db_query($sql));
    }

    function set_variable($variable, $value, $type='session') {
        $sql = sprintf('SET %s %s=%s', strtoupper($type), $variable, $this->escape($value));
        return $this->conn->query($sql);
    }

    function set_all($variables, $type='session') {
        $set = array();
        foreach ($variables as $k=>$v) {
            $set[] = "$type $k = ".$this->escape($v);
        }
        $sql = 'SET ' . implode(', ', $set);
        return $this->conn->query($sql);
    }

    function escape($what) {
        return $this->conn->escape_string($what);
    }

    function attempt($sql, $error='') {
        if (!($rv = $this->conn->real_query($sql)))
            throw new Db\Exception\OrmError(
                ($error ?: 'SQL FAILED') . ': ' . $this->conn->error);
        return $rv;
    }

    // Transaction interface
    function beginTransaction() {
        return $this->conn->autocommit(false);
    }

    function commit() {
        return $this->conn->commit();
    }

    function rollback() {
        return $this->conn->rollback();
    }

    // DistributedTransaction interface
    function startDistributed() {
        $gtrid = spl_object_hash($this);
        $this->attempt(sprintf("XA BEGIN '%s'", $this->escape($gtrid)),
            'Cannot start xa transaction')
        return $grtid;
    }

    function tryCommit($gtrid) {
        $this->attempt(sprintf("XA END '%s'", $this->escape($gtrid)),
            'Cannot complete xa transaction')
        $this->attempt(sprintf("XA PREPARE '%s'", $this->escape($gtrid)),
            'Cannot commit xa transaction')
        return true;
    }

    function undoCommit($gtrid) {
        $this->attempt(sprintf("XA ROLLBACK '%s'", $this->escape($gtrid)),
            'Cannot undo xa transaction');
        return true;
    }

    function finishCommit($gtrid) {
        $this->attempt(sprintf("XA COMMIT '%s'", $this->escape($gtrid)),
            'Cannot finish xa transaction');
        return true;
    }
}
