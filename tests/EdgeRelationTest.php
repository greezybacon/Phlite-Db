<?php
use Phlite\Db;
use Phlite\Test\Northwind;

class EdgeRelationshipTest
extends \PHPUnit_Framework_TestCase {
    
    function testPopulateEdge() {
        $e1 = Northwind\Employee::lookup(1);
        $this->assertNotNull($e1);
        
        $r1 = Northwind\Region::objects()->first();
        $this->assertNotNull($r1);
        
        $edge = $e1->regions->add($r1);
        $this->assertInstanceOf($edge, Northwind\EmployeeRegion::class);
        $this->assertTrue($edge->save());
    }
    
    // TODO: Test data in the overlay model
}
