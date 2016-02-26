<?php

namespace Phlite\Db\Model;

use Phlite\Db\Exception;
use Phlite\Db\Manager;

/**
 * Meta information about a model including edges (relationships), table
 * name, default sorting information, database fields, etc.
 *
 * This class is constructed and built automatically from the model's
 * ::_inspect method using a class's ::$meta array.
 */
class ModelMeta implements \ArrayAccess {

    static $defaults = array(
        'pk' => false,
        'table' => false,
        'form' => false,
        'defer' => array(),
        'select_related' => array(),
        'view' => false,
        'joins' => array(),
        'foreign_keys' => array(),
    );

    var $base;
    var $model;

    function __construct($model) {
        $this->model = $model;

        // Merge ModelMeta from parent model (if inherited)
        $parent = get_parent_class($this->model);
        if (is_subclass_of($parent, 'VerySimpleModel')) {
            $meta = $parent::getMeta()->extend($model::$meta);

        }
        else {
            $meta = $model::$meta + self::$defaults;
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
            $j = $this->processJoin($j);
            if ($j['local'])
                $meta['foreign_keys'][$j['local']] = $field;
        }
        unset($j);
        $this->base = $meta;
    }

    function extend($meta) {
        if ($meta instanceof self)
            $meta = $meta->base;
        return $meta + $this->base + self::$defaults;
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
                $fmodel = $this['namespace']. '\\' . $fmodel;
            }
            // NOTE: It's ok if the forein meta data is not yet inspected.
            $info = $fmodel::$meta['joins'][$key];
            $constraint = array();
            if (!is_array($info['constraint']))
                throw new Exception\ModelConfigurationError(sprintf(
                    // `reverse` here is the reverse of an ORM relationship
                    '%s: Reverse does not specify any constraints',
                    $j['reverse']));
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
        if (isset($j['broker']) && !class_exists($j['broker'])) {
            throw new OrmException($j['broker'] . ': List broker does not exist');
        }
        foreach ($constraint as $local => $foreign) {
            list($class, $field) = $foreign;
            if (strpos($class, '\\') === false) {
                // Transfer namespace from this model
                $class = $this['namespace']. '\\' . $class;
                $j['constraint'][$local] = "$class.$field";
            }
            if ($local[0] == "'" || $field[0] == "'" || !class_exists($class))
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
        $this->base['joins'][$name] = $join;
        $this->processJoin($this->base['joins'][$name]);
    }

    function offsetGet($field) {
        if (!isset($this->base[$field]))
            $this->setupLazy($field);
        return $this->base[$field];
    }
    function offsetSet($field, $what) {
        $this->base[$field] = $what;
    }
    function offsetExists($field) {
        return isset($this->base[$field]);
    }
    function offsetUnset($field) {
        throw new \Exception('Model MetaData is immutable');
    }

    function setupLazy($what) {
        switch ($what) {
        case 'fields':
            $this->base['fields'] = self::inspectFields();
            break;
        case 'namespace':
            $namespace = explode('\\', $this->model);
            array_pop($namespace);
            $this->base['namespace'] = implode('\\', $namespace);
            break;
        default:
            throw new \Exception($what . ': No such meta-data');
        }
    }

    function reset() {
        // Force reload of lazy items
        unset($this->base['fields']);
    }

    function inspectFields() {
        $backend = Manager::getBackend($this);
        $compiler = $backend->getCompiler();
        return $compiler->inspectTable($this['table']);
        // TODO: Support caching the fields
    }

    /**
     * Create a new instance of the model, optionally hydrating it with the
     * given hash table. The constructor is not called, which leaves the
     * default constructor free to assume new object status.
     */
    function newInstance($props=false) {
        static $sers = array();

        if (!isset($sers[$this->model])) {
            $sers[$this->model] = sprintf(
                'O:%d:"%s":0:{}',
                strlen($this->model), $this->model
            );
        }
        // TODO: Compare timing between unserialize() and
        //       ReflectionClass::newInstanceWithoutConstructor
        $instance = unserialize($sers[$this->model]);
        // Hydrate if props were included
        if (is_array($props)) {
            $instance->__ht__ = $props;
        }
        return $instance;
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
}
