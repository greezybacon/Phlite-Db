<?php
namespace Phlite\Db\Fields;

class DecimalField
extends BaseField {
    static $defaults = array(
        'scale' => false,
        'precision' => false,
    );

    function to_export($value) {
        return (string) $value;
    }

    function from_export($value) {
        return (float) $value;
    }
}