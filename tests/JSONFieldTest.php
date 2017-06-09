<?php
namespace Test\JsonFieldTest;

use Phlite\Db;
use Phlite\Db\Fields;
use Phlite\Db\Migrations\Migration;

class JSONFieldTest
extends \PHPUnit_Framework_TestCase {
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
