<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Db\Compile\SqlCompiler;

abstract class BaseField {
    static $defaults = array(
        'nullable' => true,
        'default' => null,
        'pk' => false,
    );
    var $options;

    function __construct(array $options=array()) {
        // Keep the defaults specified by the field type
        $this->options = $options + static::getDefaults();
    }

    static function getDefaults() {
        if ($parent = get_parent_class(get_called_class()))
            return static::$defaults + $parent::getDefaults();

        return static::$defaults;
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
    abstract function getCreateSql($name, $compiler);
}
