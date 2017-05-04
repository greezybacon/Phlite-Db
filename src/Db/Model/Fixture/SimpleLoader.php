<?php
namespace Phlite\Db\Model\Fixture;

/**
 * Class: SimpleLoader
 *
 * Simple loading strategy which loads one model at a time from the reader
 * and saves it using the ActiveRecord pattern. More complex loaders could
 * be created which could do things like use a bulk insert feature.
 */
class SimpleLoader
extends LoaderBase {
    public $loaded = 0;

    function loadAll() {
        $this->loadStart();
        foreach ($this->iterModels() as $model) {
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