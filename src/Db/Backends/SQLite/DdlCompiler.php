<?php
namespace Phlite\Db\Backends\SQLite;

use Phlite\Db;
use Phlite\Db\Model\Schema\FieldDescriptor;
use Phlite\Db\Model\Schema\SchemaEditor;

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
        case $field instanceof Db\Fields\DecimalField:
        case $field instanceof Db\Fields\TimestampField:
            return RealField::wrap($field);
        case $field instanceof Db\Fields\DateTimeField:
        case $field instanceof Db\Fields\TimestampField:
        case $field instanceof Db\Fields\TextField:
            return TextField::wrap($field);
        case $field instanceof Db\Fields\BlobField:
        case $field instanceof Db\Fields\BinaryField:
            return BlobField::wrap($field);
        default:
            throw new \Exception(sprintf('%s: Unexpected or unsupported field type',
            get_class($field)));
        }
    }

    function compileFieldDescriptor($name, FieldDescriptor $field) {
        switch ($field->disposition) {
        case SchemaEditor::TYPE_ADD:
            // Position doesn't matter in SQLite. All new columns are appended
            // to the end of the table.
            return sprintf('ADD %s %s', $this->quote($name),
                $this->visit($field->getField()));
        default:
        // For SQLite this will require a completely new table. A new table
        // should be constructed in a transaction with the altered schema.
        // All the data from the current model should be copied to the new
        // model. Then the old model can be deleted and the transaction committed.
        }
    }

    function quote($what) {
        return "\x22$what\x22";
    }
}

abstract class BaseField {
    protected $__field;

    function getCreateSql($compiler) {
        return sprintf('%s%s%s%s%s',
            $this->getAffinity(),
            $this->pk ? ' PRIMARY KEY' : '',
            $this instanceof AutoIdField ? ' AUTOINCREMENT' : '',
            $this->nullable ? '' : ' NOT NULL',
            isset($this->default) ? sprintf(' DEFAULT %s', 
                $this->to_database($this->default, $compiler->getBackend())) : ''
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
    function getCreateSql($compiler) {
        return sprintf("%s%s",
            parent::getCreateSql($compiler),
            // ASCII case-insensitive is the only supported collation aside
            // from BINARY (which is the default)
            !$this->case ? ' COLLATE NOCASE' : ''
            // SQLite does not support charsets. UTF-8 is implied for Unicode
        );
    }
}
