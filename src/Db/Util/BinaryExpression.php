<?php

namespace Phlite\Db\Util;

class BinaryExpression extends Expression {
    var $operator;
    var $operands;

    static $associative = array(
        '+' => ['+', '-'],
        '-' => ['+', '-'],
        '*' => ['*', '/'],
        '/' => ['*', '/']
    );

    function __construct($operator, $operands) {
        $this->operator = $operator;
        if (is_array($operands))
            $this->operands = $operands;
        else
            $this->operands = array_slice(func_get_args(), 1);
    }

    function toSql($compiler, $model=false, $alias=false, $lho=false) {
        $O = array();
        foreach ($this->operands as $operand) {
            if ($operand instanceof Expression)
                $O[] = $operand->toSql($compiler, $model, false, $this->operator);
            else
                $O[] = $compiler->input($operand);
        }
        $expr = implode(" {$this->operator} ", $O);

        // Emit parentheses if left-hand operator is not left associative
        // with this one
        if ($lho && (!isset(self::$associative[$lho]))
            || !($comm = self::$associative[$lho])
            || !in_array($this->operator, $comm)
        ) {
            $expr = " ($expr) ";
        }

        if ($alias)
            $expr .= ' AS '.$compiler->quote($alias);

        return $expr;
    }

    function __call($operator, $operands) {
        array_unshift($operands, $this);
        return static::__callStatic($operator, $operands);
    }

    static function __callStatic($op, $operands) {
        static $operators = array(
            'minus' =>  '-',
            'plus' =>   '+',
            'times' =>  '*',
            'bitand' => '&',
            'bitor' =>  '|',
        );
        $op = strtolower($op);
        if (isset($operators[$op]))
            return new static($operators[$op], $operatnds);

        throw new \InvalidArgumentException($operator.': Invalid operator specified');
    }

    function __toString() {
        return sprintf("(%s %s %s)",
            $this->operands[0], $this->operator, $this->operands[1]
        );
    }
}
