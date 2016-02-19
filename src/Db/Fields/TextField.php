<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Text;

class TextField
extends BaseField {
    function __construct(array $options=array()) {
        parent::__construct($options);
        if (!isset($this->length))
            throw new InvalidArgumentException('`length` is required for text fields');
    }

    function to_php($value, Backend $backend) {
        if ($this->charset && $this->charset != $backend->charset) {
            return new Text\Unicode($value, $backend->charset);
        }
        return $value;
    }

    function to_database($value, Backend $backend) {
        if ($value instanceof Text\Unicode) {
            return $value->get($backend->charset);
        }
    }

    function getCreateSql($name, $compiler) {
        return sprintf('%s %s(%s) %s%s%s%s',
            $compiler->quote($name),
            $compiler->getTypeName($this),
            $this->length,
            isset($this->charset) ? ' CHARSET ' . $this->charset : '',
            isset($this->collation) ? ' COLLATION ' . $this->collation : '',
            ($this->nullable ? 'NOT ' : '') . 'NULL',
            ($this->default) ? ' DEFAULT ' . $compiler->escape($this->default) : ''
        );
    }
}
