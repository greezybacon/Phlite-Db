<?php

namespace Phlite\Db\Model;

use Phlite\Db\Manager;
use Phlite\Util;

/**
 * Class: CachedResultSet
 *
 * Represents a array-like object which will lazily fetch data from an inner
 * iterator. As the results are fetched the results are cached in this
 * object. Once the inner iterator is exhausted, the results can be rewound
 * and read again. ArrayAccess and Countable interfaces are also implemented
 * which allow full lazy array-style access to the inner iterator.
 */
class CachedResultSet
extends Util\ListObject
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
        $this->eoi = true;
    }

    function reset() {
        $this->eoi = false;
        $this->storage = array();
        // XXX: Should the inner be recreated to refetch?
        $this->inner->rewind();
    }

    function getIterator() {
        return new \ArrayIterator($this->asArray());
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
