<?php
namespace Phlite\Db\Model\Fixture;

/**
 * Class: SimpleLoader
 *
 * Simple loading strategy which loads one model at a time from the reader
 * and saves it using the ActiveRecord pattern. More complex loaders could
 * be created which could do things like use a bulk insert feature.
 *
 * The loader should be paired with a reader in the constructor. The reader
 * is used to convert the input stream (like a file) to model instances, and
 * the loader is used to push the models to the database.
 */
class SimpleLoader {
    protected $reader;
    protected $loaded = 0;

    function __construct(ReaderBase $reader) {
        $this->reader = $reader;
    }

    function loadAll() {
        $this->loadStart();
        foreach ($this->reader as $record) {
            $model = $this->reader->makeModel($record);
            if (!$this->loadNext($model))
                // Do something here
                return false;
            $this->loaded++;
        }
        $this->loadFinish();
        return true;
    }

    function loadStart() {}
    function loadFinish() {}

    function loadNext($model) {
        return $model->save();
    }
}