<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Text;

class JSONField
extends TextField {
    function to_php($value, Backend $backend) {
        return is_string($value) ? json_decode($value) : $value;
    }

    function to_database($value, Backend $backend) {
        return json_encode($value);
    }

    function getJoinConstraint($field_name, $table, Backend $backend) {
        list($field, $path) = explode(':', $field_name, 2);
        return sprintf('json_extract(%s.%s, %s)', $table, $backend->quote($field), $path);
    }
}