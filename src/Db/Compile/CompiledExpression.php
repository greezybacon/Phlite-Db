<?php

namespace Phlite\Db\Compile;

class CompiledExpression /* extends SplString */ {
    const TYPE_WHERE =   0x0001;
    const TYPE_HAVING =  0x0002;

    var $text = '';

    function __construct($clause, $type=self::TYPE_WHERE) {
        $this->text = $clause;
        $this->type = $type;
    }

    function __toString() {
        return $this->text;
    }
}