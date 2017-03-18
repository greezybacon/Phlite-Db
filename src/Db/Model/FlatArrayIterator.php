<?php

namespace Phlite\Db\Model;

class FlatArrayIterator
implements \IteratorAggregate {
    var $queryset;
    var $resource;

    function __construct(QuerySet $queryset) {
        $this->queryset = $queryset;
    }

    function getIterator() {
        $this->resource = $this->queryset->getQuery();
        while ($row = $this->resource->getRow())
            yield $row;

        $this->resource->close();
    }
}
