<?php
namespace Phlite\Db\Fields;

abstract class Constraint {
    var $name;

    function getName() {
        return $this->name;
        // TODO: Perhaps add an auto-generated code
    }

    abstract function getCreateSql($compiler);
}
