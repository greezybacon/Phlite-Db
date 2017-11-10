<?php

namespace Phlite\Db\Util;

class Field
extends Expression {
    var $field;

    function __construct($field) {
        $this->field = $field;
    }

    function toSql($compiler, $model=false, $alias=false) {
        list($field) = $compiler->getField($this->field, $model);
        return $field;
    }

    function __toString() {
        return sprintf('<%s>', $this->field);
    }
}
