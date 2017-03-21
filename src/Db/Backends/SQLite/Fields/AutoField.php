<?php
namespace Phlite\Db\Backends\SQLite\Fields;

use Phlite\Db;
use Phlite\Db\Fields;

class AutoField
extends Fields\BaseField {
    function getCreateSql($name, $compiler) {
        // Not meant for this
        throw new \Exception();
    }
}
