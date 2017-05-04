<?php
namespace Phlite\Db\Tests;

require_once 'vendor/autoload.php';

use Phlite\Cli;
use Phlite\Db;

class DbTestSetup
extends Cli\Module {
    var $options = [
        'backend' =>    ['-B', '--backend', 'help'=>'Database backend to be tested'],
        'FILE' =>       ['-f', '--file', 'help'=>'Database file for SQLite backend'],
    ];
    
    var $arguments = [
        'action' => ['help' => 'What to do',
            'options' => [
                'create' => 'Create database and load test data',
                'test' => 'Run the tests/ suite',
            ],
        ],
    ];

    function run($args, $options) {
        $this->setupBackend($options);
        
        $action = $args['action'];
        $method = "do_{$action}";
        if (method_exists($this, $method))
            return $this->{$method}($options);
    
        $this->fail(sprintf('%s: No such action', $action));
    }
    
    function do_create($options) {
        $initial = new \Phlite\Test\Northwind\InitialMigration();
        Db\Manager::migrate($initial);
    }
    
    function do_test($options) {
        global $argv;
        $_SERVER['argv'] = [$argv[0], dirname(__FILE__)];
        \PHPUnit_TextUI_Command::main();
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
                'HOST' => 'localhost',
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
}

// Cli\Manager::register(DbTestSetup::class);
(new DbTestSetup())();