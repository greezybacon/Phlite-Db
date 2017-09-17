<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Db\Compile\SqlCompiler;
use Phlite\Db\Compile\Transform;
use Phlite\Db\Exception;
use Phlite\Db\Model;

abstract class BaseField {
    static $defaults = array(
        'nullable' => true,
        'default' => null,
        'pk' => false,
    );
    static $transforms;

    var $options;

    function __construct(array $options=array()) {
        // Keep the defaults specified by the field type
        $this->options = $options + static::getDefaults();
    }

    static function getDefaults() {
        $defaults =  static::$defaults;
        if ($parent = get_parent_class(get_called_class()))
            $defaults += $parent::getDefaults();

        return $defaults;
    }

    /**
     * Convert a value from this field to a database value
     */
    function to_database($value, Backend $backend) {
        return $value;
    }

    /**
     * Convert a value from the database to a PHP value.
     */
    function to_php($value, Backend $backend) {
        return $value;
    }

    /**
     * Covert a PHP value to a format which is exportable. The ::from_export
     * method will be used to transform the same value back to what was
     * originally sent into this method.
     */
    function to_export($value) {
        return (string) $value;
    }

    /**
     * Convert a text value from an export into a PHP value which would be
     * used by normal code. The result should be the same value which was
     * passed to ::to_export() originally.
     */
    function from_export($value) {
        return $value;
    }

    /**
     * Get a presentation of the field value to use in a join constraint.
     * Normally this is just the field name itself, but some more complex 
     * fields might need to utilize a database function or something to get
     * a correct value for joins.
     */
    function getJoinConstraint($field_name, $table, SqlCompiler $compiler) {
        return sprintf("%s.%s", $table, $compiler->quote($field_name));
    }

    /**
     * Fetch a value from the local properties array (__ht__). Usually it is
     * a simple array lookup.
     */
    function extractValue($name, $props) {
        return $props[$name];
    }

    function getConstraints($name) {
        $constraints = [];
        if (isset($this->unique) && $this->unique)
            $constraints[] = new UniqueTogether([$name]);
        if (isset($this->index) && $this->index)
            $constraints[] = new IndexTogether([$name]);
        if (isset($this->pk) && $this->pk)
            $constraints[] = new PrimaryKey([$name]);
        return $constraints;
    }

    function __get($option) {
        return $this->options[$option];
    }

    function __isset($option) {
        return isset($this->options[$option]);
    }

    /**
     * Cooperate in a CREATE TABLE statement for SqlCompilers
     */
    function getCreateSql($name, $compiler) {
        return $compiler->visit($this);
    }

    // Transforms interface -----------------------------------
    /**
     * Register a transform/lookup for a field and all its subclasses.
     */
    static function registerTransform($class, $name=false) {
        static::$transforms[$name ?: $class::$name] = [get_called_class(), $class];
    }

    function getTransform($name, $lhs) {
        if (isset(static::$transforms[$name])) {
            list($type, $class) = static::$transforms[$name];
            // Transforms only apply to the field on which it was registered
            // and all subclasses.
            if ($this instanceof $type)
                return new $class($lhs);
        }

        throw new Exception\QueryError("$name: No such transform for type: ".get_class($this));
    }
}

// Load standard transforms
class ExactTransform
extends Transform {
    static $name = 'exact';
    static $template = '%s = %s';

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);
        return $lhs == $rhs;
    }
}

class LessTransform
extends Transform {
    static $name = 'lt';
    static $template = '%s < %s';

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);
        return $lhs < $rhs;
    }
}

class LessEqualTransform
extends Transform {
    static $name = 'lte';
    static $template = '%s <= %s';

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);
         return $lhs <= $rhs;
    }
}

class GreaterTransform
extends Transform {
    static $name = 'gt';
    static $template = '%s > %s';

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);
         return $lhs > $rhs;
    }
}

class GreaterEqualTransform
extends Transform {
    static $name = 'gte';
    static $template = '%s >= %s';

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);
         return $lhs >= $rhs;
    }
}

class IsNullTransform
extends Transform {
    static $name = 'isnull';

    function toSql($compiler, $model, $rhs) {
        $lhs = $this->buildLhs($compiler, $model);
        $rhs = $rhs ? 'IS NULL' : 'IS NOT NULL';
        return "{$lhs} $rhs";
    }

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);
         return is_null($lhs) == $rhs;
    }
}

class InTransform
extends Transform {
    static $name = 'in';
    static $template = '%s IN %s';

    function buildRhs($compiler, $model, $rhs) {
        if (is_array($rhs)) {
            $vals = array_map(array($compiler, 'input'), $rhs);
            return '('.implode(', ', $vals).')';
        }
        else {
            return parent::buildRhs($compiler, $model, $rhs);
        }
    }

    function toSql($compiler, $model, $rhs) {
        // MySQL is almost always faster with a join. Use one if possible
        // MySQL doesn't support LIMIT or OFFSET in subqueries. Instead, add
        // the query as a JOIN and add the join constraint into the WHERE
        // clause.
        if ($rhs instanceof Model\QuerySet
            && ($rhs->isWindowed() || $rhs->countSelectFields() > 1 || $rhs->chain)
        ) {
            if (count($rhs->values) < 1)
                throw new Exception\OrmError('Did you forget to specify a column with ->values()?');
            $f1 = array_values($rhs->values)[0];
            $view = $rhs->asView();
            $lhs = $this->buildLhs($compiler, $model);
            $alias = $compiler->pushJoin(spl_object_hash($view), $lhs, $view, array('constraint'=>array()));
            return sprintf('%s = %s.%s', $lhs, $alias, $compiler->quote($f1));
        }
        return parent::toSql($compiler, $model, $rhs);
    }

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);

        // Array
        if (is_array($rhs))
            return in_array($lhs, $rhs);
    }
}

class RangeTransform
extends Transform {
    static $name = 'range';
    static $template = '%s BETWEEN %s';

    function buildRhs($compiler, $model, $rhs) {
        if (!is_array($rhs) || count($rhs) != 2) {
            throw new Exception\QueryError('Range must be array of two items');
        }
        return sprintf('%s AND %s',
            $compiler->input($rhs[0]), $compiler->input($rhs[1]));
    }

    function evaluate($rhs, $lhs=null) {
        if ($this->lhs instanceof Transform)
            $lhs = $this->lhs->evaluate(null, $lhs);

        if (count($rhs) != 2)
            throw new Exception\QueryError('Range must be array of two items');

        return $lhs >= $rhs[0] && $lhs <= $rhs[1];
    }
}

BaseField::registerTransform(ExactTransform::class);
BaseField::registerTransform(GreaterTransform::class);
BaseField::registerTransform(GreaterEqualTransform::class);
BaseField::registerTransform(LessTransform::class);
BaseField::registerTransform(LessEqualTransform::class);
BaseField::registerTransform(IsNullTransform::class);
BaseField::registerTransform(InTransform::class);
BaseField::registerTransform(RangeTransform::class);