<?php
namespace Phlite\Db\Fields;

use Phlite\Db;
use Phlite\Db\Backend;
use Phlite\Db\Exception;
use Phlite\Db\Model;

/**
 * Adds a REFERENCES clause to the create and alter SQL for database platforms
 * which support foreign keys in their schema. When used on a local field, the
 * actual type of the field
 */
class ForeignKey
extends BaseField {
    protected $ffield_name;
    protected $ffield;
    protected $fmodel;
    protected $fmeta;

    static $defaults = [
        // local to foreign field mapping. Can be multiple items for composite
        // keys (not yet supported).
        'key' => null,
        // Create or use a local join to refer to the foreign model
        'join' => null,
        // Name of a join to add to the foreign model. Will become a list of
        // local instances which refer to the foreign instance
        'reverse_name' => null,
    ];

    function __construct($field, $options=array()) {
        parent::__construct($options);

        // Lookup the field and import the settings
        if (!is_array($field))
            $field = explode('.', $field);
        @list($fmodel, $ffield) = $field;
        if (!class_exists($fmodel)) {
            throw new Exception\OrmError(sprintf('ForeignKey: %s: No such model', $fmodel));
        }

        $this->fmodel = $fmodel;
        $this->fmeta = $fmodel::getMeta();

        // Allow use of model class name and assume link to primary key
        // field. This also assumes that there is only one field in the pk
        if (!$ffield && is_subclass_of($fmodel, Model\ModelBase::class)) {
            $pk = $this->fmeta['pk'];
            if (count($pk) > 1) {
                throw new Exception\OrmError('Cannot link to model with composite primary key without specifying fields.');
            }
            $ffield = $pk[0];
        }

        if (!$ffield)
            throw new Exception\OrmError('Unable to determine foreign key field');

        $this->ffield_name = $ffield;
        $this->ffield = $this->getForeignFieldType($ffield);
    }

    // Delegate conversions to foreign field type
    function to_database($value, Backend $backend) {
        return $this->ffield->to_database($value, $backend);
    }

    function to_php($value, Backend $backend) {
        return $this->ffield->to_php($value, $backend);
    }

    function to_export($value) {
        return $this->ffield->to_export($value);
    }

    function from_export($value) {
        return $this->ffield->from_export($value);
    }

    function addToSchema($name, Model\SchemaBuilder $builder) {
        $fmodel = $this->fmodel;
        $ffield = $this->ffield;
        $fmeta = $fmodel::getMeta();
        $lmeta = $builder->getModelMeta();
        $pk = $fmeta['pk'];

        // Figure out the local key (the REFERENCES part of the foreign key).
        // Also, figure out what the join should be called and if one should
        // be added to the local model.
        if (isset($this->key)) {
            $key = $this->key;
            $join = $name;
        }
        elseif (isset($this->column)) {
            // $local is the join and $column is the field name
            $key = [$this->column = [$fmodel, $pk[0]]];
            $join = $name;
        }
        elseif (isset($this->join)) {
            if ($this->join != $name) {
                // In this case, the $name is the column and join is the auto
                // join to be created
                $key = [$name => [$fmodel, $pk[0]]];
                $join = $this->join;
            }
            elseif (!isset($lmeta['joins'][$this->join])) {
                throw new Exception\ModelConfigurationError(sprintf(
                    '%s: Field specifies `join` %s, but does not exist. Specify '
                   .'`column` or `key` if join should be automatically created.',
                    $name, $builder->meta->model));
            }
            else {
                $key = $lmeta['joins'][$this->join]['constraint'];
                $join = $this->join;
            }
        }
        else {
            throw new Exception\ModelConfigurationError(sprintf(
                '%s: ForeignKey must specify either `join` or `key` or `column` in '
               .'order to identify both the relationship and column names.',
                $name));
        }

        // Save the inspected key
        $this->key = $key;

        // If a join is referenced but doesn't exist locally, add it to 
        // the model
        if (!isset($this->join) || !isset($lmeta['joins'][$join])) {
            $lmeta->addJoin($join, [
                'constraint' => $key
            ]);
        }

        // If `reverse_name` is set, then add a join to the foreign model
        if (isset($this->reverse_name)) {
            $fmeta->addJoin($this->reverse_name, [
                'constraint' => [
                    $this->ffield_name => [$lmeta->model, $name]
                ],
                'list' => true,
                'null' => true,
            ]);
        }
    }

    function getForeignModel() {
        return $this->fmodel;
    }

    function getForeignFieldName() {
        return $this->ffield_name;
    }

    function getForeignField() {
        return $this->ffield;
    }

    function getForeignFieldType($field) {
        $ffield = $this->fmeta->getField($field);
        $fclass = get_class($ffield);
        # Auto foreign keys should not be primary
        $options = $this->options + $ffield->options;
        $options['pk'] = false;
        if ($fclass == AutoIdField::class)
            $fclass = IntegerField::class;
        return new $fclass($options);
    }

    function getCreateSql($name, $compiler) {
        // Try and match the database field type exactly

        // Get the create sql for the referenced field. But drop the primary key
        // part as it is a foreign key in this table. Also, auto-id fields should
        // be changed to simple integer field.
        $simple_key = current($this->key);
        return sprintf('%s REFERENCES %s (%s)',
                $this->ffield->getCreateSql($name, $compiler),
                $compiler->quote($this->fmeta['table']), # XXX: Use $key[0]?
                $compiler->quote($simple_key[1]) # XXX: Assumes simple key
            );
    }
}