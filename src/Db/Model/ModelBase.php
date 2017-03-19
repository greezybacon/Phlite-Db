<?php

namespace Phlite\Db\Model;

use Phlite\Db\Exception;
use Phlite\Db\Manager;
use Phlite\Signal;
use Phlite\Util;

abstract class ModelBase {  
    static $metaclass = __NAMESPACE__ . '\ModelMeta';
    static $meta = array(
        'table' => false,
        'ordering' => false,
        'pk' => false
    );

    var $__ht__ = array();
    var $__dirty__ = array();
    var $__new__ = false;
    var $__deferred__ = array();

    function __construct(array $row=array()) {
        $this->__new__ = true;
        foreach ($row as $field=>$value)
            $this->set($field, $value);
    }

    /**
     * Creates a new instance of the model without calling the constructor.
     * If the constructor is required, consider using the PHP `new` keyword.
     * The instance returned from this method will not be considered *new*
     * and will imply an UPDATE when sent to the database.
     */
    static function __hydrate($row=false) {
        return static::getMeta()->newInstance($row);
    }

    function get($field, $default=false) {
        if (array_key_exists($field, $this->__ht__)) {
            return $this->__ht__[$field];
        }
        elseif (($joins = static::getMeta('joins')) && isset($joins[$field])) {
            $j = $joins[$field];
            // Support instrumented lists and such
            if (isset($j['list']) && $j['list']) {
                $class = $j['fkey'][0];
                $meta = static::getMeta();
                // Localize the foreign key constraint
                foreach ($j['constraint'] as $local=>$foreign) {
                    list($_klas, $F) = $foreign;
                    // Split by colons for complex field name expressions
                    list($fname, ) = explode(':', $local, 2);
                    $fkey[$F ?: $_klas] = ($local[0] == "'")
                        ? trim($local, "'")
                        : $meta->getField($fname)->extractValue($local, $this->__ht__);
                }
                $v = $this->__ht__[$field] = new $j['broker'](
                    // Send Model, [Foriegn-Field => Local-Id]
                    array($class, $fkey)
                );
                return $v;
            }
            // Support relationships
            elseif (isset($j['fkey'])) {
                $criteria = array();
                foreach ($j['constraint'] as $local => $foreign) {
                    list(, $F) = $foreign;
                    if ($local[0] == "'") {
                        $criteria[$F] = trim($local,"'");
                    }
                    elseif ($F[0] == "'") {
                        // Does not affect the local model
                        continue;
                    }
                    else {
                        if (!isset($this->__ht__[$local]))
                            // NULL foreign key
                            return null;
                        $criteria[$F] = $this->__ht__[$local];
                    }
                }
                try {
                    $class = $j['fkey'][0];
                    $v = $this->__ht__[$field] = $class::lookup($criteria);
                }
                catch (Exception\DoesNotExist $e) {
                    $v = null;
                }
                return $v;
            }
        }
        elseif (isset($this->__deferred__[$field])) {
            // Fetch deferred field
            $row = static::objects()->filter($this->getPk())
                // FIXME: Seems like all the deferred fields should be fetched
                ->values_flat($field)
                ->one();
            if (!$row)
                throw new \RuntimeError(sprintf('%s: Unable to fetch deferred field', $field));
            unset($this->__deferred__[$field]);
            return $this->__ht__[$field] = $row[0];
        }
        elseif ($field == 'pk') {
            return $this->getPk();
        }

        if (isset($default))
            return $default;

        // For new objects, assume the field is NULLable
        if ($this->__new__)
            return null;

        // Check to see if the column referenced is actually valid
        if (in_array($field, static::getMeta('fields')))
            return null;

        throw new Exception\OrmError(sprintf('%s: %s: Field not defined',
            get_class($this), $field));
    }
    function __get($field) {
        return $this->get($field, null);
    }

    function getByPath($path) {
        if (is_string($path))
            $path = explode('__', $path);
        $root = $this;
        foreach ($path as $P)
            if (!($root = $root->get($P)))
                break;
        return $root;
    }

    function __isset($field) {
        return array_key_exists($field, $this->__ht__)
            || isset(static::$meta['joins'][$field]);
    }
    function __unset($field) {
        if ($this->__isset($field))
            unset($this->__ht__[$field]);
        else
            unset($this->{$field});
        unset($this->__dirty__[$field]);
    }

    function set($field, $value) {
        // Update of foreign-key by assignment to model instance
        $related = false;
        $joins = static::getMeta('joins');
        if (isset($joins[$field])) {
            $j = $joins[$field];
            if ($j['list'] && ($value instanceof InstrumentedList)) {
                // Magic list property
                $this->__ht__[$field] = $value;
                return;
            }
            if ($value === null) {
                $this->__ht__[$field] = $value;
                if (in_array($j['local'], static::$meta['pk'])) {
                    // Reverse relationship — don't null out local PK
                    return;
                }
                // Pass. Set local field to NULL in logic below
            }
            elseif ($value instanceof $j['fkey'][0]) {
                // Capture the object under the object's field name
                $this->__ht__[$field] = $value;
                if ($value->__new__)
                    // save() will be performed when saving this object
                    $value = null;
                else
                    $value = $value->get($j['fkey'][1]);
                // Fall through to the standard logic below
            }
            else
                throw new \InvalidArgumentException(
                    sprintf('Expecting NULL or instance of %s. Got a %s instead',
                    $j['fkey'][0], is_object($value) ? get_class($value) : gettype($value)));

            // Capture the foreign key id value
            $field = $j['local'];
        }
        // elseif $field is in a relationship, adjust the relationship
        elseif (($fks = static::getMeta('foreign_keys')) && isset($fks[$field])) {
            // meta->foreign_keys->{$field} points to the property of the
            // foreign object. For instance 'object_id' points to 'object'
            $related = $fks[$field];
        }
        $old = isset($this->__ht__[$field]) ? $this->__ht__[$field] : null;
        if ($old != $value) {
            // isset should not be used here, because `null` should not be
            // replaced in the dirty array
            if (!array_key_exists($field, $this->__dirty__))
                $this->__dirty__[$field] = $old;
            if ($related)
                // $related points to a foreign object propery. If setting a
                // new object_id value, the relationship to object should be
                // cleared and rebuilt
                unset($this->__ht__[$related]);
        }
        $this->__ht__[$field] = $value;
    }
    function __set($field, $value) {
        return $this->set($field, $value);
    }

    function setAll($props) {
        foreach ($props as $field=>$value)
            $this->set($field, $value);
    }

    function __clone() {
        $this->__new__ = true;
        $this->__deleted__ = false;
        foreach (static::$meta['pk'] as $f)
            $this->__unset($f);
        $this->__dirty__ = array_fill_keys(array_keys($this->__ht__), null);
    }

    function __onload() {}
    static function __oninspect() {}

    static function _inspect() {
        $mc = static::$metaclass;
        static::$meta = new $mc(static::class);

        // Let the model participate
        static::__oninspect();
    }

    static function getMeta($key=false) {
        if (!static::$meta instanceof static::$metaclass)
            static::_inspect();
        $M = static::$meta;
        return ($key) ? $M->offsetGet($key) : $M;
    }

    /**
     * objects
     *
     * Retrieve a QuerySet for this model class which can be used to fetch
     * models from the connected database. Subclasses can override this
     * method to apply forced constraints on the QuerySet.
     */
    static function objects() {
        return new QuerySet(get_called_class());
    }

    /**
     * lookup
     *
     * Retrieve a record by its primary key. This method may be short
     * circuited by model caching if the record has already been loaded by
     * the database. In such a case, the database will not be consulted for
     * the model's data.
     *
     * This method can be called with an array of keyword arguments matching
     * the PK of the object or the values of the primary key. Both of these
     * usages are correct:
     *
     * >>> User::lookup(1)
     * >>> User::lookup(array('id'=>1))
     *
     * For composite primary keys and the first usage, pass the values in
     * the order they are given in the Model's 'pk' declaration in its meta
     * data. For example:
     *
     * >>> UserPrivilege::lookup(1, 2)
     *
     * Parameters:
     * $criteria - (mixed) primary key for the sought model either as
     *      arguments or key/value array as the function's first argument
     *
     * Returns:
     * (Object<ModelBase>|null) a single instance of the sought model or
     * null if no such instance exists.
     *
     * Throws:
     * Db\Exception\NotUnique if the criteria does not hit a single object
     */
    static function lookup($criteria) {
        // Model::lookup(1), where >1< is the pk value
        if (!is_array($criteria)) {
            $criteria = array();
            $pk = static::getMeta('pk');
            foreach (func_get_args() as $i=>$f)
                $criteria[$pk[$i]] = $f;

            // Only consult cache for PK lookup, which is assumed if the
            // values are passed as args rather than an array
            if ($cached = ModelInstanceManager::checkCache(get_called_class(),
                    $criteria))
                return $cached;
        }

        return static::objects()->filter($criteria)->one();
    }

    private function getPk() {
        $pk = array();
        foreach ($this::getMeta('pk') as $f)
            $pk[$f] = $this->__ht__[$f];
        return $pk;
    }

    function __toString() {
        $a = new Util\ArrayObject($this->getPk());
        return sprintf('<%s %s>', get_class($this), $a->join('=',', '));
    }
}
