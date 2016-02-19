<?php
use Phlite\Db;

class User
extends Db\Model\ModelBase {
    static $meta = [
        'table' => 'user',
        'pk' => ['id'],
    ];
}

class CreateModels
extends Db\Migrations\Migration {
    function getOperations() {
        return [
            new Db\Migrations\CreateModel('User', [
                'id'        => new Db\Fields\AutoIdField(),
                'name'      => new Db\Fields\TextField(['length' => 64]),
                'username'  => new Db\Fields\TextField(['length' => 32]),
            ]),
        ];
    }
}

class CreateMigrateTest
extends PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ]);
    }

    function testCreateModel() {
        Db\Manager::migrate(new CreateModels());

        $this->assertContains('id', User::getMeta('fields'));
        $this->assertContains('name', User::getMeta('fields'));
        $this->assertContains('username', User::getMeta('fields'));
    }

}
