<?php
namespace Phlite\Db\Model;

use Phlite\Db\Fields;

class SchemaBuilder {
    protected $fields = array();
    protected $constraints = array();

    function addFields($fields) {
        foreach ($fields as $name=>$F)
            $this->addField($name, $F);
    }

    function addField($name, Fields\BaseField $field) {
        $this->fields[$name] = $field;
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
}