<?php

namespace Phlite\Db\Util;

class Field
extends Expression {
    var $level;
    var $field;

    function __construct($field, $level=0) {
        $this->field = $field;
        $this->level = $level;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $L = $this->level;
        while ($L--)
            $compiler = $compiler->getParent();
        list($field) = $compiler->getField($this->field, $model);
        return $field;
    }

    function __toString() {
        return sprintf('<%s>', $this->field);
    }
}
