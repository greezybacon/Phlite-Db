<?php

namespace Phlite\Db\Util;

class Func
extends Expression {
    var $func;

    function __construct($name, ...$args) {
        $this->func = $name;
        parent::__construct(...$args);
    }

    function input($what, $compiler, $model) {
        if ($what instanceof Expression)
            $A = $what->toSql($compiler, $model);
        elseif ($what instanceof Q)
            $A = $compiler->compileQ($what, $model);
        else
            $A = $compiler->input($what);
        return $A;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $args = array();
        foreach ($this->args as $A)
            $args[] = $this->input($A, $compiler, $model);
        return sprintf('%s(%s)%s', $this->func, implode(', ', $this->args),
            $alias ? ' AS '.$compiler->quote(alias) : '');
    }

    static function __callStatic($func, $args) {
        $I = new static($func);
        $I->args = $args;
        return $I;
    }

    function __toString() {
        return sprintf("%s(%s)",
            $this->func, implode(", ", $this->args)
        );
    }
}
