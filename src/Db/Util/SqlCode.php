<?php

namespace Phlite\Db\Util;

class SqlCode
extends Expression {
    function __construct($code) {
        $this->code = $code;
    }

    function toSql($compiler, $model=false, $alias=false) {
        return $this->code.($alias ? ' AS '.$alias : '');;
    }
}
