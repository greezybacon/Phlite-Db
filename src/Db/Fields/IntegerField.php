<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Compile\Lookup;

class IntegerField
extends BaseField {
    static $defaults = array(
        'unsigned' => false,
        'length' => 10,
    );

    function to_export($value) {
        return (string) $value;
    }

    function from_export($value) {
        return (int) $value;
    }
}

class HasbitTransform
extends Lookup {
    static $name = 'hasbit';
    static $template = '%s & %s != 0';

    function evaluate($rhs, $lhs) {
         return ($lhs & $rhs) == $rhs;
    }
}

IntegerField::registerTransform(HasbitTransform::class);