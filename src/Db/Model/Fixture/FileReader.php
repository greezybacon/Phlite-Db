<?php
namespace Phlite\Db\Model\Fixture;

abstract class FileReader
extends ReaderBase {
    protected $file;

    function __construct(array $options) {
        if (!isset($options['file']))
            throw new \InvalidArgumentException(
                "Specify a `file` option of the CSV file to load");

        $this->file = $options['file'];
        if (is_string($this->file))
            $this->file = new \SplFileObject($this->file);

        if (!$this->file instanceof \SplFileObject)
            throw new \InvalidArgumentException(
                "`file` option should be a path of `SplFileObject`");

        parent::__construct($options);
    }
}