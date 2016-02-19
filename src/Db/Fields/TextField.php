<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Connection;
use Phlite\Text;

class TextField
extends BaseField {
    function to_php($value, Connection $connection) {
        if ($this->charset && $this->charset != $connection->charset) {
            return new Text\Unicode($value, $connection->charset);
        }
        return $value;
    }

    function to_database($value, Connection $connection) {
        if ($value instanceof Text\Unicode) {
            return $value->get($connection->charset)
        }
    }

    function getCreateSql($compiler) {
        $typename = $compiler->getTypeName($this);
        return sprintf('%s VARCHAR(%s) %s%s%s%s',
            $compiler->quote($this->name),
            $this->max_length,
            ($this->charset) ? ' CHARSET ' . $this->charset : '',
            ($this->collation) ? ' COLLATION ' . $this->collation : '',
            ($this->nullable ? 'NOT ' : '') . 'NULL',
            ($this->default) ? ' DEFAULT ' . $compiler->escape($this->default) : ''
        );
    }
}
