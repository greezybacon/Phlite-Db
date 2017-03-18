<?php
namespace Test\MigrateCreateTest;

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
            new Db\Migrations\CreateModel(User::class, [
                'id'        => new Db\Fields\AutoIdField(['pk' => true]),
                'name'      => new Db\Fields\TextField(['length' => 64]),
                'username'  => new Db\Fields\TextField(['length' => 32]),
            ]),
        ];
    }
}

class CreateMigrateTest
extends \PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
    }

    function testCreateModel() {
        Db\Manager::migrate(new CreateModels());

        $fields = User::getMeta()->getFields();
        $this->assertArrayHasKey('id', $fields);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('username', $fields);
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
        $this->assertInstanceOf(User::class,
            $u = User::lookup(['username' => 'jodoe']));
        $u->name = 'John Doe, II';
        $this->assertTrue($u->save());
    }

    /**
     * @depends testCreateModel
     */
    function testDelete() {
        $u = User::lookup(['username' => 'jodoe']);
        $before = count(User::objects());
        $this->assertTrue($u->delete());
        $this->assertEquals($before - 1, count(User::objects()));
        $this->assertTrue($u->__deleted__);

        $this->expectException(Db\Exception\DoesNotExist::class);
        User::lookup(['username' => 'jodoe']);
    }
}
