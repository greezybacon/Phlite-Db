<?php
namespace Test;

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

    function testRelationRemoveAndDelete() {
        $supplier = Northwind\Supplier::objects()->first();
        $before = $supplier->products->count();
        $juice = $supplier->products->findFirst([
            'ProductName' => 'Prune Juice']);

        // Test remove without delete
        $supplier->products->remove($juice, false);
        $this->assertNull($juice->SupplierID);
        $this->assertEquals($before - 1, $supplier->products->count());

        // Add back and test remove with delete
        $supplier->products->add($juice);
        $supplier->products->remove($juice, true);
        $this->assertTrue($juice->__deleted__);
        $this->assertEquals($before, $supplier->products->count());
    }
}
