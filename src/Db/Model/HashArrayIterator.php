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

        try {
            while ($row = $this->resource->fetchArray())
                yield $row;
        }
        finally {
            $this->resource->close();
        }
    }
}
