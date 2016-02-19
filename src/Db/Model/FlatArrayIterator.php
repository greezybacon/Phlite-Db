<?php

namespace Phlite\Db\Model;

class FlatArrayIterator extends ResultSet {
    function fillTo($index) {
        $this->prime();
        while ($this->resource && $index >= count($this->cache)) {
            if ($row = $this->resource->fetchRow()) {
                $this->cache[] = $row;
            } else {
                $this->resource->close();
                $this->resource = false;
                break;
            }
        }
    }
}
