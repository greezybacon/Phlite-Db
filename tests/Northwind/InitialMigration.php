<?php
namespace Phlite\Test\Northwind;

use Phlite\Db;
use Phlite\Db\Fields;
use Phlite\Db\Migrations;
use Phlite\Db\Model\Fixture;

// Model Creation Operations ----------------------------------

class InitialMigration
extends Migrations\Migration {
    static $csv_files = [
        ['Data/categories.csv', Category::class],
        ['Data/customers.csv', Customer::class],
        ['Data/employee-territories.csv', EmployeeTerritory::class],
        ['Data/employees.csv', Employee::class],
        ['Data/order-details.csv', OrderDetail::class],
        ['Data/orders.csv', Order::class],
        ['Data/products.csv', Product::class],
        ['Data/regions.csv', Region::class],
        ['Data/shippers.csv', Shipper::class],
        ['Data/suppliers.csv', Supplier::class],
        ['Data/territories.csv', Territory::class],
    ];

    function getOperations() {
        yield from new \ArrayIterator([
            new Migrations\CreateModel(Product::class),
            new Migrations\CreateModel(Supplier::class),
            new Migrations\CreateModel(Region::class),
            new Migrations\CreateModel(Customer::class),
            new Migrations\CreateModel(Order::class),
            new Migrations\CreateModel(OrderDetail::class, null, [
                new Fields\PrimaryKey(['OrderID', 'ProductID']),
            ]),
            new Migrations\CreateModel(Category::class),
            new Migrations\CreateModel(Employee::class),
            new Migrations\CreateModel(EmployeeTerritory::class),
            new Migrations\CreateModel(Territory::class),
            new Migrations\CreateModel(Shipper::class, [
                'ShipperID'     => new Fields\AutoIdField(['pk' => true]),
                'CompanyName'   => new Fields\TextField(['length' => 40]),
                'Phone'         => new Fields\TextField(['length' => 24]),
            ]),
        ]);

        // Now load the initial data
        foreach (static::$csv_files as $I) {
            list($filename, $class) = $I;
            $filename = dirname(__file__) . DIRECTORY_SEPARATOR . $filename;
            $loader = new Fixture\SimpleLoader(
                new Fixture\CsvReader([
                'model' => $class::getMeta(),
                'file' => $filename,
            ]));
            yield new Migrations\LoadFixtures($loader);
        }
    }
}