<?php
namespace Phlite\Test\Northwind;

use Phlite\Db\Fields;
use Phlite\Db\Model;

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
        ],
        'field_types' => [
            'ProductID' => Fields\AutoIdField::class,
        ],
    ];
}

class Supplier
extends Model\ModelBase {
    static $meta = [
        'table' => 'Suppliers',
        'pk' => ['SupplierID'],
        'field_types' => [
            'SupplierID' => Fields\AutoIdField::class,
        ]
    ];
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

    function getQuantityShippable() {
        // XXX: Assumes overlay annotation
        return min($this->Quantity, $this->UnitsInStock);
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
        'field_types' => [
            'OrderID' => Fields\AutoIdField::class,
        ],
    ];

    function getTotal() {
        $total = 0;
        foreach ($this->items as $I)
            $total += $I->Quantity * $I->UnitPrice;
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
        'field_types' => [
            'CategoryID' => Fields\AutoIdField::class,
        ],
    ];
}

class Territory
extends Model\ModelBase {
    static $meta = [
        'table' => 'Territories',
        'pk' => ['TerritoryID'],
        'field_types' => [
            'TerritoryID' => Fields\AutoIdField::class,
        ],
    ];
}

class Region
extends Model\ModelBase {
    static $meta = [
        'table' => 'RegionID',
        'pk' => ['RegionID'],
        'field_types' => [
            'RegionID' => Fields\AutoIdField::class,
        ],
    ];
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
}

class EmployeeTerritory
extends Model\ModelBase {
    static $meta = [
        'table' => 'EmployeeTerritories',
        'pk' => ['EmployeeID', 'TerritoryID'],
        'field_types' => [
            'EmployeeID' => Fields\AutoIdField::class,
        ],
    ];
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
                'constraint' => ['CustomerTypeID' => 'CustomerDemographic.CustomerTypeID'],
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
