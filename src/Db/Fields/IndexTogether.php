<?php
namespace Phlite\Db\Fields;

class IndexTogether
extends Constraint {
    var $fields;

    function __construct(array $fields, $name=null) {
        $this->fields = $fields;
        parent::__construct($name);
    }

    function getCreateSql($compiler) {
        return sprintf('KEY %s (%s)',
            ($name = $this->getName()) ? $compiler->quote($name) : '',
            implode(', ', array_map(array($compiler, 'quote'), $this->fields))
        );
    }
}
