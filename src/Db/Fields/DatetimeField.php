<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Text;

class DateTimeField
extends TextField {
    static $defaults = array(
        'on_update_now' => false,
        'auto_now' => 10,
        'timezone' => false,
    );
}