<?php
namespace Phlite\Db\Model\Fixture;

/**
 * The loader should be paired with a reader in the constructor. The reader
 * is used to convert the input stream (like a file) to model instances, and
 * the loader is used to push the models to the database.
 */
abstract class LoaderBase
implements \IteratorAggregate {
    protected $reader;

    function __construct(ReaderBase $reader) {
        $this->reader = $reader;
    }
    
    function getIterator() {
        foreach ($this->reader->iterModels() as $model)
            yield $model;
    }
}
