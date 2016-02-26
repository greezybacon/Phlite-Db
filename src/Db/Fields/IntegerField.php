<?php
namespace Phlite\Db\Fields;

class IntegerField
extends BaseField {
    static $defaults = array(
        'unsigned' => false,
        'length' => 10,
    );

    function getCreateSql($name, $compiler) {
        return sprintf('%s %s%s%s%s%s',
            $compiler->quote($name),
            $compiler->getTypeName($this),
            ($this->length) ? "({$this->length})" : '',
            $this->unsigned ? ' UNSIGNED' : '',
            (!$this->nullable ? ' NOT' : '') . ' NULL',
            ($this->default) ? (' DEFAULT ' . $compiler->escape($this->default)) : ''
        );
    }
}
