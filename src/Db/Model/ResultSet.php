<?php

namespace Phlite\Db\Model;

use Phlite\Db\Manager;

abstract class ResultSet
extends Util\CachingIterator
implements \Countable {
    var $resource;
    var $stmt;
    var $queryset;

    function __construct($queryset=false) {
        $this->queryset = $queryset;
        if ($queryset) {
            $this->stmt = $queryset->getQuery();
        }
    }

    function asArray() {
        $this->fillTo(PHP_INT_MAX);
        return $this->getCache();
    }

    // Iterator interface
    function rewind() {
        if (!isset($this->resource) && $this->queryset) {
            $connection = Manager::getConnection($this->queryset->model);
            $this->resource = $connection->getDriver($this->stmt);
        }
        parent::rewind();
    }

    // Countable interface
    function count() {
        return count($this->asArray());
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
        if (is_callable($key)) {
            array_multisort(
                array_map($key, $this->__cache),
                $reverse ? SORT_DESC : SORT_ASC,
                $this->__cache);
        }
        elseif ($key) {
            array_multisort($this->__cache,
                $reverse ? SORT_DESC : SORT_ASC, $key);
        }
        elseif ($reverse) {
            rsort($this->__cache);
        }
        else
            sort($this->__cache);
        return $this;
    }

    /**
     * Reverse the list item in place. Returns this object for chaining
     */
    function reverse() {
        $this->asArray();
        array_reverse($this->__cache);
        return $this;
    }
}
