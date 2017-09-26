<?php
namespace Phlite\Db\Compile;

use Phlite\Db\Fields\BaseField;
use Phlite\Db\Fields\IntegerField;
use Phlite\Db\Util;

/**
 * Interface between the compiler field lookup and comparison methods like
 * case-insensitive search, less-than, etc.
 */
abstract class Transform {
    protected $lhs;
    
    static $name;
    static $template;

    function __construct($lhs) {
        $this->lhs = $lhs;
    }

    function buildLhs($compiler, $model, $lhs=false) {
        $lhs = $lhs ?: $this->lhs;
        if ($lhs instanceof Util\Expression)
            return $lhs->toSql($compiler, $model);
        if ($lhs instanceof self)
            return $lhs->toSql($compiler, $model, null);

        return $lhs;
    }
    
    function buildRhs($compiler, $model, $rhs) {
        return $compiler->input($rhs, $model);
    }
    
    function isAggregate() {
        if ($this->lhs instanceof self)
            return $this->lhs->isAggregate();

        return $this->lhs instanceof Util\Aggregate;
    }
    
    function toSql($compiler, $model, $rhs) {
        $lhs = $this->buildLhs($compiler, $model);
        $rhs = $this->buildRhs($compiler, $model, $rhs);
        return sprintf(static::$template, $lhs, $rhs);
    }
    
    function getTransform($name, $lhs) {
        $class = $this->getOutputFieldType();
        $field = new $class();
        // Only apply alias to outer-most transform
        return $field->getTransform($name, $this);
    }
    
    /**
     * Perform the transformation in PHP. This would be used as a complement
     * to ::toSQL where the result would be consumed by PHP code rather than
     * the database.
     */
    function transform($rhs, $lhs) {
        if ($this->lhs instanceof self)
            $lhs = $this->lhs->transform(null, $lhs);
        return $this->evaluate($rhs, $lhs);
    }

    abstract function evaluate($rhs, $lhs);
    abstract function getOutputFieldType();
}