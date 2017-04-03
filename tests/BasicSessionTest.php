<?php
use Phlite\Db;
use Phlite\Test\Northwind;

class BasicSessionTest
extends \PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
    }
    
    function testCreateSession() {
        // Create a new product
        $product = new Northwind\Product([
            'ProductName'   => 'Prune Juice',
            'UnitPrice'     => 2.39,
        ]);
        
        $session = Db\Manager::getTransaction();

        $session->add($product);
        $this->assertNull($product->ProductID);

        $session->flush();
        $this->assertNotNull($product->ProductID);

        $this->assertTrue($session->commit());
    }

    function testSaveRelated() {
        $P = Northwind\Product::lookup(['ProductName' => 'Prune Juice']);
        $id = $P->ProductID;
        $this->assertNotNull($P);
        
        $category = new Northwind\Category([
            'CategoryName' => 'Juices',
            'Description' => 'Boxed and bottled juices',
        ]);
        $P->category = $category;
        $this->assertTrue($P->save());
        $this->assertNotNull($category->CategoryID);
        $this->assertNotNull($P->CategoryID);
    }

    function testSaveRelated_Session() {
        $product = new Northwind\Product([
            'ProductName' => 'Instant Pudding',
            'UnitPrice' => 2.79,
        ]);
        $category = new Northwind\Category([
            'CategoryName' => "Baking",
            'Description' => 'Flour, sugar, yeast, and other ingredients',
        ]);

        $session = Db\Manager::getTransaction();
        $session->add($product);
        $session->add($category);
        $session->flush();

        $this->assertNotNull($category->CategoryID);
        $this->assertNotNull($product->ProductID);
        $this->assertNotNull($product->CategoryID);
    }
}
