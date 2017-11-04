<?php
namespace Phlite\Test;

use Phlite\Db;
use Phlite\Db\Exception;
use Phlite\Test\Northwind;

class TransactionTest
extends \PHPUnit_Framework_TestCase {
    function tearDown() {
        // Will trigger an error if a session is left open
        Db\Manager::getSession()->reset();
    }

    // Database-layer tests -----------------------------------
    function testSessionRollback() {
        $session = Db\Manager::getSession();
        $session->reset();
        $P = Northwind\Product::objects()->lookup(['ProductID' => 23]);
        $session->remove($P);
        $session->flush();
        
        try {
            Northwind\Product::objects()->lookup(['ProductID' => 23]);
            // XXX: Fail here
        }
        catch (Exception\DoesNotExist $e) {
            // pass
        }
        
        $this->assertTrue($session->rollback());
        $this->assertNotNull(Northwind\Product::objects()->lookup(['ProductID' => 23]));
    }
    
    // TransactionLog tests -----------------------------------
    function testSessionRestore() {
        $session = Db\Manager::getSession();
        $session->reset();
        $P = Northwind\Product::objects()->lookup(['ProductID' => 34]);
        $P->ProductName .= " and stuff";
        $session->add($P);
        $this->assertEquals('Sasquatch Ale and stuff', $P->ProductName);
        
        $session->revert();
        
        $this->assertEquals('Sasquatch Ale', $P->ProductName);
    }
}