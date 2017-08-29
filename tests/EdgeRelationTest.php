<?php
use Phlite\Db;
use Phlite\Test\Northwind;

class EdgeRelationshipTest
extends \PHPUnit_Framework_TestCase {
    
    function testPopulateEdge() {
        $e1 = Northwind\Employee::objects()->lookup(1);
        $this->assertNotNull($e1);
        
        $r1 = Northwind\Territory::objects()->first();
        $this->assertNotNull($r1);

        $edge = $e1->territories->add($r1);
        // Edge returned will extend from Territory, but should have overlay
        // properties from the intermediate model
        $this->assertInstanceOf(Northwind\Territory::class, $edge);
        $this->assertTrue($edge->save());
    }

    // TODO: Test data in the overlay model
    function testOverlayCount() {
        $o1 = Northwind\Order::objects()->lookup(10248);
        $this->assertEquals($o1->items->count(), 3);
    }

    function testOverlayFields() {
        $o1 = Northwind\Order::objects()->lookup(10248);
        $items = $o1->items;

        $item = $items->findFirst(['ProductID' => 11]);
        // Edge class looks like the remote target
        $this->assertInstanceOf(Northwind\Product::class, $item);
        // Has references back to the order
        $this->assertInstanceOf(Northwind\Order::class, $item->order);
        // Provides access to the intermediate fields
        $this->assertEquals($item->Quantity, 12);
        $this->assertEquals($item->Discount, 0);
        // Allows writing
        $item->Quantity = 13;
        // Will save properly
        $this->assertTrue($item->save());
    }

    function testOverlayMethod() {
        $o1 = Northwind\Order::objects()->lookup(10248);
        $this->assertEquals($o1->getTotal(), 454.0);

        $item = $o1->items->findFirst(['ProductID' => 11]);
        $this->assertEquals($item->getQuantityShippable(), 13);
        $this->assertFalse($item->shouldReorder());
    }
}
