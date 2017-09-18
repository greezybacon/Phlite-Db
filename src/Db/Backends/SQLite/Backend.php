<?php
namespace Phlite\Db\Backends\SQLite;

use Phlite\Db;
use Phlite\Util;

class Backend
extends Db\Backend
implements Db\Transaction {
    static $defaults = [
      'COMPILER'  => 'Phlite\Db\Backends\SQLite\Compiler',
      'DDLCOMPILER' => 'Phlite\Db\Backends\SQLite\DdlCompiler',
      'DRIVER'    => 'Phlite\Db\Backends\SQLite\Driver',
    ];

    var $info;
    var $cnxn;
    protected $compiler;
    protected $ddlcompiler;
    protected $driver;

    function __construct(array $info) {
        $this->info = new Util\ArrayObject($info);
        $this->compiler = @$info['OPTIONS']['COMPILER']
          ?: static::$defaults['COMPILER'];
        $this->ddlcompiler = @$info['OPTIONS']['DDLCOMPILER']
          ?: static::$defaults['DDLCOMPILER'];
        $this->driver = @$info['OPTIONS']['DRIVER']
          ?: static::$defaults['DRIVER'];
    }

    function getCompiler($options=false) {
       $class = $this->compiler;
       return new $class($this, $options);
    }

    function getDdlCompiler($options=false) {
       $class = $this->ddlcompiler;
       return new $class($this, $options);
    }

    function getDriver(Db\Compile\Statement $stmt) {
        $class = $this->driver;
        return new $class($stmt, $this);
    }

    function getConnection() {
        $this->connect();
        return $this->cnxn;
    }

    function connect() {
        if (isset($this->cnxn))
            // No auto reconnect, use ::disconnect() first
            return;

        $db = $this->info->get('FILE', ':memory:');
        $options = new Util\ArrayObject($this->info->get('OPTIONS', array()));

        // Assertions
        if ($db !== ':memory:') {
            $path = dirname(realpath($db)) ?: '.';
            if (!file_exists($path) || !is_dir($path))
                throw new \InvalidArgumentException(sprintf(
                    '%s: Database path does not exist', $db));
        }

        if (!($this->cnxn = new \SQLite3($db)))
            throw new \Exception('SQLite3 extension is missing on this system');

        // TODO: Handle encryption key, read-only and such

        $this->charset = $options->get('CHARSET', 'utf8');

        // TODO: Perhaps create PHP function for collation to enforce the
        //       charset setting

        // Enable REGEXP support
        $this->cnxn->createFunction('regexp',
            function($y, $x) { return preg_match("/$y/u", $x); });
    }

    function close() {
        $this->cnxn->close();
    }

    function escape($what) {
        return $this->cnxn->escapeString($what);
    }

    // Transaction interface
    function beginTransaction() {
        return $this->getConnection()->exec('BEGIN');
    }

    function rollback() {
        return $this->getConnection()->exec('ROLLBACK');
    }

    function commit() {
        return $this->getConnection()->exec('COMMIT');
    }
}
