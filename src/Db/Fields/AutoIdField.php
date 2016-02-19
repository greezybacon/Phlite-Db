<?php
namespace Phlite\Db\Fields;

class AutoIdField
extends IntegerField {
    function getCreateSql($name, $compiler) {
        return parent::getCreateSql($name, $compiler) . ' AUTOINCREMENT';
    }
}
