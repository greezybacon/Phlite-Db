<?php
namespace Phlite\Db\Fields;

class ForeignKey
extends BaseField {
    protected $ffield;
    protected $fmodel;
    protected $fmeta;

    function __construct($field, $options=array()) {
        parent::__construct($options);

        // TODO: Lookup the field and import the settings
        list($fmodel, $this->ffield) = explode('.', $field);
        assert(class_exists($fmodel));
        $this->fmodel = $fmodel;
        $this->fmeta = $fmodel::getMeta();
    }

    function getCreateSql($name, $compiler) {
        // Try and match the database field type exactly
        $ffield = $this->fmeta->getField($this->ffield);
        return $ffield->getCreateSql($name, $compiler)
            . sprintf(' REFERENCES %s (%s)',
                $compiler->quote($this->fmeta['table']),
                $compiler->quote($this->ffield)
            );
    }
}

