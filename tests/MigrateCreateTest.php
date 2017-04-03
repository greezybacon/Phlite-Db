<?php
use Phlite\Db;

class CreateMigrateTest
extends \PHPUnit_Framework_TestCase {
    function testCreateModel() {
        $fields = User::getMeta()->getFields();
        $this->assertArrayHasKey('id', $fields);
        $this->assertArrayHasKey('email_id', $fields);
        $this->assertArrayHasKey('name', $fields);
        $this->assertArrayHasKey('username', $fields);
    }

    function testInsert() {
        $u = new User(['name' => 'John Doe', 'username' => 'jodoe']);
        $this->assertTrue($u->save());
        $this->assertNotNull($u->id);
    }

    function testUpdate() {
        // Dump cache to force a SELECT
        Db\Model\ModelInstanceManager::flushCache();
        $this->assertInstanceOf(User::class,
            $u = User::lookup(['username' => 'jodoe']));
        $u->name = 'John Doe, II';
        $this->assertTrue($u->save());

        $u = User::lookup(['username' => 'jodoe']);
        $this->assertEquals($u->name, 'John Doe, II');
    }

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
