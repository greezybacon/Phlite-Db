<?php
namespace Phlite\Db\Model\Schema;

use Phlite\Db\Exception;
use Phlite\Db\Fields;
use Phlite\Db\Model;

class SchemaBuilder {
    protected $meta;
    protected $fields = array();
    protected $constraints = array();

    function __construct(Model\ModelMeta $modelMeta) {
        $this->meta = $modelMeta;
    }

    function addFields($fields) {
        foreach ($fields as $name=>$F)
            $this->addField($name, $F);
    }

    function addField($name, Fields\BaseField $field) {
        $this->fields[$name] = $field;
        $field->addToSchema($name, $this);
    }

    function addConstraints($constraints) {
        foreach ($constraints as $C)
            $this->addConstraint($C);
    }

    function addConstraint($constraint) {
        $this->constraints[] = $constraint;
    }

    function getFields() {
        return $this->fields;
    }

    function getConstraints() {
        return $this->constraints;
    }

    // And for the more magical stuff
    function getJoins() {

    }

    function getModelMeta() {
        return $this->meta;
    }
}
