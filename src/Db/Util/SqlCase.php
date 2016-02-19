<?php

namespace Phlite\Db\Util;

class SqlCase
extends Func {
    var $cases = array();
    var $else = false;

    static function N() {
        return new static('CASE');
    }

    function when($expr, $result) {
        if (is_array($expr))
            $expr = new Q($expr);
        $this->cases[] = array($expr, $result);
        return $this;
    }
    function otherwise($result) {
        $this->else = $result;
        return $this;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $cases = array();
        foreach ($this->cases as $A) {
            list($expr, $result) = $A;
            $expr = $this->input($expr, $compiler, $model);
            $result = $this->input($result, $compiler, $model);
            $cases[] = "WHEN {$expr} THEN {$result}";
        }
        if ($this->else) {
            $else = $this->input($this->else, $compiler, $model);
            $cases[] = "ELSE {$else}";
        }
        return sprintf('CASE %s END%s', implode(' ', $cases),
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }
}
