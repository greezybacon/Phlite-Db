<?php
namespace Phlite\Db\Compile;

/**
 * A field transformation used in filter expressions
 */
abstract class Lookup
extends Transform {
    function getOutputFieldType() {
        return IntegerField::class;
    } 
}