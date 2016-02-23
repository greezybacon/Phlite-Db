<?php

namespace Phlite\Db\Model;

use Phlite\Db\Manager;
use Phlite\Util;

abstract class ResultSet
implements \IteratorAggregate {
    var $iterator;

    function __construct(\IteratorAggregate $iterator) {
        $this->iterator = $iterator->getIterator();
    }

    function next() {
        $rv = $this->iterator->current();
        $this->iterator->next();
        return $rv;
    }

    function getIterator() {
        while ($n = $this->next())
            yield $n;
    }
}
