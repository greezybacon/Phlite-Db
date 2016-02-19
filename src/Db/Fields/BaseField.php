<?php

namespace Phlite\Db\Fields;

abstract class BaseField {
    static $defaults = array();
    var $options;

    function __construct(array $options) {
        // Keep the defaults specified by the field type
        $this->options = $options + static::$defaults;
    }

    /**
     * Convert a value from this field to a database value
     */
    function to_database($value, $compiler=false) {
        return $value;
    }

    /**
     * Convert a value from the database to a PHP value.
     */
    function to_php($value, $connection) {
        return $value;
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
    abstract function getCreateSql($name, $compiler);
}
