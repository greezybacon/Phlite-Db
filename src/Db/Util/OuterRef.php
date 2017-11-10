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
            $compiler = $compiler->getParent();
        return parent::toSql($compiler, $model, $alias);
    }
}

