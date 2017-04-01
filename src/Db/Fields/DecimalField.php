<?php
namespace Phlite\Db\Fields;

class DecimalField
extends BaseField {
    static $defaults = array(
        'scale' => false,
        'precision' => false,
    );

    function getCreateSql($name, $compiler) {
        return sprintf('%s %s%s%s%s%s',
            $compiler->quote($name),
            $compiler->getTypeName($this),
            ($this->length) ? "({$this->length})" : '',
            (!$this->nullable ? ' NOT' : '') . ' NULL',
            ($this->default) ? ' DEFAULT ' . $compiler->escape($this->default) : ''
        );
    }
}