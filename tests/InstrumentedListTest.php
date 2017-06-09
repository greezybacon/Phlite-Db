<?php
namespace Test\InstrumentedListTest;

use Phlite\Db;
use Phlite\Test\Northwind;

class InstrumentedListTest
extends \PHPUnit_Framework_TestCase {
    function testAddData() {
        $order = new Northwind\Order();
        $order->customer = Northwind\Customer::lookup('BOTTM');
        $this->assertTrue($order->save());
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
