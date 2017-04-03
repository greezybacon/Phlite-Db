<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Text;

class TextField
extends BaseField {
    static $defaults = array(
        'case' => false,
        'charset' => false,
        'collation' => false,
    );

    function __construct(array $options=array()) {
        parent::__construct($options);
        if (false && !isset($this->length))
            throw new \InvalidArgumentException('`length` is required for text fields');
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
}
