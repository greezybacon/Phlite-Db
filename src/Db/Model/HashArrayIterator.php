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
        $backend = $this->queryset->getBackend();
        $stmt = $this->queryset->getQuery();
        $this->resource = $backend->getDriver($stmt);
        while ($row = $this->resource->fetchArray())
            return $row;

        $this->resource->close();
    }
}
