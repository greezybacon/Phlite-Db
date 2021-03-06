<?php
namespace Phlite\Test\Northwind;

use Phlite\Db\Model\Schema\SchemaBuilder;
use Phlite\Db\Fields;

class ProductWithData
extends Product {
    static $meta = [];
    static function buildSchema(SchemaBuilder $builder) {
        parent::buildSchema($builder);
        $builder->addField('data', new Fields\JSONField([
            'object' => true]));
    }
}

namespace Test\JsonFieldTest;

use Phlite\Db;
use Phlite\Db\Migrations;
use Phlite\Test\Northwind;
use Phlite\Db\Fields;
use Phlite\Db\Util;

class JSONFieldTest
extends \PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        // Migrate the customer table to have a json field
        Db\Manager::migrate(new class
        extends Migrations\Migration {
            function getOperations() {
                return [
                    new Migrations\AlterModel(Northwind\Product::class,
                    function($editor) {
                        $editor->addField('data', new Fields\JSONField())->last();
                    }),
                ];
            }
        });
    }

    function testJSONAlter() {
        $fields = Northwind\ProductWithData::getMeta()->getFields();
        $this->assertArrayHasKey('data', $fields);
    }

    function testJSONUpdate() {
        $gummibears = Northwind\ProductWithData::objects()->lookup(26);
        $gummibears->data = ['CountryOfOrigin' => 'Germany'];
        $this->assertFalse($gummibears->__new__);
        $this->assertTrue($gummibears->save());

        $this->assertEquals(1, Northwind\ProductWithData::objects()
            ->filter(['ProductID' => 27])
            ->update(['data' => ['CountryOfOrigin' => 'Switzerland']])
        );
    }

    /**
     * @depends testJSONUpdate
     */
    function testJSONFetch() {
        $shoggi = Northwind\ProductWithData::objects()
            ->filter(['ProductID' => 27])->one();
        $this->assertInternalType('object', $shoggi->data);
        $this->assertEquals('Switzerland', $shoggi->data->CountryOfOrigin);
    }

    /**
     * @depends testJSONUpdate
     */
    function testJSONAnnotate() {
        $german = Northwind\ProductWithData::objects()
            ->annotate(['coa' => new Util\Field('data__CountryOfOrigin')])
            ->filter(['ProductID' => 26])
            ->one();

        $this->assertEquals('Germany', $german->coa);
    }

    /**
     * @depends testJSONUpdate
     */
    function testJSONFilter() {
        $german = Northwind\ProductWithData::objects()
            ->filter(['data__CountryOfOrigin' => 'Germany']);
        $this->assertCount(1, $german);
    }
}
