<?php
namespace Phlite\Db\Fields;

class DecimalField
extends BaseField {
    static $defaults = array(
        'scale' => false,
        'precision' => false,
    );
}