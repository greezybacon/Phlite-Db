<?php
use Phlite\Db;

include_once 'Migrations.php';

class CreateMigrateTest
extends PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
        Db\Manager::migrate(new CreateModels());
    }

    function testCreateModel() {
        $this->assertContains('id', User::getMeta('fields'));
        $this->assertContains('email_id', User::getMeta('fields'));
        $this->assertContains('name', User::getMeta('fields'));
        $this->assertContains('username', User::getMeta('fields'));
    }

    function testInsert() {
        $u = new User(['name' => 'John Doe', 'username' => 'jodoe']);
        $this->assertTrue($u->save());
        $this->assertNotNull($u->id);
    }

    function testUpdate() {
        // Dump cache to force a SELECT
        Db\Model\ModelInstanceManager::flushCache();
        $this->assertInstanceOf('User',
            $u = User::lookup(['username' => 'jodoe']));
        $u->name = 'John Doe, II';
        $this->assertTrue($u->save());

        $u = User::lookup(['username' => 'jodoe']);
        $this->assertEquals($u->name, 'John Doe, II');
    }

    function testDelete() {
        $u = User::lookup(['username' => 'jodoe']);
        $this->assertTrue($u->delete());
        $this->assertEquals(0, count(User::objects()));
    }
}
