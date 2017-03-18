<?php

namespace Phlite\Db\Model;

use Phlite\Db\Exception;
use Phlite\Db\Manager;

/**
 * Meta information about a model including edges (relationships), table
 * name, default sorting information, database fields, etc.
 *
 * This class is constructed and built automatically from the model's
 * ::getMeta() method using a class's ::$meta array.
 */
class ModelMeta
implements \ArrayAccess {

    static $defaults = array(
        'pk' => false,
        'table' => false,
        'form' => false,
        'defer' => array(),
        'select_related' => array(),
        'view' => false,
        'joins' => array(),
        'foreign_keys' => array(),
        // Table prefix to use. Useful to be inherited
        'label' => '',
        // Use ->getFields() instances to interpret data to/from the
        // database. Can be TRUE or an array of field names.
        'interpret' => false,
        // Give hints for field types returned from database. Useful for
        // ambiguous things like JSONField
        'field_types' => false,
    );
    protected static $model_cache;
    static $use_cache = true;
    static $secret = '/orm/';

    var $model;
    var $meta = array();
    var $subclasses = array();
    var $fields;
    var $apc_key;
    protected $new;

    function __construct($model) {
        $this->model = $model;

        // Short circuit the meta-data processing if APCu is available.
        // This is preferred as the meta-data is unlikely to change unless
        // osTicket is upgraded, (then the upgrader calls the
        // flushModelCache method to clear this cache). Also, GIT_VERSION is
        // used in the APC key which should be changed if new code is
        // deployed.
        if (static::$use_cache && function_exists('apcu_store')) {
            $loaded = false;
            $this->apc_key = static::$secret . "meta/{$this->model}";
            $this->meta = apcu_fetch($this->apc_key, $loaded);
            if ($loaded)
                return;
        }

        // Build the meta data as usual
        $this->meta = $this->build($model);

        if (isset($this->apc_key)) {
            apcu_store($this->apc_key, $this->meta, 1800);
        }
    }

    /**
     * Construct the local $meta data. This method is only called once for
     * each model, and it is short-circuited if the ::$use_cache is set and
     * the model has been previously cached.
     * 
     * Returns:
     * The meta data which should become the ->meta array. This value will
     * be cached if caching is enabled.
     */
    function build($model) {
        // Merge ModelMeta from parent model (if inherited)
        $parent = get_parent_class($model);
        $meta = $model::$meta;
        if ($meta instanceof self)
            $meta = $meta->meta;
        if (is_subclass_of($parent, __NAMESPACE__ . '\ModelBase')) {
            $this->parent = $parent::getMeta();
            $meta = $this->parent->extend($this, $meta);
        }
        else {
            $meta = $meta + self::$defaults;
        }

        if (!$meta['view']) {
            if (!$meta['table'])
                throw new Exception\ModelConfigurationError(
                    sprintf('%s: Model does not define meta.table', $model));
            elseif (!$meta['pk'])
                throw new Exception\ModelConfigurationError(
                    sprintf('%s: Model does not define meta.pk', $model));
        }

        // Ensure other supported fields are set and are arrays
        foreach (array('pk', 'ordering', 'defer', 'select_related') as $f) {
            if (!isset($meta[$f]))
                $meta[$f] = array();
            elseif (!is_array($meta[$f]))
                $meta[$f] = array($meta[$f]);
        }

        // Break down foreign-key metadata
        foreach ($meta['joins'] as $field => &$j) {
            $this->processJoin($j);
            if ($j['local'])
                $meta['foreign_keys'][$j['local']] = $field;
        }
        unset($j);

        // Capture enclosing namespace
        $namespace = explode('\\', $this->model);
        array_pop($namespace);
        $meta['namespace'] = implode('\\', $namespace);

        // Capture the backend
        $meta['bk'] = Manager::getBackend($this->model);

        return $meta;
    }

    /**
     * Merge this class's meta-data into the recieved child meta-data.
     * When a model extends another model, the meta data for the two models
     * is merged to form the child's meta data. Returns the merged, child
     * meta-data.
     */
    function extend(ModelMeta $child, $meta) {
        $this->subclasses[$child->model] = $child;
        // Merge 'joins' settings (instead of replacing)
        if (isset($this->meta['joins'])) {
            $meta['joins'] = array_merge($meta['joins'] ?: array(),
                $this->meta['joins']);
        }
        return $meta + $this->meta + $child::$defaults + self::$defaults;
    }

    function isSuperClassOf($model) {
        if (isset($this->subclasses[$model]))
            return true;
        foreach ($this->subclasses as $M=>$meta)
            if ($meta->isSuperClassOf($M))
                return true;
    }

    function isSubclassOf($model) {
        if (!isset($this->parent))
            return false;

        if ($this->parent->model === $model)
            return true;

        return $this->parent->isSubclassOf($model);
    }

    /**
     * Adds some more information to a declared relationship. If the
     * relationship is a reverse relation, then the information from the
     * reverse relation is loaded into the local definition
     *
     * Compiled-Join-Structure:
     * 'constraint' => array(local => array(foreign_field, foreign_class)),
     *      Constraint used to construct a JOIN in an SQL query
     * 'list' => boolean
     *      TRUE if an InstrumentedList should be employed to fetch a list
     *      of related items
     * 'broker' => Handler for the 'list' property. Usually a subclass of
     *      'InstrumentedList'
     * 'null' => boolean
     *      TRUE if relation is nullable
     * 'fkey' => array(class, pk)
     *      Classname and field of the first item in the constraint that
     *      points to a PK field of a foreign model
     * 'local' => string
     *      The local field corresponding to the 'fkey' property
     */
    function processJoin(&$j) {
        $constraint = array();
        if (isset($j['reverse'])) {
            list($fmodel, $key) = explode('.', $j['reverse']);
            if (strpos($fmodel, '\\') === false) {
                // Transfer namespace from this model
                $fmodel = $this->meta['namespace']. '\\' . $fmodel;
            }
            // NOTE: It's ok if the forein meta data is not yet inspected.
            $info = $fmodel::$meta['joins'][$key];
            if (!is_array($info['constraint']))
                throw new Exception\ModelConfigurationError(sprintf(
                    // `reverse` here is the reverse of an ORM relationship
                    '%s: Reverse does not specify any constraints'),
                    $j['reverse']);
            foreach ($info['constraint'] as $foreign => $local) {
                list($L,$field) = is_array($local) ? $local : explode('.', $local);
                $constraint[$field ?: $L] = array($fmodel, $foreign);
            }
            if (!isset($j['list']))
                $j['list'] = true;
            if (!isset($j['null']))
                // By default, reverse releationships can be empty lists
                $j['null'] = true;
        }
        else {
            foreach ($j['constraint'] as $local => $foreign) {
                list($class, $field) = $constraint[$local]
                    = is_array($foreign) ? $foreign : explode('.', $foreign);
            }
        }
        if (isset($j['list']) && $j['list'] && !isset($j['broker'])) {
            $j['broker'] = __NAMESPACE__ . '\InstrumentedList';
        }
        if (isset($j['broker']) && $j['broker'] && !class_exists($j['broker'])) {
            throw new OrmException($j['broker'] . ': List broker does not exist');
        }
        foreach ($constraint as $local => $foreign) {
            list($class, $field) = $foreign;
            if (strpos($class, '\\') === false) {
                // Transfer namespace from this model
                $class = $this->meta['namespace']. '\\' . $class;
                $j['constraint'][$local] = "$class.$field";
            }
            if ($local[0] == "'" || $field[0] == "'" || !class_exists($class))
                continue;
            $j['fkey'] = $foreign;
            $j['local'] = $local;
            #if (!isset($j['list']))
            #    $j['list'] = false;
        }
        $j['constraint'] = $constraint;
    }

    function addJoin($name, array $join) {
        $this->meta['joins'][$name] = $join;
        $this->processJoin($this->meta['joins'][$name]);
    }

    /**
     * Fetch ModelMeta instance by following a join path from this model
     */
    function getByPath($path) {
        if (is_string($path))
            $path = explode('__', $path);
        $root = $this;
        foreach ($path as $P) {
            if (!($root = $root['joins'][$P]['fkey'][0]))
                break;
            $root = $root::getMeta();
        }
        return $root;
    }

    function offsetGet($field) {
        return $this->meta[$field];
    }
    function offsetSet($field, $what) {
        $this->meta[$field] = $what;
    }
    function offsetExists($field) {
        return isset($this->meta[$field]);
    }
    function offsetUnset($field) {
        throw new \Exception('Model MetaData is immutable');
    }

    function getFields() {
        if (!isset($this->fields))
            $this->fields = self::inspectFields();
        return $this->fields;
    }

    function getField($name) {
        $fields = $this->getFields();
        return $fields[$name];
    }

    /**
     * Fetch the column names of the table used to persist instances of this
     * model in the database.
     */
    function getFieldNames() {
        return array_keys($this->getFields());
    }

    /**
     * Function: newInstance
     * Create a new instance of the model, optionally hydrating it with the
     * given hash table. The constructor is not called, which leaves the
     * default constructor free to assume new object status.
     *
     * Three methods were considered, with runtime for 10000 iterations
     *   * unserialze('O:9:"ModelBase":0:{}') - 0.0671s
     *   * new ReflectionClass("ModelBase")->newInstanceWithoutConstructor()
     *      - 0.0478s
     *   * and a hybrid by cloning the reflection class instance - 0.0335s
     */
    function newInstance($props=false) {
        if (!isset($this->new)) {
            $rc = new \ReflectionClass($this->model);
            $this->new = $rc->newInstanceWithoutConstructor();
        }
        $instance = clone $this->new;
        // Hydrate if props were included
        if (is_array($props)) {
            if ($this->meta['interpret'])
                $props = $this->interpret($props);
            $instance->__ht__ = $props;
        }
        return $instance;
    }

    /**
     * Function: interpret
     * Used when hydrating a new model from the database. Data is
     * interpreted from the database using the field instances inspected
     * from the database (via ::getFields()). This is configured with the
     * meta field 'interpret', which can be either TRUE or a list of field
     * names to interpret.
     *
     * Parameters:
     * $props - array - List of fields to be passed to become the __ht__ of
     *      a hydrated model instance.
     * $to_db - boolean:false - false when loading DB data, true when saving
     *
     * Returns:
     * Properties data as interpreted by the fields.
     */
    function interpret($props, $to_db=false) {
        $fieldnames = $this->meta['interpret'];
        if ($fieldnames === true)
            $fieldnames = $this->getFieldNames();
        $interpret = array_fill_keys($fieldnames, 1);
        foreach ($this->getFields() as $name=>$field) {
            if (isset($props[$name]) && isset($interpret[$name])) {
                $props[$name] = $field->to_php($props[$name],
                    $this->meta['bk']);
            }
        }
        return $props;
    }

    function inspectFields() {
        if (isset($this->apc_key)) {
            $key = static::$secret . "fields/{$this['table']}";
            if ($fields = apcu_fetch($key)) {
                return $fields;
            }
        }
        $backend = Manager::getBackend($this);
        $fields = $backend->getCompiler()->inspectTable($this, true);
        if (isset($key) && $fields) {
            apcu_store($key, $fields, 1800);
        }
        return $fields;
    }

    function reset() {
        unset($this->fields);
    }

    static function flushModelCache() {
        if (self::$model_cache)
            @apcu_clear_cache('user');
    }
}
