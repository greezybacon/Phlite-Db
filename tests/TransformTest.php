<?php
namespace Phlite\Test;

use Phlite\Test\Northwind;

class TransformTest
extends \PHPUnit_Framework_TestCase {
    function testGtTransform() {
        $this->assertCount(78,
            Northwind\Order::objects()
                ->filter(['OrderID__gt' => 11000])
        );
        // And for the ::evaluate method
        $this->assertCount(78,
            Northwind\Order::objects()->all()
                ->findAll(['OrderID__gt' => 11000])
    );
    }

    function testGteTransform() {
        $this->assertCount(79,
            Northwind\Order::objects()
                ->filter(['OrderID__gte' => 11000])
        );
        $this->assertCount(79,
        Northwind\Order::objects()->all()
            ->findAll(['OrderID__gte' => 11000])
        );
    }

    function testLtTransform() {
        $this->assertCount(752,
            Northwind\Order::objects()
                ->filter(['OrderID__lt' => 11000])
        );
        $this->assertCount(752,
        Northwind\Order::objects()->all()
            ->findAll(['OrderID__lt' => 11000])
        );
    }

    function testLteTransform() {
        $this->assertCount(753,
            Northwind\Order::objects()
                ->filter(['OrderID__lte' => 11000])
        );
        $this->assertCount(753,
            Northwind\Order::objects()->all()
                ->findAll(['OrderID__lte' => 11000])
        );
    }

    function testIsnullTransform() {
        // SQLite has a problem with IS NULL. It does not return proper results
        $this->assertCount(30,
            Northwind\Order::objects()
                ->filter(['ShipRegion__isnull' => true])
        );
        $this->assertCount(30,
            Northwind\Order::objects()->all()
                ->findAll(['ShipRegion__isnull' => true])
        );
    }

    function testRangeTransform() {
        $this->assertCount(144,
            Northwind\Order::objects()
                ->filter(['Freight__range' => [20, 40]])
        );
        $this->assertCount(144,
            Northwind\Order::objects()->all()
                ->findAll(['Freight__range' => [20, 40]])
        );
    }

    function testContainsTransform() {
        $this->assertCount(122,
            Northwind\Order::objects()
                ->filter(['ShipCountry__contains' => 'rman'])
        );
        $this->assertCount(122,
            Northwind\Order::objects()->all()
                ->findAll(['ShipCountry__contains' => 'rman'])
        );
    }

    function testStartswithTransform() {
        $this->assertCount(18,
            Northwind\Order::objects()
                ->filter(['ShipCountry__startswith' => 'Switz'])
        );
        $this->assertCount(18,
            Northwind\Order::objects()->all()
                ->findAll(['ShipCountry__startswith' => 'Switz'])
        );
    }

    function testEndswithTransform() {
        $this->assertCount(66,
            Northwind\Order::objects()
                ->filter(['ShipCountry__endswith' => 'land'])
        );
        $this->assertCount(66,
            Northwind\Order::objects()->all()
                ->findAll(['ShipCountry__endswith' => 'land'])
        );
    }

    function testRegexTransform() {
        // Address ends in a number ?
        // SQLite3 requires a user-defined function to actually implement this
        $this->assertCount(510,
            Northwind\Order::objects()
                ->filter(['ShipAddress__regex' => '\d+$'])
        );
        $this->assertCount(510,
            Northwind\Order::objects()->all()
                ->findAll(['ShipAddress__regex' => '\d+$'])
        );
    }

    /**
     * @expectedException Phlite\Db\Exception\QueryError
    */
    function testTextTransformFailOnIntField() {
        Northwind\Order::objects()
            ->filter(['OrderID__contains' => '1337'])
            ->one();
    }

    function testYearTransform() {
        $this->assertCount(152,
            Northwind\Order::objects()
                ->filter(['OrderDate__year' => 1996])
        );
        $this->assertCount(152,
            Northwind\Order::objects()->all()
                ->findAll(['OrderDate__year__exact' => 1996])
        );
    }
}