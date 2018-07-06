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
    function testSessionRemoveRollback() {
        $session = Db\Manager::getSession();
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
    function testSessionUpdateRevert() {
        $session = Db\Manager::getSession();

        $P = Northwind\Product::objects()->lookup(['ProductID' => 34]);
        $P->ProductName .= " and stuff";
        $session->add($P);
        $this->assertEquals('Sasquatch Ale and stuff', $P->ProductName);
        
        $session->revert();

        $this->assertEquals('Sasquatch Ale', $P->ProductName);
    }

    function testUndoCommittedEdit() {
        $session = Db\Manager::getSession();

        $P = Northwind\Product::objects()->lookup(['ProductID' => 35]);
        $this->assertEquals('24 - 12 oz bottles', $P->QuantityPerUnit);

        $P->QuantityPerUnit = "24 - 13.3 oz bottles";
        $session->add($P);
        $this->assertTrue($session->commit());

        $P = Northwind\Product::objects()->lookup(['ProductID' => 35]);
        $this->assertEquals('24 - 13.3 oz bottles', $P->QuantityPerUnit);

        $session->undoCommit();
        $session->flush();
        $this->assertEquals('24 - 12 oz bottles', $P->QuantityPerUnit);

        // Save the original result
        $this->assertTrue($session->commit());
    }

    function testUndoCommittedDelete() {
        $session = Db\Manager::getSession();

        $P = Northwind\Product::objects()->lookup(['ProductID' => 37]);
        $session->remove($P);
        $this->assertTrue($session->commit());

        try {
            $P = Northwind\Product::objects()->lookup(['ProductID' => 37]);
        }
        catch (Exception\DoesNotExist $e) {}
        $this->assertNotNull($e);

        $session->undoCommit();
        $session->flush();

        $P = Northwind\Product::objects()->lookup(['ProductID' => 37]);
        $this->assertEquals('Gravad lax', $P->ProductName);

        // Save the original result
        $this->assertTrue($session->commit());
    }
}