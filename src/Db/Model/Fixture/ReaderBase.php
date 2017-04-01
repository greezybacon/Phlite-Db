<?php
namespace Phlite\Db\Model\Fixture;

abstract class ReaderBase
implements \IteratorAggregate {
    protected $options;

    function __construct(array $options) {
        $this->setOptions($options);
    }

    function setOptions(array $options) {
        $this->options = $options;
    }

    function getIterator() {
        while ($record = $this->readRecord())
            yield $record;
    }

    abstract function readRecord();

    // XXX: This could probably be implemented abstractly
    abstract function makeModel(array $record);
}