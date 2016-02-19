<?php
namespace Phlite\Db\Fields;

class PrimaryKey
extends IndexTogether {
    function getCreateSql($compiler) {
        return 'PRIMARY ' . parent::getCreateSql($compiler);
    }
}
