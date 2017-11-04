<?php

namespace Phlite\Db\Model;

use Phlite\Db\Compile\SqlCompiler;
use Phlite\Db\Exception;

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
     * Evaluate this entire Q against a record. The fields are checked
     * according to the criteria in this Q. Data in other records in a
     * result set (such as aggregate functions) are not supported.
     */
    function matches($record) {
        // Start with FALSE for OR and TRUE for AND
        $result = !$this->ored;
        foreach ($this->constraints as $field=>$check) {
            $R = ($check instanceof self)
                ? $check->matches($record)
                : SqlCompiler::evaluate($record, $field, $check);
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
