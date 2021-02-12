<?php
namespace Phlite\Db\Tests;

require_once 'vendor/autoload.php';

use Phlite\Cli;
use Phlite\Db;

class DbTestSetup
extends Cli\Module
implements Db\Util\IContextManager {

    var $options = [
        'backend' =>    ['-B', '--backend', 'help'=>'Database backend to be tested',
            'default'=>'sqlite'],
        'file' =>       ['-f', '--file', 'help'=>'Database file for SQLite backend',
            'default'=>':memory:'],
    ];

    var $arguments = [
        'action' => ['help' => 'What to do',
            'options' => [
                'create' => 'Create database and load test data',
                'test' => 'Run the tests/ suite',
                'interact' => 'Setup database connections and enter CLI interactive mode',
            ],
        ],
    ];

    function run($args, $options) {
        $this->setupBackend($options);

        $action = $args['action'];
        $method = "do_{$action}";
        if (method_exists($this, $method)) {
            return Db\Util\ContextManager::with($this)->do(
            function() use ($method, $options) {
                return $this->{$method}($options);
            });
        }

        $this->fail(sprintf('%s: No such action', $action));
    }

    function do_test($options) {
        global $argv;
        $_SERVER['argv'] = [$argv[0], dirname(__FILE__)];
        \PHPUnit_TextUI_Command::main(false);
    }

    function do_interact($options) {
        (new Cli\Interact())->cmdloop();
    }

    function setupBackend($options) {
        static $names = [
            'sqlite'    => ['sqlite', 'sqlite3'],
            'mysql'     => ['mysql', 'mariadb'],
        ];

        static $bks = [
            'sqlite' => Db\Backends\SQLite\Backend::class,
            'mysql' => Db\Backends\MySQL\Backend::class,
        ];

        static $defaults = [
            'sqlite' => [
                'FILE' => ':memory:',
            ],
            'mysql' => [
                'HOST' => '127.0.0.1',
            ],
        ];

        $bk = strtolower($options['backend']);
        foreach ($names as $official => $aliases) {
            foreach ($aliases as $name) {
                if (strcasecmp($bk, $name) === 0) {
                    $bk = $official;
                    break;
                }
            }
        }

        $class = $bks[$bk];
        $settings = [
            'BACKEND' => $class,
            'FILE' => $options['file'],
        ] + $defaults[$bk];

        Db\Manager::addConnection($settings, 'default');
    }

    function __enter() {
        $TI = $this->stderr->getTerminfo();
        $this->stderr->write($TI->template(
            "{setaf:GREEN}>>> Creating database data ...{sgr0}\n"));
        $initial = new \Phlite\Test\Northwind\InitialMigration();
        Db\Manager::migrate($initial);
        return $this;
    }

    function __exit($e) {
        $initial = new \Phlite\Test\Northwind\InitialMigration();
        $TI = $this->stderr->getTerminfo();
        $this->stderr->write($TI->template(
            "{setaf:CYAN}>>> Destroying database data ...{sgr0}\n"));
        Db\Manager::migrate($initial, Db\Migrations\Migration::BACKWARDS);
    }
}

// Cli\Manager::register(DbTestSetup::class);
(new DbTestSetup())->_run();