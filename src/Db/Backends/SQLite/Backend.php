<?php
namespace Phlite\Db\Backends\MySQL;

use Phlite\Db;

class Backend extends Db\Backend {
    static $defaults = [
      'COMPILER'  => 'Phlite\Db\Backends\SQLite\Compiler',
      'DRIVER'    => 'Phlite\Db\Backends\SQLite\Driver',
    ];

    var $info;
    var $cnxn;
    protected $compiler;
    protected $driver;

    function __construct(array $info) {
        $this->info = new Util\ArrayObject($info);
        $this->compiler = @$info['OPTIONS']['COMPILER']
          ?: static::$defaults['COMPILER'];
        $this->driver = @$info['OPTIONS']['DRIVER']
          ?: static::$defaults['DRIVER']
    }

    function getCompiler($options=false) {
       $class = $this->compiler;
       return new $class($this, $options);
    }

    function getDriver(Db\Compile\Statement $stmt) {
        $class = $this->driver;
        return new $class($stmt, $this);
    }

    function getConnection() {
        $this->connect();
        return $this->conn;
    }

    function connect() {
        if (isset($this->cnxn))
            // No auto reconnect, use ::disconnect() first
            return;

        $db = $this->info->get('FILE', ':memory:');
        $options = new Util\ArrayObject($this->info->get('OPTIONS', array());

        // Assertions
        if ($db !== ':memory:') {
            $path = dirname(abspath($db));
            if (!file_exists($path) || !is_dir($path))
                throw new InvalidArgumentException('Database path does not exist');
        }

        if (!($this->cnxn = new SQLite3($db)))
            throw new \Exception('SQLite3 extension is missing on this system');

        // TODO: Handle encryption key, read-only and such

        $this->charset = $options->get('CHARSET', 'utf8');

        // TODO: Perhaps create PHP function for collation to enforce the
        //       charset setting
    }

    function escape($what) {
        return $this->cnxn->escapeString($what);
    }
}
