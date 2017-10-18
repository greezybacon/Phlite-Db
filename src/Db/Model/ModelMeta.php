<?php

namespace Phlite\Db\Model;

use Phlite\Db\Exception;
use Phlite\Db\Router;
use Phlite\Db\Util;

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
        'edges' => array(),
        'foreign_keys' => array(),
        // If the model is used to hold meta data only
        'abstract' => false,
        // Table prefix to use. Useful to be inherited
        'label' => '',
        // Use ->getFields() instances to interpret data to/from the
        // database. Can be TRUE or an array of field names.
        'interpret' => true,
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
        // there is a change in the matrix.
        // TODO: Ensure apc_key will change if a migration is necessary
        if (static::$use_cache && function_exists('apcu_store')) {
            $loaded = false;
            $this->apc_key = static::$secret . "meta/{$this->model}";
            $this->meta = apcu_fetch($this->apc_key, $loaded);
            if ($loaded)
                return;
        }

        // Build the meta data as usual
        $this->meta = $this->build($model);
        
        // Break down edge (many-to-many) metadata to joins
        foreach ($this->meta['edges'] as $field => $e) {
            $this->meta['joins'][$field] = $this->getJoinInfoForEdge($e, $field);
        }

        // Break down foreign-key metadata
        foreach ($this->meta['joins'] as $field => $j) {
            $this->meta['joins'][$field] = $j = $this->buildJoin($j);
            if (isset($j['local']))
                $this->meta['foreign_keys'][$j['local']] = $field;
        }

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
        if (is_subclass_of($parent, ModelBase::class)) {
            $this->parent = $parent::getMeta();
            $meta = $this->parent->extend($this, $meta);
        }
        else {
            $meta = $meta + self::$defaults;
        }

        // Capture enclosing namespace
        $class = new \ReflectionClass($model);
        $meta['namespace'] = $class->getNamespaceName();

        // Ensure other supported fields are set and are arrays
        foreach (array('pk', 'ordering', 'defer', 'select_related') as $f) {
            if (!isset($meta[$f]))
                $meta[$f] = array();
            elseif (!is_array($meta[$f]))
                $meta[$f] = array($meta[$f]);
        }

        if ($meta['abstract'] || $class->isAbstract())
            return $meta;

        if (!$meta['view']) {
            if (!$meta['table']) {
                // Assume class name without namespace, camelCase converted
                // to underscores and lower cased
                $class_name = preg_replace('/(?<=[a-z])[A-Z]/', '_$0',
                    $class->getShortName());
                $meta['table'] = strtolower($class_name);
            }
            if (!$meta['pk'])
                // TODO: Look this up out of the fields later? Since a field
                // can be declared to be a `pk`
                throw new Exception\ModelConfigurationError(
                    sprintf('%s: Model does not define meta.pk', $model));
        }

        if ($meta['label']
            && substr($meta['table'], 0, strlen($meta['label'])) !== $meta['label']
        ) {
            $meta['table'] = $meta['label'] . '_' . $meta['table'];
        }

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
            $meta['joins'] = array_merge($meta['joins'] ?? array(),
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

    function getParent() {
        return $this->parent;
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
     * 'through' => [relationship, ModelBase::class]
     *      The information for the rest of the edge. The join info will
     *      have information to get the intermediate models. This has the
     *      relation from the intermediate models to the target model.
     */
    function buildJoin($j) {
        $constraint = array();
        if (isset($j['reverse'])) {
            list($fmodel, $key) = is_string($j['reverse'])
                ? explode('.', $j['reverse']) : $j['reverse'];
            if (strpos($fmodel, '\\') === false) {
                // Transfer namespace from this model
                $fmodel = $this->meta['namespace']. '\\' . $fmodel;
            }
            // NOTE: It's ok if the forein meta data is not yet inspected.
            $info = $fmodel::$meta['joins'][$key];
            if (!is_array($info['constraint'])) {
                throw new Exception\ModelConfigurationError(sprintf(
                    // `reverse` here is the reverse of an ORM relationship
                    '%s: Reverse does not specify any constraints',
                    $j['reverse']));
            }
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
        elseif (isset($j['constraint'])) {
            // Determine what the class of the foreign model is. Add the local
            // namespace if it was implied.
            foreach ($j['constraint'] as $local => $foreign) {
                list($class, $field) = $constraint[$local]
                    = is_array($foreign) ? $foreign : explode('.', $foreign);
                if ($local[0] == "'" || $field[0] == "'")
                    continue;
                if (!class_exists($class) && strpos($class, '\\') === false) {
                    // Transfer namespace from this model
                    $class = $this->meta['namespace']. '\\' . $class;
                    $constraint[$local] = $foreign = array($class, $field);
                }
            }
        }
        if (isset($j['list']) && $j['list'] && !isset($j['broker'])) {
            $j['broker'] = InstrumentedList::class;
        }
        if (isset($j['broker']) && !class_exists($j['broker'])) {
            throw new Exception\ModelConfigurationError(sprintf(
                '%s: List broker class does not exist', $j['broker']));
        }
        foreach ($constraint as $local => $foreign) {
            list($class, $field) = $foreign;
            if (!class_exists($class))
                continue;
            $j['fkey'] = $foreign;
            $j['local'] = $local;
            if (!isset($j['list']))
                $j['list'] = false;
        }
        $j['constraint'] = $constraint;
        
        return $j;
    }

    function addJoin($name, array $join) {
        $this->meta['joins'][$name] = $this->buildJoin($join);
    }
    
    function getJoinInfoForEdge(array $edge, $name) {
        // Simplistic configuration -> specify 'target' and 'through' models
        $join = [
            'broker' => InstrumentedEdges::class,
        ];
        if (isset($edge['target']) && isset($edge['through'])) {
            if (!class_exists($edge['target']))
                throw new Exception\ModelConfigurationError(sprintf(
                    '%s: Target model for edge `%s` does not exist', 
                    $edge['target'], $name));
            if (!class_exists($edge['through']))
                throw new Exception\ModelConfigurationError(sprintf(
                    '%s: Intermediate model for edge does not exist', $edge['through']));
            
            // For this configuration, the `through` model is inspected for
            // a relationship to reverse
            foreach ($edge['through']::getMeta('joins') as $field=>$info) {
                list($class, $pk) = $info['fkey'];
                if ($class === $this->model) {
                    $join['reverse'] = sprintf("%s.%s", $edge['through'], $field);
                    break;
                }
            }
            
            // The join information should have a `through` field which has 
            // the intermediate model and the relation between it and the 
            // target model.
            foreach ($edge['through']::getMeta('joins') as $field=>$info) {
                list($class, $pk) = $info['fkey'];
                if ($class === $edge['target']) {
                    $join['through'] = [$field, $edge['target']];
                    break;
                }
            }

            if (!isset($join['through']))
                throw new \Exception(sprintf('%s: %s: Unable to determine fields used for joins',
                    $this->model, $name));
        }
        return $join;
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

    function getFields($try_schema=true) {
        if (!isset($this->fields)) {
            if ($try_schema)
                if (!($this->fields = $this->getSchema()))
                    // XXX: Emit warning?
                    unset($this->fields);
            if (!isset($this->fields))
                $this->fields = $this->fetchFields();
        }
        return $this->fields;
    }

    function getField($name) {
        $fields = $this->getFields();
        return $fields[$name];
    }
    
    function hasField($name) {
        $fields = $this->getFields();
        return isset($fields[$name]);
    }

    /**
     * Fetch the column names of the table used to persist instances of this
     * model in the database.
     */
    function getFieldNames() {
        return array_keys($this->getFields());
    }

    /**
     * Convenience method to return the schema defined for the model.
     */
    function getSchema() {
        $builder = new SchemaBuilder($this);
        $this->model::buildSchema($builder);
        return $builder->getFields();
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
        $bk = Router::getBackend($this);
        foreach ($this->getFields() as $name=>$field) {
            if (array_key_exists($name, $props) && isset($interpret[$name])) {
                $props[$name] = $field->to_php($props[$name], $bk);
            }
        }
        return $props;
    }

    function fetchFields() {
        if (isset($this->apc_key)) {
            $key = static::$secret . "fields/{$this['table']}";
            if ($fields = apcu_fetch($key)) {
                return $fields;
            }
        }
        $backend = Router::getBackend($this);
        $fields = $backend->getCompiler()->inspectTable($this, true);
        if (isset($key) && $fields) {
            apcu_store($key, $fields, 1800);
        }
        return $fields;
    }

    function reset() {
        unset($this->fields);
        if (isset($this->apc_key)) {
            $key = static::$secret . "fields/{$this['table']}";
            apcu_delete($key);
        }
    }

    static function flushModelCache() {
        if (self::$model_cache)
            @apcu_clear_cache('user');
    }
}
