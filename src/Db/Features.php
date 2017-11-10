<?php
namespace Phlite\Db;

class Features
implements \ArrayAccess {
    static $defaults = [
        'has_interval_math' => true,
        'has_extract_function' => true,
    ];
    protected $features = array();

    function __construct(array $features=array()) {
        $this->features = $features + static::$defaults;
    }

    function get($name) {
        $me = new \ReflectionClass($this);

        if (isset($this->features[$name]))
            return $this->features[$name];
        elseif ($me->hasMethod($name))
            return $this->{$name}();
        elseif ($me->hasProperty($name))
            return $this->{$name};
        else
            throw new \InvalidArgumentException(
                $name.": No such feature defined for this database backend");
    }
    function __get($name) {
        return $this->get($name);
    }

    function offsetGet($offset) {
        return $this->__get($offset);
    }
    function offsetExists($offset) {
        try {
            $this->__get($offset);
            return true;
        }
        catch (\InvalidArgumentException $e) {
            return false;
        }
    }
    function offsetSet($offset, $value) {
        throw new \Exception('Features are read-only');
    }
    function offsetUnset($offset) {
        throw new \Exception('Features are read-only');
    }
}
