<?php

namespace Phlite\Db\Model;

class InstrumentedList
extends ModelInstanceManager
implements \JsonSerializable {
    var $key;

    function __construct($fkey, $queryset=false) {
        list($model, $this->key) = $fkey;
        if (!$queryset) {
            $queryset = $model::objects()->filter($this->key);
            if ($related = $model::getMeta('select_related'))
                $queryset->select_related($related);
        }
        parent::__construct($queryset);
        $this->model = $model;
    }

    function add($object, $at=false) {
        if (!$object || !$object instanceof $this->model)
            throw new Exception(sprintf(
                'Attempting to add invalid object to list. Expected <%s>, but got <%s>',
                $this->model,
                get_class($object)
            ));

        foreach ($this->key as $field=>$value)
            $object->set($field, $value);

        if (!$object->__new__)
            $object->save();

        if ($at !== false)
            $this->cache[$at] = $object;
        else
            $this->cache[] = $object;

        return $object;
    }

    function remove($object, $delete=true) {
        if ($delete)
            $object->delete();
        else
            foreach ($this->key as $field=>$value)
                $object->set($field, null);
    }

    function reset() {
        $this->cache = array();
        unset($this->resource);
    }

    /**
     * Slight edit to the standard ::next() iteration method which will skip
     * deleted items.
     */
    function next() {
        do {
            parent::next();
        }
        while ($this->valid() && $this->current()->__deleted__);
    }

    /**
     * Reduce the list to a subset using a simply key/value constraint. New
     * items added to the subset will have the constraint automatically
     * added to all new items.
     */
    function window($constraint) {
        $model = $this->model;
        $fields = $model::getMeta('fields');
        $key = $this->key;
        foreach ($constraint as $field=>$value) {
            if (!is_string($field) || false === in_array($field, $fields))
                throw new OrmException('InstrumentedList windowing must be performed on local fields only');
            $key[$field] = $value;
        }
        return new static(array($this->model, $key), $this->filter($constraint));
    }

    // Save all changes made to any list items
    function saveAll() {
        foreach ($this as $I)
            if (!$I->save())
                return false;
        return true;
    }

    // QuerySet delegates
    function count() {
        return $this->objects()->count();
    }
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
        $this->cache[$a]->delete();
    }
    function offsetSet($a, $b) {
        $this->fillTo($a);
        $this->cache[$a]->delete();
        $this->add($b, $a);
    }

    // QuerySet overriedes
    function __call($func, $args) {
        return call_user_func_array(array($this->objects(), $func), $args);
    }

    // ---- JsonSerializable interface ------------------------
    function jsonSerialize() {
        return $this->queryset->asArray();
    }
}
