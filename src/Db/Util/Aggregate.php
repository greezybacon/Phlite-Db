<?php

namespace Phlite\Db\Util;

class Aggregate
extends Func {

    var $expr;
    var $distinct=false;
    var $constraint=false;

    function __construct($func, $expr, $distinct=false, $constraint=false) {
        $this->func = $func;
        $this->expr = $expr;
        $this->distinct = $distinct;
        if ($constraint instanceof Q)
            $this->constraint = $constraint;
        elseif ($constraint)
            $this->constraint = new Q($constraint);
    }

    static function __callStatic($func, $args) {
        $distinct = @$args[1] ?: false;
        $constraint = @$args[2] ?: false;
        return new static($func, $args[0], $distinct, $constraint);
    }

    function toSql($compiler, $model=false, $alias=false) {
        $options = array('constraint' => $this->constraint, 'model' => true);

        // For DISTINCT, require a field specification — not a relationship
        // specification.
        $E = $this->expr;
        if ($E instanceof SqlFunction) {
            $field = $E->toSql($compiler, $model);
        }
        else {
            list($field, $rmodel) = $compiler->getField($E, $model, $options);
            if ($this->distinct) {
                $pk = false;
                $fpk  = $rmodel::getMeta('pk');
                foreach ($fpk as $f) {
                    $pk |= false !== strpos($field, $f);
                }
                if (!$pk) {
                    // Try and use the foriegn primary key
                    if (count($fpk) == 1) {
                        list($field) = $compiler->getField(
                            $this->expr . '__' . $fpk[0],
                            $model, $options);
                    }
                    else {
                        throw new OrmException(
                            sprintf('%s :: %s', $rmodel, $field) .
                            ': DISTINCT aggregate expressions require specification of a single primary key field of the remote model'
                        );
                    }
                }
            }
        }

        return sprintf('%s(%s%s)%s', $this->func,
            $this->distinct ? 'DISTINCT ' : '', $field,
            $alias && $this->alias ? ' AS '.$compiler->quote($this->alias) : '');
    }

    function getFieldName() {
        return strtolower(sprintf('%s__%s', $this->args[0], $this->func));
    }
}
