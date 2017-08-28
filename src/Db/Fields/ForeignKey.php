<?php
namespace Phlite\Db\Fields;

use Phlite\Db;
use Phlite\Db\Exception;
use Phlite\Db\Model;

class ForeignKey
extends IntegerField {
    protected $ffield;
    protected $fmodel;
    protected $fmeta;

    function __construct($field, $options=array()) {
        parent::__construct($options);

        // Lookup the field and import the settings
        if (!is_array($field))
            $field = explode('.', $field);
        @list($fmodel, $this->ffield) = $field;
        if (!class_exists($fmodel)) {
            throw new Exception\OrmError(sprintf('ForeignKey: %s: No such model', $fmodel));
        }

        $this->fmodel = $fmodel;
        $this->fmeta = $fmodel::getMeta();

        // Allow use of model class name and assume link to primary key
        // field. This also assumes that there is only one field in the pk
        if (!$this->ffield && is_subclass_of($fmodel, Model\ModelBase::class)) {
            $pk = $this->fmeta['pk'];
            if (count($pk) > 1) {
                throw new Exception\OrmError('Cannot link to model with composite primray key without specifying fields.');
            }
            $this->ffield = $pk[0];
        }

        if (!$this->ffield)
            throw new Exception\OrmError('Unable to determine foreign key field');
    }

    function getCreateSql($name, $compiler) {
        // Try and match the database field type exactly
        $ffield = $this->fmeta->getField($this->ffield);

        // Get the create sql for the referenced field. But drop the primary key
        // part as it is a foreign key in this table. Also, auto-id fields should
        // be changed to simple integer field.
        $fclass = get_class($ffield);
        if ($fclass == AutoIdField::class)
            $fclass = IntegerField::class;
        $ffield = new $fclass($this->options + $ffield->options);
        return sprintf('%s REFERENCES %s (%s)',
                $ffield->getCreateSql($name, $compiler),
                $compiler->quote($this->fmeta['table']),
                $compiler->quote($this->ffield)
            );
    }
}