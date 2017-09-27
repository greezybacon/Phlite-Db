<?php
namespace Phlite\Test\Northwind;

use Phlite\Db\Fields;
use Phlite\Db\Model;
use Phlite\Db\Model\SchemaBuilder;

class Product
extends Model\ModelBase {
    static $meta = [
        'table' => 'Products',
        'pk' => ['ProductID'],
        'joins' => [
            'supplier' => [
                'constraint' => ['SupplierID' => 'Supplier.SupplierID'],
            ],
            'category' => [
                'constraint' => ['CategoryID' => 'Category.CategoryID'],
            ],
            'sales' => [
                'reverse' => 'OrderDetail.product'
            ],
        ],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'ProductID'     => new Fields\AutoIdField(['pk' => true]),
            'ProductName'   => new Fields\TextField(['length' => 40]),
            'SupplierID'    => new Fields\ForeignKey(Supplier::class, ['join' => 'supplier']),
            'CategoryID'    => new Fields\IntegerField(),
            'QuantityPerUnit' => new Fields\TextField(['length' => 20]),
            'UnitPrice'     => new Fields\DecimalField(),
            'UnitsInStock'  => new Fields\IntegerField(['bits' => 16, 'default' => 0]),
            'UnitsOnOrder'  => new Fields\IntegerField(['bits' => 16, 'default' => 0]),
            'ReorderLevel'  => new Fields\IntegerField(['bits' => 16]),
            'Discontinued'  => new Fields\IntegerField(['bits' => 1]),
        ]);
    }

    function shouldReorder() {
        return $this->ReorderLevel >= $this->UnitsInStock + $this->UnitsOnOrder;
    }

    function low_on_stock() {
        return static::objects()->filter([
            'ReorderLevel__gt' => (new Field('UnitsInStock'))->plus(new Field('UnitsOnOrder'))
        ]);
    }
}

class Supplier
extends Model\ModelBase {
    static $meta = [
        'table' => 'Suppliers',
        'pk' => ['SupplierID'],
        'joins' => [
            'products' => [
                'reverse' => 'Product.supplier',
            ],
        ],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
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
        ]);
    }
}

class OrderDetail
extends Model\ModelBase {
    static $meta = [
        'table' => 'OrderDetails',
        'pk' => ['OrderID', 'ProductID'],
        'joins' => [
            'product' => [
                'constraint' => ['ProductID' => 'Product.ProductID'],
            ],
            'order' => [
                'constraint' => ['OrderID' => 'Order.OrderID'],
            ],
        ]
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'OrderID'       => new Fields\IntegerField(),
            'ProductID'     => new Fields\IntegerField(),
            'UnitPrice'     => new Fields\DecimalField(),
            'Quantity'      => new Fields\IntegerField(),
            'Discount'      => new Fields\DecimalField(),
        ]);
    }

    function getQuantityShippable() {
        // TODO: Maybe? this should work without $this->product; however, PHP
        // developers are too smart for me and restrict using the overlay as
        // the $this variable in the scope of this method.
        return min($this->Quantity, $this->product->UnitsInStock);
    }
}

class Order
extends Model\ModelBase {
    static $meta = [
        'table' => 'Orders',
        'pk' => ['OrderID'],
        'joins' => [
            'customer' => [
                'constraint' => ['CustomerID' => 'Customer.CustomerID'],
            ],
            'employee' => [
                'constraint' => ['EmployeeID' => 'Employee.EmployeeID'],
            ],
            'shipper' => [
                'constraint' => ['ShipVia' => 'Shipper.ShipperID'],
            ],
        ],
        'edges' => [
            'items' => [
                'target' => Product::class,
                'through' => OrderDetail::class
            ]
        ],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'OrderID'       => new Fields\AutoIdField(['pk' => true]),
            'CustomerID'    => new Fields\ForeignKey(Customer::class, ['join'=>'orders']),
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
        ]);
    }

    function getTotal() {
        $total = 0;
        foreach ($this->items as $I)
            $total += $I->Quantity * $I->UnitPrice;
        return $total;
    }

    function getShippable() {
        return $this->items->filter(['UnitsInStock__gt' => 0]);
    }
}

class Category
extends Model\ModelBase {
    static $meta = [
        'table' => 'Categories',
        'pk' => ['CategoryID'],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'CategoryID'    => new Fields\AutoIdField(['pk' => true]),
            'CategoryName'  => new Fields\TextField(['length' => 15]),
            'Description'   => new Fields\TextField(['length' => 1<<12]),
            'Picture'       => new Fields\BinaryField(),
        ]);
    }
}

class Territory
extends Model\ModelBase {
    static $meta = [
        'table' => 'Territories',
        'pk' => ['TerritoryID'],
        'joins' => [
            'region' => [
                'constraint' => ['RegionID' => 'Region.RegionID'],
            ],
        ],
        'field_types' => [
            'TerritoryID' => Fields\AutoIdField::class,
        ],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'TerritoryID'   => new Fields\AutoIdField(['pk' => true]),
            'TerritoryDescription' => new Fields\TextField(['length' => 50]),
            'RegionID'      => new Fields\IntegerField(),
        ]);
    }
}

class Region
extends Model\ModelBase {
    static $meta = [
        'table' => 'Region',
        'pk' => ['RegionID'],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'RegionID'      => new Fields\AutoIdField(['pk' => true]),
            'RegionDescription' => new Fields\TextField(['length' => 50]),
        ]);
    }
}

class Employee
extends Model\ModelBase {
    static $meta = [
        'table' => 'Employees',
        'pk' => ['EmployeeID'],
        'joins' => [
            'manager' => [
                'constraint' => ['ReportsTo' => 'Employee.EmployeeID'],
            ],
            'reports' => [
                'reverse' => 'Employee.manager',
            ],
            'sales' => [
                'reverse' => 'Order.employee',
            ],
        ],
        'edges' => [
            'territories' => [
                'target' => Territory::class,
                'through' => EmployeeTerritory::class,
            ]
        ],
        'field_types' => [
            'EmployeeID' => Fields\AutoIdField::class,
        ],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
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
        ]);
    }
}

class EmployeeTerritory
extends Model\ModelBase {
    static $meta = [
        'table' => 'EmployeeTerritories',
        'pk' => ['EmployeeID', 'TerritoryID'],
        'joins' => [
            'employee' => [
                'constraint' => ['EmployeeID' => 'Employee.EmployeeID'],
            ],
            'territory' => [
                'constraint' => ['TerritoryID' => 'Territory.TerritoryID'],
            ],
        ],
        'field_types' => [
            'EmployeeID' => Fields\AutoIdField::class,
        ],
    ];

    static function buildSchema(SchemaBuilder $b) {
        $b->addFields([
            'EmployeeID'    => new Fields\IntegerField(),
            'TerritoryID'   => new Fields\IntegerField(),
        ]);
        $b->addConstraint(new Fields\PrimaryKey(['EmployeeID', 'TerritoryID']));
    }
}

class Shipper
extends Model\ModelBase {
    static $meta = [
        'table' => 'Shippers',
        'pk' => ['ShipperID'],
        'field_types' => [
            'ShipperID' => Fields\AutoIdField::class,
        ],
    ];
}

class Customer
extends Model\ModelBase {
    static $meta = [
        'table' => 'Customers',
        'pk' => ['CustomerID'],
        'joins' => [
            'orders' => [
                'reverse' => 'Order.customer',
            ],
        ],
        'edges' => [
            'demographics' => [
                'target' => Demographic::class,
                'through' => CustomerDemographic::class,
            ],
        ],
    ];

    static function buildSchema(SchemaBuilder $builder) {
        $builder->addFields([
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
        ]);
    }
}

class CustomerDemographic
extends Model\ModelBase {
    static $meta = [
        'table' => 'CustomerCustomerDemo',
        'pk' => ['CustomerID', 'CustomerTypeID'],
        'joins' => [
            'customer' => [
                'constraint' => ['CustomerID' => 'Customer.CustomerID'],
            ],
            'demographic' => [
                'constraint' => ['CustomerTypeID' => 'Demographic.CustomerTypeID'],
            ],
        ],
    ];
}

class Demographic
extends Model\ModelBase {
    static $meta = [
        'table' => 'CustomerDemographics',
        'pk' => ['CustomerTypeID'],
    ];
}
