<?php
namespace Phlite\Db\Model;

use Phlite\Db\Compile\SqlCompiler;
use Phlite\Db\Exception;

class InstrumentedList
extends ModelResultSet
implements \JsonSerializable {
    var $key;
    var $queryset;

    function __construct($fkey, $queryset=false,
        $iterator=ModelInstanceManager::class
    ) {
        list($model, $this->key) = $fkey;
        if (!$queryset) {
            $queryset = $model::objects()->filter($this->key);
            if ($related = $model::getMeta('select_related'))
                $queryset->select_related($related);
        }
        $iterator = is_callable($iterator)
            ? $iterator($queryset) : new $iterator($queryset);
        parent::__construct($iterator);
        $this->model = $model;
        $this->queryset = $queryset;
    }

    function add($object, $at=false) {
        if (!$object || !$object instanceof $this->model)
            throw new \InvalidArgumentException(sprintf(
                'Attempting to add invalid object to list. Expected <%s>, but got <%s>',
                $this->model,
                get_class($object)
            ));
        foreach ($this->key as $field=>$value)
            $object->set($field, $value);

        if (!$object->__new__)
            $object->save();

        if ($at !== false)
            $this->storage[$at] = $object;
        else
            $this->storage[] = $object;

        return $object;
    }

    function remove($object, $delete=true) {
        if ($delete && !$object->delete()) {
            return false;
        }
        else {
            foreach ($this->key as $field=>$value)
                $object->set($field, null);
        }
        // Seems like the object should be removed from ->storage
        foreach ($this as $k=>$v) {
            if ($v === $object) {
                unset($this[$k]);
                break;
            }
        }
        return true;
    }

    /**
     * Slight edit to the standard ::next() iteration method which will skip
     * deleted items.
     */
    function getIterator() {
        return new \CallbackFilterIterator(parent::getIterator(),
            function($i) { return !$i->__deleted__; });
    }

    /**
     * Reduce the list to a subset using a simply key/value constraint. New
     * items added to the subset will have the constraint automatically
     * added to all new items.
     *
     * Parameters:
     * $criteria - (<Traversable>) criteria by which this list will be
     *    constrained and filtered.
     * $evaluate - (<bool>) if set to TRUE, the criteria will be evaluated
     *    without making any more trips to the database. NOTE this may yield
     *    unexpected results if this list does not contain all the records
     *    from the database which would be matched by another query.
     */
    function window($constraint, $evaluate=false) {
        $model = $this->model;
        $fields = $model::getMeta()->getFields();
        $key = $this->key;
        foreach ($constraint as $field=>$value) {
            if (!is_string($field) || !isset($fields[$field]))
                throw new Exception\OrmError('InstrumentedList windowing must be performed on local fields only');
            $key[$field] = $value;
        }
        $list = new static(array($this->model, $key),
            $this->objects()->filter($constraint));
        if ($evaluate)
            $list->setCache($this->findAll($constraint));
        return $list;
    }

    /**
     * Disable database fetching on this list by providing a static list of
     * objects. ::add() and ::remove() are still supported.
     * XXX: Move this to a parent class?
     */
    function setCache(array $cache) {
        if (count($this->storage) > 0)
            throw new \Exception('Cache must be set before fetching records');
        // Set cache and disable fetching
        $this->reset();
        $this->storage = $cache;
    }

    // Save all changes made to any list items
    function saveAll() {
        foreach ($this as $I)
            if (!$I->save())
                return false;
        return true;
    }

    // QuerySet delegates
    function exists() {
        return $this->queryset->exists();
    }
    function expunge() {
        if ($this->queryset->delete())
            $this->reset();
    }
    function update(array $what) {
        return $this->queryset->update($what);
    }

    // Fetch a new QuerySet — ensure local queryset object is not modified
    function objects() {
        return clone $this->queryset;
    }

    function offsetUnset($a) {
        $this->fillTo($a);
        unset($this->storage[$a]);
    }
    function offsetSet($a, $b) {
        $this->fillTo($a);
        unset($this->storage[$a]);
        $this->add($b, $a);
    }

    // QuerySet overriedes
    function __call($func, $args) {
        $this->objects()->$func(...$args);
    }

    // ---- JsonSerializable interface ------------------------
    function jsonSerialize() {
        return $this->queryset->asArray();
    }
}
