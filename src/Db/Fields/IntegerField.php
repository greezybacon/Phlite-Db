<?php
namespace Phlite\Db\Fields;

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
