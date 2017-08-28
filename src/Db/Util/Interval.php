<?php

namespace Phlite\Db\Util;

class Interval
extends Func {
    var $type;

    function __construct($interval, $args) {
        $this->type = $interval;
        parent::__construct($args)
    }

    function toSql($compiler, $model=false, $alias=false) {
        $A = $this->args[0];
        if ($A instanceof Expression)
            $A = $A->toSql($compiler, $model);
        else
            $A = $compiler->input($A);
        return sprintf('INTERVAL %s %s',
            $A,
            $this->func)
            . ($alias ? ' AS '.$compiler->quote($alias) : '');
    }

    static function __callStatic($interval, $args) {
        if (count($args) != 1) {
            throw new \InvalidArgumentException("Interval expects a single interval value");
        }
        return parent::__callStatic($interval, $args);
    }

    function __toString() {
        return sprintf("INTERVAL '%s' %s", $this->args[0], $this->type);
    }
}
