<?php

namespace Phlite\Db\Model;

use Phlite\Db\Manager;
use Phlite\Util;

class CachedResultSet
extends ResultSet
implements \ArrayAccess, \Countable {
    protected $cache;
   
    function __construct(\IteratorAggregate $iterator) {
        parent::__construct($iterator);
        $this->cache = new Util\ArrayObject();
    }

    function fillTo($level) {
        while (count($this->cache) < $level) {
            if (!($next = $this->next()))
                break;
            $this->cache[] = $next;
        }
    }

    function getCache() {
        return $this->cache;
    }

    function asArray() {
        $this->fillTo(PHP_INT_MAX);
        return $this->cache;
    }

    /**
     * Sort the resultset list in place. This would be useful to change the
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
     * This resultset list for chaining and inlining.
     */
    function sort($key=false, $reverse=false) {
        // Fetch all records into the cache
        $this->asArray();
        $this->cache->sort($key, $reverse);
        return $this;
    }

    /**
     * Reverse the list item in place. Returns this object for chaining
     */
    function reverse() {
        $this->asArray();
        $this->cache->reverse();
        return $this;
    }

    // ArrayAccess interface
    function offsetGet($offset) {
        $this->fillTo($offset);
        return $this->cache[$offset];
    }
    function offsetExists($offset) {
        $this->fillTo($offset);
        return $this->cache->offsetExists($offset);
    }
    function offsetSet($offset, $item) {
        throw new \Exception('ResultSet is read-only');
    }
    function offsetUnset($offset) {
        throw new \Exception('ResultSet is read-only');
    }

    // Countable interface
    function count() {
        $this->asArray();
        return count($this->cache);
    }
}
