<?php
namespace Phlite\Db\Model;

use Phlite\Db\Exception;
use Phlite\Db\Manager;
use Phlite\Db\Signals;
use Phlite\Util;

abstract class ModelBase {  
    static $metaclass = ModelMeta::class;
    static $manager = ModelManager::class;
    static $meta = array(
        'table' => false,
        'ordering' => false,
        'pk' => false
    );

    var $__ht__ = array();
    var $__dirty__ = array();
    var $__new__ = false;
    var $__deferred__ = array();
    var $__deleted__ = false;

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

    function get($field, $default=null) {
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
                    $fkey[$F ?: $_klas] = ($local[0] == "'")
                        ? trim($local, "'")
                        : $this->__ht__[$local];
                }
                $v = $this->__ht__[$field] = new $j['broker'](
                    // Send [ForeignModel, [Foriegn-Field => Local-Id], JoinInfo]
                    array($class, $fkey, $j)
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
                $class = $j['fkey'][0];
                $v = $this->__ht__[$field] = $class::objects()->lookup($criteria);
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
        if (in_array($field, static::getMeta()->getFieldNames()))
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
        if (array_key_exists($field, $this->__ht__))
            return true;
        
        $meta = static::getMeta();
        if (isset($meta['joins'][$field])
            || isset($meta->getFields()[$field])
        ) {
            return true;
        }
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
        // elseif $field is part of a relationship, adjust the relationship
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

    static function getMeta($key=null) {
        if (!static::$meta instanceof static::$metaclass)
            static::_inspect();
        $M = static::$meta;
        return isset($key) ? $M->offsetGet($key) : $M;
    }

    static function buildSchema(SchemaBuilder $builder) {
        return static::getMeta()->getFields(false);
    }

    /**
     * objects
     *
     * Retrieve a QuerySet for this model class which can be used to fetch
     * models from the connected database. Subclasses can override this
     * method to apply forced constraints on the QuerySet.
     */
    static function objects() {
        $M = static::$manager;
        return new $M(get_called_class());
    }

    private function getPk() {
        $pk = array();
        foreach (static::getMeta('pk') as $f)
            $pk[$f] = $this->__ht__[$f];
        return $pk;
    }

    // ActiveRecord pattern -----------------------------------

    /**
     * Drop this record from the database. Returns TRUE if the drop was
     * successful according to the database and FALSE otherwise.
     *
     * Signals:
     * `model.deleted` after successful delete
     */
    function delete() {
        try {
            $ex = static::objects()->deleteModel($this);
            if ($ex === false)
                return false;

            $this->__deleted__ = true;
            Signals\ModelDeleted::send($this);
        }
        catch (Exception\DbError $e) {
            return false;
        }
        return true;
    }

    /**
     * Commit changes made to this model to the database. Returns TRUE if the
     * model was successfully persisted to the database and FALSE otherwise.
     *
     * Caveats:
     * If a relationship property of this model is associated with a foreign
     * *new* object, then those objects will be saved and the keys will be
     * updated in this model locally before this model is saved. Then, after
     * this model is saved, if this object has relationships where the primary
     * key of this model is a foreign key for a related model, the primary key
     * value is automatically set in the foreign models.
     *
     * Signals:
     * `model.updated` - if an existing record was updated for this model
     * `model.created` - if a new record was inserted for this model
     */
    function save($refetch=false) {
        if ($this->__deleted__)
            throw new Exception\OrmError('Trying to update a deleted object');

        // Attempt to update foreign, unsaved objects with the PK of this
        // newly created object
        $pk = static::getMeta('pk');
        $wasnew = $this->__new__;

        // First, if any foreign properties of this object are connected to
        // another *new* object, then save those objects first and set the
        // local foreign key field values
        foreach (static::getMeta('joins') as $prop => $j) {
            if (isset($this->__ht__[$prop])
                && ($foreign = $this->__ht__[$prop])
                && $foreign instanceof self
                && !in_array($j['local'], $pk)
                && null === $this->get($j['local'])
            ) {
                if ($foreign->__new__ && !$foreign->save())
                    return false;
                $this->set($j['local'], $foreign->get($j['fkey'][1]));
            }
        }

        // If there's nothing in the model to be saved, then we're done
        if (count($this->__dirty__) === 0)
            return true;

        try {
            $ex = static::objects()->saveModel($this);
            if ($ex === false) {
                // This doesn't really signify an error. It just means that
                // the database believes that the row did not change. For
                // inserts though, it's a deal breaker
                if ($wasnew) {
                    return false;
                }
                // No need to reload the record if requested — the database
                // didn't update anything
                $refetch = false;
            }
        }
        catch (Exception\OrmError $e) {
            return false;
        }

        // Reset anything marked dirty as it is not synced with the database
        $this->__dirty__ = array();

        if ($wasnew) {
            // XXX: Ensure AUTO_INCREMENT is set for the field
            if (count($pk) === 1 && !$refetch) {
                $key = $pk[0];
                $id = $ex->insert_id();
                if (!isset($this->__ht__[$key]) && $id)
                    $this->__ht__[$key] = $id;
            }
            $this->__new__ = false;
            Signals\ModelCreated::send($this, $data);
        }
        else {
            $data = array('dirty' => $this->__dirty__);
            Signals\ModelUpdated::send($this, $data);
        }
        # Refetch row from database
        if ($refetch) {
            // Preserve non database information such as list relationships
            // across the refetch
            $this->__ht__ = static::objects()->filter($this->getPk())->values()->one()
                + $this->__ht__;
        }
        if ($wasnew) {
            // Attempt to update foreign, unsaved objects with the PK of
            // this newly created object
            foreach (static::getMeta('joins') as $prop => $j) {
                if (isset($this->__ht__[$prop])
                    && ($foreign = $this->__ht__[$prop])
                    && in_array($j['local'], $pk)
                ) {
                    if ($foreign instanceof ModelBase
                        && null === $foreign->get($j['fkey'][1])
                    ) {
                        $foreign->set($j['fkey'][1], $this->get($j['local']));
                    }
                    elseif ($foreign instanceof InstrumentedList) {
                        foreach ($foreign as $item) {
                            if (null === $item->get($j['fkey'][1]))
                                $item->set($j['fkey'][1], $this->get($j['local']));
                        }
                    }
                }
            }
            // Cache the object so lookups will return this one rather than
            // a copy
            ModelInstanceManager::cache($this);
        }
        $this->__dirty__ = array();
        return true;
    }

    function __toString() {
        $a = new Util\ArrayObject($this->getPk());
        return sprintf('<%s %s>', (new \ReflectionClass($this))->getShortName(),
            $a->join('=',', '));
    }
}
