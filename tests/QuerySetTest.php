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

    function testSimpleAnnotation() {
        $chai = Northwind\Product::objects()
            ->select_related('category')
            ->filter(['ProductID' => 1])
            ->annotate(['expensive?' => new Util\Expression(
                ['UnitPrice__gt' => 50.0])])
            ->one();

        $this->assertNotNull($chai);
        $this->assertNotNull($chai->{'expensive?'});
        $this->assertEquals(false, $chai->{'expensive?'});
    }

    function testFieldExpressionFilter() {
        $subpar = Northwind\Product::objects()
            ->filter([
                'ReorderLevel__gt' => (new Util\Field('UnitsInStock'))->plus(new Util\Field('UnitsOnOrder'))
            ]);

        $this->assertEquals(2, count($subpar));
        foreach ($subpar as $P)
            $this->assertEquals(true, $P->UnitsInStock + $P->UnitsOnOrder < $P->ReorderLevel);
    }

    function testAggregateFilter() {
        // Criteria here should be placed in the having clause
        $big_guns = Northwind\Employee::objects()
            ->annotate(['gross_sales' => Util\Aggregate::SUM(
                (new Util\Field('sales__items__Quantity'))->times(new Util\Field('sales__items__UnitPrice')))
            ])
            ->filter(['gross_sales__gt' => 20000]);

        $this->assertContains(' HAVING ', (string) $big_guns);
        $this->assertNotContains(' WHERE ', (string) $big_guns);
        $this->assertEquals(7, count($big_guns));

        foreach ($big_guns as $bg)
            $this->assertTrue($bg->gross_sales > 20000);
    }

    function testExists() {
        // Anyone handling Seattle?
        $seattle = Northwind\Employee::objects()
            ->filter(['territories__TerritoryDescription' => 'Seattle']);

        // Looks like there is
        $this->assertTrue($seattle->exists());

        $seattle = Northwind\Employee::objects()
            ->filter(['territories__TerritoryDescription' => 'Podunk']);
        // and a fetch
        $this->assertFalse($seattle->exists(true));
    }

    function testLeftJoinPropagation() {
        $total = count(Northwind\Employee::objects());
        $handled = Northwind\Employee::objects()
            ->annotate(['territory_count' => Util\Aggregate::COUNT('territories__region')]);

        // The regex searches for LEFT JOIN followed by JOIN not preceeded by LEFT
        $this->assertNotRegexp('/LEFT JOIN .+? ((?<!LEFT )JOIN)/', (string) $handled,
            'Does not propagate left joins in paths');
        $this->assertEquals($total, count($handled));
    }

    function testSerialize() {
        $nancy_sales = Northwind\Employee::objects()
            ->filter(['EmployeeID' => 1])
            ->aggregate(Util\Aggregate::SUM('sales__items__Quantity'))
            ->order_by('-sales__items__Quantity')
            ->values_flat()
            ->limit(1);

        $dat = serialize($nancy_sales);
        $sales = unserialize($dat);

        $this->assertNotNull($sales[0]);
        $this->assertEquals($sales[0][0], 7812);
    }

    function testLimit() {
        $sales = Northwind\Order::objects()
            ->limit(10);

        $this->assertCount(10, $sales);
    }

    function testOffset() {
        $sales = Northwind\Order::objects()
            ->order_by('OrderID')
            ->offset(10)
            ->limit(10);

        $this->assertEquals(10258, $sales[0]->OrderID);
    }

    // Nested select queries ----------------------
    function testNestedSelectInWhere() {
        // Find the employee with the oldest order (by OrderDate)
        $oldest = Northwind\Order::objects()
            ->filter(['OrderDate' =>
                Northwind\Order::objects()
                    ->aggregate(Util\Aggregate::MIN('OrderDate'))
            ])
            ->select_related('employee');

        $this->assertCount(1, $oldest);
        $this->assertEquals(5, $oldest[0]->employee->EmployeeID);
    }

    function testNestSelectAsJoin() {
        Northwind\Order::objects();
    }

    function testNestedSelectAsIn() {
        // Orders from the top 5 suppliers
        $big_5 = Northwind\Order::objects()
            ->filter(['items__product__supplier__in' =>
                Northwind\Supplier::objects()
                    ->values('SupplierID')
                    ->aggregate(['product_count' => Util\Aggregate::COUNT('products')])
                    ->order_by('-product_count')
                    ->limit(5)
            ]);

        $this->assertCount(5, $big_5);
    }
}