<?php
namespace Test\JsonFieldTest;

use Phlite\Db;

class User
extends Db\Model\ModelBase {
    use Db\Model\Ext\ActiveRecord;
    static $meta = [
        'table' => 'user',
        'pk' => ['id'],
        'field_types' => [
            'props' => Db\Fields\JSONField::class,
        ],
        'interpret' => ['props'],
    ];
}

class EmailAddress
extends Db\Model\ModelBase {
    use Db\Model\Ext\ActiveRecord;
    static $meta = [
        'table' => 'email_addr',
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
                'props'     => new Db\Fields\JSONField(['length' => 1000]),
            ]),
            new Db\Migrations\CreateModel(EmailAddress::class, [
                'id'        => new Db\Fields\AutoIdField(['pk' => true]),
                'address'   => new Db\Fields\TextField(['length' => 64]),
                'created'   => new Db\Fields\DatetimeField(['length' => 32]),
            ]),
        ];
    }
}

class JSONFieldTest
extends \PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
        Db\Manager::migrate(new CreateModels());
    }

    static function tearDownAfterClass() {
        Db\Manager::removeConnection('default');
    }

    function testJSONCreate() {
        $user = new User([
            'name' => 'John Doe',
            'username' => 'jdoe',
            'props' => array('age' => 33),
        ]);
        $this->assertTrue($user->save());
        $this->assertTrue($user->id !== null);
    }

    function testJSONFetch() {
        $user = User::lookup(['username' => 'jdoe']);
        $this->assertInternalType('array', $user->props);
    }
}
