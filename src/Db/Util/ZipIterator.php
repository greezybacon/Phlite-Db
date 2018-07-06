<?php
namespace Phlite\Db\Util;

/**
 * Similar to the Python zip() function. It allows iteration over multiple
 * iterators yielding a list with one item from each iterator for each
 * iteration. Stops when any of the iterators are exhausted, hence the
 * iterated list is the length of the shortest iterator.
 */
class ZipIterator
implements \IteratorAggregate {
    protected $iters = [];

    function __construct(...$iterables) {
        foreach ($iterables as $I) {
            if (is_array($I))
                $I = new \ArrayIterator($I);
            while ($I instanceof \IteratorAggregate)
                $I = $I->getIterator();
            $I->rewind();
            $this->iters[] = $I;
        }
    }

    function getIterator() {
        for (;;) {
            $next = [];
            foreach ($this->iters as $i) {
                if (!$i->valid())
                    return;
                $next[] = $i->current();
                $i->next();
            }
            yield $next;
        }
    }
}
