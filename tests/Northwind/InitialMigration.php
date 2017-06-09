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
            new Migrations\CreateModel(Product::class, [
                'ProductID'     => new Fields\AutoIdField(['pk' => true]),
                'ProductName'   => new Fields\TextField(['length' => 40]),
                'SupplierID'    => new Fields\IntegerField(),
                'CategoryID'    => new Fields\IntegerField(),
                'QuantityPerUnit' => new Fields\TextField(['length' => 20]),
                'UnitPrice'     => new Fields\DecimalField(),
                'UnitsInStock'  => new Fields\IntegerField(['bits' => 16, 'default' => 0]),
                'UnitsOnOrder'  => new Fields\IntegerField(['bits' => 16, 'default' => 0]),
                'ReorderLevel'  => new Fields\IntegerField(['bits' => 16]),
                'Discontinued'  => new Fields\IntegerField(['bits' => 1]),
            ]),
            new Migrations\CreateModel(Supplier::class, [
                'SupplierID'    => new Fields\AutoIdField(['pk' => true]),
                'CompanyName'   => new Fields\TextField(['length' => 40]),
                'ContactName'   => new Fields\TextField(['length' => 30]),
                'ContactTitle'  => new Fields\TextField(['length' => 30]),
                'Address'       => new Fields\TextField(['length' => 60]),
                'City'          => new Fields\TextField(['length' => 15]),
                'Region'        => new Fields\TextField(['length' => 15]),
                'PostalCode'    => new Fields\TextField(['length' => 10]),
                'Country'       => new Fields\TextField(['length' => 15]),
                'Phone'         => new Fields\TextField(['length' => 24]),
                'Fax'           => new Fields\TextField(['length' => 24]),
                'HomePage'      => new Fields\TextField(['length' => 255]),
            ]),
            new Migrations\CreateModel(Region::class, [
                'RegionID'      => new Fields\AutoIdField(['pk' => true]),
                'RegionDescription' => new Fields\TextField(['length' => 50]),
            ]),
            new Migrations\CreateModel(Order::class, [
                'OrderID'       => new Fields\AutoIdField(['pk' => true]),
                'CustomerID'    => new Fields\IntegerField(),
                'EmployeeID'    => new Fields\IntegerField(),
                'OrderDate'     => new Fields\DatetimeField(),
                'RequiredDate'  => new Fields\DatetimeField(),
                'ShippedDate'   => new Fields\DatetimeField(),
                'ShipVia'       => new Fields\IntegerField(),
                'Freight'       => new Fields\DecimalField(),
                'ShipName'      => new Fields\TextField(['length' => 40]),
                'ShipAddress'   => new Fields\TextField(['length' => 60]),
                'ShipCity'      => new Fields\TextField(['length' => 15]),
                'ShipRegion'    => new Fields\TextField(['length' => 15]),
                'ShipPostalCode'=> new Fields\TextField(['length' => 10]),
                'ShipCountry'   => new Fields\TextField(['length' => 15]),
            ]),
            new Migrations\CreateModel(OrderDetail::class, [
                'OrderID'       => new Fields\IntegerField(),
                'ProductID'     => new Fields\IntegerField(),
                'UnitPrice'     => new Fields\DecimalField(),
                'Quantity'      => new Fields\IntegerField(),
                'Discount'      => new Fields\DecimalField(),
            ], [
                new Fields\PrimaryKey(['OrderID', 'ProductID']),
            ]),
            new Migrations\CreateModel(Category::class, [
                'CategoryID'    => new Fields\AutoIdField(['pk' => true]),
                'CategoryName'  => new Fields\TextField(['length' => 15]),
                'Description'   => new Fields\TextField(['length' => 1<<12]),
                'Picture'       => new Fields\BinaryField(),
            ]),
            new Migrations\CreateModel(Employee::class, [
                'EmployeeID'    => new Fields\AutoIdField(['pk' => true]),
                'LastName'      => new Fields\TextField(['length' => 20]),
                'FirstName'     => new Fields\TextField(['length' => 10]),
                'Title'         => new Fields\TextField(['length' => 30]),
                'TitleOfCourtesy' => new Fields\TextField(['length' => 25]),
                'BirthDate'     => new Fields\DatetimeField(),
                'HireDate'      => new Fields\DatetimeField(),
                'Address'       => new Fields\TextField(['length' => 60]),
                'City'          => new Fields\TextField(['length' => 15]),
                'Region'        => new Fields\TextField(['length' => 15]),
                'PostalCode'    => new Fields\TextField(['length' => 10]),
                'Country'       => new Fields\TextField(['length' => 15]),
                'HomePhone'     => new Fields\TextField(['length' => 24]),
                'Extension'     => new Fields\TextField(['length' => 4]),
                'Photo'         => new Fields\BinaryField(),
                'Notes'         => new Fields\TextField(['length' => 1<<16]),
                'ReportsTo'     => new Fields\IntegerField(),
                'PhotoPath'     => new Fields\TextField(['length'=> 255]),
            ]),
            new Migrations\CreateModel(EmployeeTerritory::class, [
                'EmployeeID'    => new Fields\IntegerField(),
                'TerritoryID'   => new Fields\IntegerField(),
            ], [
                new Fields\PrimaryKey(['EmployeeID', 'TerritoryID']),
            ]),
            new Migrations\CreateModel(Territory::class, [
                'TerritoryID'   => new Fields\AutoIdField(['pk' => true]),
                'TerritoryDescription' => new Fields\TextField(['length' => 50]),
                'RegionID'      => new Fields\IntegerField(),
            ]),
            new Migrations\CreateModel(Customer::class, [
                'CustomerID'    => new Fields\TextField(['pk' => true, 'length'=>8]),
                'CompanyName'   => new Fields\TextField(['length' => 40]),
                'ContactName'   => new Fields\TextField(['length' => 30]),
                'ContactTitle'  => new Fields\TextField(['length' => 30]),
                'Address'       => new Fields\TextField(['length' => 60]),
                'City'          => new Fields\TextField(['length' => 15]),
                'Region'        => new Fields\TextField(['length' => 15]),
                'PostalCode'    => new Fields\TextField(['length' => 10]),
                'Country'       => new Fields\TextField(['length' => 15]),
                'Phone'         => new Fields\TextField(['length' => 24]),
                'Fax'           => new Fields\TextField(['length' => 24]),
            ]),
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