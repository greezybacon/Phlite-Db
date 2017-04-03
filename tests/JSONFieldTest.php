<?php
namespace Test\JsonFieldTest;

use Phlite\Db;
use Phlite\Db\Fields;
use Phlite\Db\Migrations\Migration;

class User
extends Db\Model\ModelBase {
    static $meta = [
        'table' => 'user',
        'pk' => ['id'],
        'field_types' => [
            'props' => Fields\JSONField::class,
        ],
        'interpret' => ['props'],
    ];
}

class EmailAddressMeta
extends Db\Model\ModelMeta {
    function build($model) {
        User::getMeta()->addJoin('emails', [
            'reverse' => 'EmailAddress.user',
        ]);
        return parent::build($model);
    }
}

class EmailAddress
extends Db\Model\ModelBase {
    static $metaclass = EmailAddressMeta::class;
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
                'props'     => new Fields\JSONField(['length' => 1000]),
            ]),
            new Db\Migrations\CreateModel(EmailAddress::class, [
                'id'        => new Fields\AutoIdField(['pk' => true]),
                'address'   => new Fields\TextField(['length' => 64]),
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
