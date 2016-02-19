<?php

namespace Phlite\Db\Util;

use Phlite\Db\Compile\SqlCompiler;
use Phlite\Db\Exception;
use Phlite\Db\Model;

class Q
implements \Serializable {
    const NEGATED = 0x0001;
    const ANY =     0x0002;

    var $constraints;
    var $negated = false;
    var $ored = false;

    function __construct($filter, $flags=0) {
        if (!is_array($filter))
            $filter = array($filter);
        $this->constraints = $filter;
        $this->negated = $flags & self::NEGATED;
        $this->ored = $flags & self::ANY;
    }

    function isNegated() {
        return $this->negated;
    }

    function isOred() {
        return $this->ored;
    }

    function negate() {
        $this->negated = !$this->negated;
        return $this;
    }

    function union() {
        $this->ored = true;
    }

    function add($constraints) {
        if (is_array($constraints))
            $this->constraints = array_merge($this->constraints, $constraints);
        elseif ($constraints instanceof static)
            $this->constraints[] = $constraints;
        else
            throw new \InvalidArgumentException('Expected an instance of Q or an array thereof');
        return $this;
    }

    /**
     * Check if the values match given the operator.
     *
     * Throws:
     * OrmException - if $operator is not supported
     */
    static function evaluate($record, $field, $check) {
        static $ops; if (!isset($ops)) { $ops = array(
            'exact' => function($a, $b) { return is_string($a) ? strcasecmp($a, $b) == 0 : $a == $b; },
            'isnull' => function($a, $b) { return is_null($a) == $b; },
            'gt' => function($a, $b) { return $a > $b; },
            'gte' => function($a, $b) { return $a >= $b; },
            'lt' => function($a, $b) { return $a < $b; },
            'lte' => function($a, $b) { return $a <= $b; },
            'range' => function($a, $b) { return $a >= $b[0] && $a <= $b[1]; },
            'contains' => function($a, $b) { return stripos($a, $b) !== false; },
            'startswith' => function($a, $b) { return stripos($a, $b) === 0; },
            'endswith' => function($a, $b) { return $b === '' || strcasecmp(substr($a, -strlen($b))) === 0; },
            'regex' => function($a, $b) { return preg_match("/$a/iu", $b); },
            'hasbit' => function($a, $b) { return ($a & $b) == $b; },
        ); }
        list($field, $path, $operator) = SqlCompiler::splitCriteria($field);
        if (!isset($ops[$operator]))
            throw new Exception\OrmError($operator.': Unsupported operator');

        if ($record instanceof Model\ModelBase) {
            if ($path)
                $record = $record->getByPath($path);
            $field = $record->get($field);
        }
        else {
            $field = $record[$field];
        }

        return $ops[$operator]($field, $check);
    }

    /**
     * Evaluate this entire Q against a record. The fields are checked
     * according to the criteria in this Q. Data in other records in a
     * result set (such as aggregate functions) are not supported.
     */
    function matches(array $record) {
        // Start with FALSE for OR and TRUE for AND
        $result = !$this->ored;
        foreach ($this->constraints as $field=>$check) {
            $R = ($check instanceof self)
                ? $check->matches($record)
                : static::evaluate($record, $field, $check);
            if ($this->ored) {
                if ($result |= $R)
                    break;
            }
            // Anything AND false
            elseif (!$R) {
                $result = false;
                break;
            }
        }
        return $this->negated ? !$result : $result;
    }

    static function not($constraints) {
        return new static($constraints, self::NEGATED);
    }

    static function any($constraints) {
        return new static($constraints, self::ANY);
    }

    static function all($constraints) {
        return new static($constraints);
    }

    function serialize() {
        return serialize(array($this->negated, $this->ored, $this->constraints));

    }

    function unserialize($data) {
        list($this->negated, $this->ored, $this->constraints) = unserialize($data);
    }
}
