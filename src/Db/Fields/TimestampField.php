<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Text;

class TimestampField
extends DateTimeField {
    static $defaults = array(
        'precision' => 6,
    );
}