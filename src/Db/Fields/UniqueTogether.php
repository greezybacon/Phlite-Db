<?php
namespace Phlite\Db\Constraint;

class UniqueTogether
extends IndexTogether {
    function getCreateSql($compiler) {
        return 'UNIQUE ' . parent::getCreateSql($compiler);
    }
}
