<?php
namespace Test\InstrumentedListTest;

use Phlite\Db;
use Phlite\Test\Northwind;

class InstrumentedListTest
extends \PHPUnit_Framework_TestCase {
    function testAddData() {
        $order = new Northwind\Order();
        $order->customer = Northwind\Customer::objects()->lookup('BOTTM');
        $this->assertTrue($order->save());
    }

    function testRelationWrite() {
        $supplier = Northwind\Supplier::objects()->first();
        $before = $supplier->products->count();
        $supplier->products->add(Northwind\Product::objects()->lookup([
            'ProductName' => 'Prune Juice']));
        $this->assertTrue($supplier->products->saveAll());
        $this->assertEquals(1 + $before,
            Northwind\Product::objects()->filter([
                'SupplierID' => $supplier->SupplierID
            ])->count());

        $supplier = Northwind\Supplier::objects()->first();
        $this->assertEquals(1 + $before, $supplier->products->count());
    }

    function testLazySelectRelated() {
        $chai = Northwind\Product::objects()->lookup(1);
        $this->assertNotNull($chai);
        $this->assertInstanceOf(Northwind\Supplier::class, $chai->supplier);
        $this->assertEquals($chai->supplier->CompanyName, 'Exotic Liquids');
    }

    function testSelectRelated() {
        $chai = Northwind\Product::objects()
            ->select_related('category')
            ->filter(['ProductID' => 1])
            ->one();
        $this->assertArrayHasKey('category', $chai->__ht__);
        $this->assertInstanceOf(Northwind\Category::class, $chai->category);
    }
}
