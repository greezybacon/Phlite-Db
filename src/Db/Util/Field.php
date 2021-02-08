<?php

namespace Phlite\Db\Util;

class Field
extends Expression {
    var $field;

    function __construct($field) {
        $this->field = $field;
    }

    function toSql($compiler, $model=false, $alias=false) {
        list($_, $fmodel, $transform) = $compiler->getField($this->field, $model);
        return $transform->buildLhs($compiler, $fmodel, null)
            . ($alias ? ' AS ' . $compiler->quote($alias) : '');
    }

    function __toString() {
        return sprintf('<%s>', $this->field);
    }
}
