<?php
namespace Test\InstrumentedListTest;

use Phlite\Db;
use Phlite\Db\Fields;
use Phlite\Db\Migrations\Migration;

abstract class TestModelBase
extends Db\Model\ModelBase {
    use Db\Model\Ext\ActiveRecord;

    static $meta = [
        'label' => 'ilt',
        'abstract' => true,
    ];
}

class User
extends TestModelBase {
    static $meta = [
        'table' => 'user',
        'pk' => ['id'],
        'joins' => [
            'emails' => [
                'reverse' => 'EmailAddress.user',
            ]
        ]
    ];
}

class EmailAddress
extends TestModelBase {
    static $meta = [
        'table' => 'email_addr',
        'pk' => ['id'],
        'joins' => [
            'user' => [
                'constraint' => ['user_id' => 'User.id'],
            ]
        ]
    ];
}

class CreateModels
extends Db\Migrations\Migration {
    function getOperations() {
        return [
            new Db\Migrations\CreateModel(User::class, [
                'id'        => new Fields\AutoIdField(['pk' => true]),
                'name'      => new Fields\TextField(['length' => 64]),
                'username'  => new Fields\TextField(['length' => 32]),
            ]),
            new Db\Migrations\CreateModel(EmailAddress::class, [
                'id'        => new Fields\AutoIdField(['pk' => true]),
                'user_id'   => new Fields\IntegerField(['bits' => 32]),
                'address'   => new Fields\TextField(['length' => 64]),
            ]),
        ];
    }
}

class InstrumentedListTest
extends \PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
        Db\Manager::migrate(new CreateModels());
    }

    static function tearDownAfterClass() {
        Db\Manager::migrate(new CreateModels(), Migration::BACKWARDS);
        Db\Manager::removeConnection('default');
    }
    
    function testAddData() {
        $user = new User([
            'name' => 'John Doe',
            'username' => 'jdoe',
        ]);
        $this->assertTrue($user->save());
        $this->assertTrue($user->id !== null);
    }

    function testRelationWrite() {
        $joe = User::objects()->first();
        $joe->emails->add(new EmailAddress(['address' => 'joe@example.com']));
        $this->assertTrue($joe->emails->saveAll());
        $this->assertEquals(1, EmailAddress::objects()
            ->filter(['address' => 'joe@example.com'])->count());

        $joe = User::objects()->first();
        $this->assertEquals(1, $joe->emails->count());
    }

    function testLazySelectRelated() {
        $joe = EmailAddress::objects()->first();
        $this->assertEquals($joe->user->username, 'jdoe');
    }
    
    function testSelectRelated() {
        $joe = EmailAddress::objects()->select_related('user')->first();
        $this->assertArrayHasKey('user', $joe->__ht__);
        $this->assertInstanceOf(User::class, $joe->user);
    }
}