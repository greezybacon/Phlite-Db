<?php
namespace Phlite\Db\Model\Fixture;

use Phlite\Db\Model;

/**
 * CsvLoader
 * Simple reader extension to support reading models from a CSV file
 * into a model.
 *
 * >>> $loader = new SimpleLoader(new CsvReader([
 * ...     'file' =>   '/path/to/file.csv', 
 * ...     'model' =>  MyModel::getMeta()
 * ... ]));
 * >>> $loader->loadAll();
 */
class CsvReader
extends FileReader {
    protected $header;
    protected $model;

    function __construct(array $options) {
        if (!isset($options['model']))
            throw new \InvalidArgumentException(
                "Specify a `model` option which the data represents.");

        if (is_string($options['model'] 
            && is_subclass_of($options['model'], Model\ModelBase::class))
        ) {
            $options['model'] = $options['model']::getMeta();
        }

        if (!$options['model'] instanceof Model\ModelMeta)
            throw new \InvalidArgumentException(
                "`model` option should be a model classname or `ModelMeta` instance.");

        $this->model = $options['model'];
        return parent::__construct($options);
    }

    function readRecord() {
        if (!$this->header)
            $this->header = $this->file->fgetcsv();

        $row = $this->file->fgetcsv();
        var_dump($this->header, $row);
        return array_combine($this->header, $row);
    }

    function makeModel(array $record) {
        return $this->model->newInstance($record);
    }
}