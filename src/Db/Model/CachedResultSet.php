<?php

namespace Phlite\Db\Model;

use Phlite\Db\Manager;
use Phlite\Util;

class CachedResultSet
extends Util\ArrayObject
implements \ArrayAccess, \Countable {
    protected $inner;
    protected $eoi = false;

    function __construct(\IteratorAggregate $iterator) {
        $this->inner = $iterator->getIterator();
    }

    function fillTo($level) {
        while (!$this->eoi && count($this->storage) < $level) {
            if (!$this->inner->valid()) {
                $this->eoi = true;
                break;
            }
            $this->storage[] = $this->inner->current();
            $this->inner->next();
        }
    }

    function asArray() {
        $this->fillTo(PHP_INT_MAX);
        return $this->getCache();
    }

    function getCache() {
        return $this->storage;
    }

    function reset() {
        $this->eoi = false;
        $this->storage = array();
        // XXX: Should the inner be recreated to refetch?
        $this->inner->rewind();
    }

    function getIterator() {
        $this->asArray();
        return new \ArrayIterator($this->storage);
    }

    function offsetExists($offset) {
        $this->fillTo($offset+1);
        return count($this->storage) > $offset;
    }
    function offsetGet($offset) {
        $this->fillTo($offset+1);
        return $this->storage[$offset];
    }
    function offsetUnset($a) {
        throw new \Exception(sprintf('%s: is read-only', get_class()));
    }
    function offsetSet($a, $b) {
        throw new \Exception(sprintf('%s: is read-only', get_class()));
    }

    function count() {
        $this->asArray();
        return count($this->storage);
    }

    /**
     * Sort the instrumented list in place. This would be useful to change the
     * sorting order of the items in the list without fetching the list from
     * the database again.
     *
     * Parameters:
     * $key - (callable|int) A callable function to produce the sort keys
     *      or one of the SORT_ constants used by the array_multisort
     *      function
     * $reverse - (bool) true if the list should be sorted descending
     *
     * Returns:
     * This instrumented list for chaining and inlining.
     */
    function sort($key=false, $reverse=false) {
        // Fetch all records into the cache
        $this->asArray();
        return parent::sort($key, $reverse);
    }

    /**
     * Reverse the list item in place. Returns this object for chaining
     */
    function reverse() {
        $this->asArray();
        return parent::reverse();
    }
}
