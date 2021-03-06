<?php
namespace Phlite\Db\Util;

use Phlite\Db\Fields;

/**
 * Base expression class for SQL expressions in ORM queries. Expressions are
 * chainable using the BinaryExpression class, which is setup automatically
 * for calls on the Expression instance matching operator names defined in
 * the BinaryExpression class. For example
 *
 * >>> Func::NOW()->minus(Interval::MINUTE(5))
 * (NOW() - INTERVAL '5' MINUTE)
 *
 * Other more useful expression types extend from this base class
 *   - Aggregate - Use an aggregate function (eg. SUM) in a query
 *   - Field - Use a field of a model in a query
 *   - Func - Call a function on the DB server in a query (eg. NOW)
 *   - Interval - Use a date interval in a query
 *   - SqlCase - Use a CASE WHEN ... END in a query
 *   - SqlCode - Add arbitrary SQL to a query
 *
 * TODO: Add `evaluate` support for the SqlCompiler::evaluate method
 */
class Expression {
    protected $args;

    function __construct(...$args) {
        // One day—probably not—but maybe one day, PHP will support keyword
        // arguments
        if (is_array($args[0]))
            $args = $args[0];
        $this->args = $args;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $O = array();
        foreach ($this->args as $field=>$value) {
            if ($value instanceof self) {
                $O[] = $value->toSql($compiler, $model);
            }
            elseif ($value instanceof Q) {
                $ex = $compiler->compileQ($value, $model);
                $O[] = $ex->text;
            }
            else {
                list($field, , $transform) = $compiler->getField($field, $model);
                $O[] = $transform->toSql($compiler, $model, $value);
            }
        }
        return implode(' ', $O) . ($alias ? ' AS ' . $compiler->quote($alias) : '');
    }
    
    function getTransform($name, $field) {
        $f = new Fields\IntegerField();
        return $f->getTransform($name, $this);
    }

    // Allow $function->plus($something)
    function __call($operator, $operands) {
        array_unshift($operands, $this);
        return BinaryExpression::__callStatic($operator, $operands);
    }
}
