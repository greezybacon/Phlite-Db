<?php

namespace Phlite\Db\Util;

class OuterRef
extends Field {
    protected $level;

    function __construct($field, $level=1) {
        parent::__construct($field);
        $this->level = $level;
    }

    function toSql($compiler, $model=false, $alias=false) {
        $L = $this->level;
        while ($L--)
            if (!($compiler = $compiler->getParent()))
                throw new Exception\OrmError(sprintf(
                    'This QuerySet does not have %d levels', $this->level));
        return parent::toSql($compiler, $model, $alias);
    }
}

