<?php
namespace Test;

use Phlite\Db;
use Phlite\Db\Util;
use Phlite\Test\Northwind;

class QuerySetTest
extends \PHPUnit_Framework_TestCase {
    function testSelectRelated() {
        $chai = Northwind\Product::objects()
            ->select_related('category')
            ->filter(['ProductID' => 1])
            ->one();
        $this->assertArrayHasKey('category', $chai->__ht__);
        $this->assertInstanceOf(Northwind\Category::class, $chai->category);
    }

    function testAggregateWithAssumedName() {
        // See how many items nancy sold
        $nancy_sales = Northwind\Employee::objects()
            ->filter(['EmployeeID' => 1])
            ->aggregate(Util\Aggregate::SUM('sales__items__quantity'))
            ->order_by('-sales__items__quantity')
            ->values_flat()
            ->limit(1);

        $this->assertNotNull($nancy_sales[0]);
        $this->assertEquals($nancy_sales[0][0], 7812);
    }

    function testAggregateExpression() {
        // Top grossing employee
        $top_sales = Northwind\Employee::objects()
            ->values_flat('EmployeeID')
            ->annotate(['gross_sales' => Util\Aggregate::SUM(
                (new Util\Field('sales__items__Quantity'))->times(new Util\Field('sales__items__UnitPrice')))
            ])
            ->order_by('-gross_sales')
            ->limit(1);

        $this->assertEquals(1, count($top_sales));
        list($emp_id, $sales) = $top_sales[0];
        $this->assertEquals(4, $emp_id);
        $this->assertEquals(250187.45, $sales);
    }

    function testAnnotateWithName() {
        $top_sales = Northwind\Employee::objects()
            ->values_flat('EmployeeID')
            ->annotate(['sales_count' => Util\Aggregate::COUNT('sales')])
            ->order_by('-sales_count')
            ->limit(1);

        $this->assertEquals(1, count($top_sales));
        list($emp_id, $sales) = $top_sales[0];
        $this->assertEquals(4, $emp_id);
        $this->assertEquals(156, $sales);
    }
}