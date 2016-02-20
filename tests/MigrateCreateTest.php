<?php
use Phlite\Db;

class User
extends Db\Model\ModelBase {
    use Db\Model\Ext\ActiveRecord;
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
        ], 'default');
    }

    function testCreateModel() {
        Db\Manager::migrate(new CreateModels());

        $this->assertContains('id', User::getMeta('fields'));
        $this->assertContains('name', User::getMeta('fields'));
        $this->assertContains('username', User::getMeta('fields'));
    }

    /**
     * @depends testCreateModel
     */
    function testInsert() {
        $u = new User(['name' => 'John Doe', 'username' => 'jodoe']);
        $this->assertTrue($u->save());
        $this->assertNotNull($u->id);
    }

    /**
     * @depends testCreateModel
     */
    function testUpdate() {
        // Dump cache to force a SELECT
        Db\Model\ModelInstanceManager::flushCache();
        $this->assertInstanceOf('User',
            $u = User::lookup(['username' => 'jodoe']));
        $u->name = 'John Doe, II';
        $this->assertTrue($u->save());
    }

    /**
     * @depends testCreateModel
     */
    function testDelete() {
        $u = User::lookup(['username' => 'jodoe']);
        $this->assertTrue($u->delete());
        $this->assertEquals(0, count(User::objects()));
    }
}
