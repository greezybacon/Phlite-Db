<?php
namespace Phlite\Db\Backends\SQLite;

use Phlite\Db;

class DdlCompiler 
extends Db\Compile\DdlCompiler {
    function visit($node) {
        switch (true) {
        case $node instanceof Db\Fields\BaseField:
            return $this->wrapField($node)->getCreateSql($this);
        }
        return $node;
    }

    function wrapField(Db\Fields\BaseField $field) {
        switch (true) {
        case $field instanceof Db\Fields\AutoIdField:
            return AutoIdField::wrap($field);
        case $field instanceof Db\Fields\IntegerField:
            return IntegerField::wrap($field);
        case $field instanceof Db\Fields\DateTimeField:
        case $field instanceof Db\Fields\TimestampField:
        case $field instanceof Db\Fields\TextField:
            return TextField::wrap($field);
        case $field instanceof Db\Fields\BlobField:
            return BlobField::wrap($field);
        default:
            throw new \Exception();
        }
    }

    function quote($what) {
        return "\x22$what\x22";
    }
}

abstract class BaseField {
    protected $__field;

    function getCreateSql($compiler) {
        return sprintf('%s%s%s%s%s%s',
            $this->getAffinity(),
            $this->pk ? ' PRIMARY KEY' : '',
            $this instanceof AutoIdField ? ' AUTOINCREMENT' : '',
            $this->nullable ? '' : ' NOT NULL',
            isset($this->default) ? sprintf(' DEFAULT %s', $this->getDefault()) : '',
            isset($this->case) && !$this->case ? sprintf(' COLLATE NOCASE') : ''
        );
    }

    abstract function getAffinity();

    static function wrap(Db\Fields\BaseField $field) {
        $inst = new static();
        $inst->__field = $field;
        return $inst;
    }

    function __get($what) {
        return $this->__field->{$what};
    }

    function __set($what, $to) {
        $this->__field->{$what} = $to;
    }

    function __isset($what) {
        if (isset($this->__field->{$what}))
            return true;
    }

    function __call($what, $how) {
        return call_user_func_array(array($this->__field, $what), $how);
    }
}

class IntegerField 
extends BaseField {
    function getAffinity() { return 'INTEGER'; }
    function getDefault() { return intval($this->default); }
}

class AutoIdField
extends IntegerField {
    function getCreateSql($compiler) {
        if (!$this->pk)
            throw new Db\Exception\ModelConfigurationError('AutoIdField must also be PK');
        return parent::getCreateSql($compiler);
    }
}

class RealField 
extends BaseField {
    function getAffinity() { return 'REAL'; }
}

class BlobField 
extends BaseField {
    function getAffinity() { return 'BLOB'; }
    function getDefault() {
        return sprintf("'%s'", $compiler->escape($this->default)); }
}

class TextField 
extends BlobField {
    function getAffinity() { return 'TEXT'; }
}
