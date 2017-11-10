<?php
namespace Phlite\Test;

use Phlite\Db\Backends;
use Phlite\Db\Compile;
use Phlite\Test\Northwind;

class MockBackend
extends \Phlite\Db\Backend {
    function __construct(array $info) {}
    function connect() { return true; }

    /**
     * Gets a compiler compatible with this database engine that can compile
     * and execute a queryset or DML request.
     */
    function getCompiler($options=false) {}
    function getDdlCompiler($options=false) {}
    function getDriver(Compile\Statement $stmt) {}
}

class CompilerTest
extends \PHPUnit_Framework_TestCase {
    static function allCompilers() {
        $bk = new MockBackend([]);
        return [
            [new Backends\SQLite\Compiler($bk)],
            [new Backends\MySQL\Compiler($bk)],
        ];
    }
    
    /**
     * @dataProvider allCompilers
     */
    public function testExplodePath($compiler) {
        list($model, $alias, $path) = $compiler->explodePath(
            explode('__', 'sales__order__customer__exact'),
            Northwind\Product::class);
        
        $this->assertEquals(Northwind\Customer::class, $model);
        $this->assertEquals($path, ['CustomerID', 'exact']);
    }
}
