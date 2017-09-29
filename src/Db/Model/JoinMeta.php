<?php
namespace Phlite\Db\Model;

use Phlite\Db\Exception;

/**
 * Adds some more information to a declared relationship. If the
 * relationship is a reverse relation, then the information from the
 * reverse relation is loaded into the local definition
 */
class JoinMeta
implements \ArrayAccess {
    /**
     * 'constraint' => array(local => array(foreign_field, foreign_class)),
     *      Constraint used to construct a JOIN in an SQL query
     * 'list' => boolean
     *      TRUE if an InstrumentedList should be employed to fetch a list
     *      of related items
     * 'broker' => Handler for the 'list' property. Usually a subclass of
     *      'InstrumentedList'
     * 'null' => boolean
     *      TRUE if relation is nullable
     * 'foreign_model' => ModelBase class to which this relationship points
     * 'foreign_fields' => array(local => foreign) mapping of the local
     *      fields and the foreign fields in `foreign_model` to which they
     *      correspond.
     * 'local_pk' => Field in the local model which is part of the PK
     * 'through' => [relationship, ModelBase::class]
     *      The information for the rest of the edge. The join info will
     *      have information to get the intermediate models. This has the
     *      relation from the intermediate models to the target model.
     */
    public $constraint = [];
    public $list;
    public $broker = InstrumentedList::class;
    public $null;
    public $foreign_fields = [];
    public $foreign_model;
    public $local_pk;
    public $through;

    function __construct(array $info, ModelMeta $meta) {
        $this->mergeHints($info);

        // Convert constraints to array of [class, field] (if current a
        // dotted string as Model.field)
        foreach ($this->constraint as $local => $foreign) {
            if (!is_array($foreign)) {
                $foreign = explode('.', $foreign);
            }
            list($class, $ffield) = $foreign;
            if ($local[0] == "'" || $ffield[0] == "'")
                continue;
            if (!class_exists($class) && strpos($class, '\\') === false) {
                // Transfer namespace from the referenced metaclass
                $class = $meta['namespace']. '\\' . $class;
            }

            // Compile foreign key constraint and identify foreign model
            // XXX: This assumes a single foreign key constraint
            $this->constraint[$local] = [$class, $ffield];
            $this->foreign_model = $class;
            $this->foreign_fields[$local] = $ffield;
        }

        // Identify the local field in the foreign_fields list which is part
        // of the local PK
        if (count($this->foreign_fields) === 1) {
            // Simple strategy -- not a composite key
            // TODO: Study why I needs this
            $this->local_pk = key($this->foreign_fields);
            $this->foreign_pk = current($this->foreign_fields);
        }
        else {
            $fpk = $this->foreign_model::$meta['pk'];
            // TODO: handle composite foreign keys
        }

        if (isset($this->broker) && !class_exists($this->broker)) {
            throw new Exception\ModelConfigurationError(sprintf(
                '%s: List broker class does not exist', $this->broker));
        }

        if (0 === count($this->constraint)) {
            throw new Exception\ModelConfigurationError(sprintf(
                // `reverse` here is the reverse of an ORM relationship
                '%s: Does not specify any constraints', 'Name?'));
        }
    }

    protected function mergeHints(array $hints) {
        foreach ($hints as $name=>$value) {
            $this->$name = $value;
        }
    }

    function isList() {
        return $this->list === true;
    }

    function getList(ModelBase $model) {
        // Localize the foreign key constraint
        list($foreign, $key) = $this->getForeignKey($model);
        $broker = $this->broker;
        return new $broker(
            // Send [ForeignModel, [Foriegn-Field => Local-Id], JoinInfo]
            array($foreign, $key, $this)
        );
    }

    function hasForeignKey() {
        return isset($this->foreign_model);
    }

    function getForeignKey(ModelBase $model) {
        $criteria = array();
        foreach ($this->constraint as $local => $foreign) {
            list($_klas, $F) = $foreign;
            $criteria[$F ?: $_klas] = ($local[0] == "'")
                ? trim($local, "'")
                : @$model->__ht__[$local];
        }
        return [$this->foreign_model, $criteria];
    }

    function isLocal(array $fields) {
        foreach ($fields as $F) {
            // perpahs cryptically named, foreign_fields is a map between
            // local fields (keys) and the corresponding foreign fields in
            // the foreign model
            if (!isset($this->foreign_fields[$F]))
                return false;
        }
        return true;
    }

    function update(ModelBase $local, ModelBase $foreign) {
        foreach ($this->foreign_fields as $lfield=>$ffield)
            $foreign->set($ffield, $local->get($lfield));
    }

    /**
     * Convenience method to retrieve a copy of this join metadata with the
     * null property set. Useful for the LEFT JOIN propogation feature.
     */
    function withNull() {
        $null = clone $this;
        $null->null = true;
        return $null;
    }

    function asArray() {
        return get_object_vars($this);
    }

    // ArrayAccess interface ----------------------------------
    function offsetGet($field) {
        return $this->$field;
    }
    function offsetSet($field, $what) {
        throw new \Exception('JoinMeta is immutable');
    }
    function offsetExists($field) {
        return isset($this->$field);
    }
    function offsetUnset($field) {
        throw new \Exception('JoinMeta is immutable');
    }
}