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

    function fromExport(array $record, array $fields) {
        foreach ($record as $name=>$value) {
            if (isset($fields[$name])) {
                $record[$name] = $fields[$name]->from_export($value);
            }
        }
        return $record;
    }

    /**
     * Iterate through the records the associated data source can provide.
     * Should return NULL or FALSE when there are no more records.
     */
    abstract function readRecord();

    // XXX: This could probably be implemented abstractly
    abstract function makeModel(array $record);
}