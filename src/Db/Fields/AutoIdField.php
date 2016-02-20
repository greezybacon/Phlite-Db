<?php
namespace Phlite\Db\Fields;

class AutoIdField
extends IntegerField {
    static $defaults=[
        'nullable' => false,
        'length' => null,
    ];
}
