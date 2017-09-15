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
    }
    
    function testGteTransform() {
        $this->assertCount(79,
            Northwind\Order::objects()
                ->filter(['OrderID__gte' => 11000])
        );
    }
    
    function testLtTransform() {
        $this->assertCount(752,
            Northwind\Order::objects()
                ->filter(['OrderID__lt' => 11000])
        );
    }
    
    function testLteTransform() {
        $this->assertCount(753,
            Northwind\Order::objects()
                ->filter(['OrderID__lte' => 11000])
        );
    }
    
    function testIsnullTransform() {
        // SQLite has a problem with IS NULL. It does not return proper results
        $this->assertCount(30,
            Northwind\Order::objects()
                ->filter(['ShipRegion__isnull' => true])
        );
    }
    
    function testContainsTransform() {
        $this->assertCount(122,
            Northwind\Order::objects()
                ->filter(['ShipCountry__contains' => 'rman'])
        );
    }
    
    function testStartswithTransform() {
        $this->assertCount(18,
            Northwind\Order::objects()
                ->filter(['ShipCountry__startswith' => 'Switz'])
        );
    }
    
    function testEndswithTransform() {
        $this->assertCount(66,
            Northwind\Order::objects()
                ->filter(['ShipCountry__endswith' => 'land'])
        );
    }
    
    function testRegexTransform() {
        // Address ends in a number ?
        // SQLite3 requires a user-defined function to actually implement this
        $this->assertCount(66,
            Northwind\Order::objects()
                ->filter(['ShipAddress__regex' => '\d+$'])
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
        $sales_1996 = Northwind\Order::objects()
            ->filter(['OrderDate__year__exact' => 1996]);
        $this->assertCount(152, $sales_1996);
    }
}