<?php

namespace Phlite\Db\Model;

class HashArrayIterator
implements \IteratorAggregate {
    var $queryset;
    var $resource;

    function __construct(QuerySet $queryset) {
        $this->queryset = $queryset;
    }

    function getIterator() {
        $this->resource = $this->queryset->getQuery();
        while ($row = $this->resource->getArray())
            return $row;

        $this->resource->close();
    }
}
