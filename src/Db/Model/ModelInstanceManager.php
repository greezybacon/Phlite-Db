<?php

namespace Phlite\Db\Model;

use Phlite\Db\Compile;
use Phlite\Db\Manager;

class ModelInstanceManager
extends ResultSet {

    var $model;
    var $map;

    static $objectCache = array();

    function __construct($queryset) {
        $this->model = $queryset->model;
        parent::__construct($queryset);
    }

    function cache($model) {
        $key = sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->get('pk')));
        self::$objectCache[$key] = $model;
    }

    /**
     * uncache
     *
     * Drop the cached reference to the model. If the model is deleted
     * database-side. Lookups for the same model should not be short
     * circuited to retrieve the cached reference.
     */
    static function uncache($model) {
        $key = sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->pk));
        unset(self::$objectCache[$key]);
    }

    static function flushCache() {
        self::$objectCache = array();
    }

    static function checkCache($modelClass, $fields) {
        $key = $modelClass::$meta->model;
        foreach ($modelClass::getMeta('pk') as $f)
            $key .= '.'.$fields[$f];
        return @self::$objectCache[$key];
    }

    /**
     * getOrBuild
     *
     * Builds a new model from the received fields or returns the model
     * already stashed in the model cache. Caching helps to ensure that
     * multiple lookups for the same model identified by primary key will
     * fetch the exact same model. Therefore, changes made to the model
     * anywhere in the project will be reflected everywhere.
     *
     * For annotated models (models build from querysets with annotations),
     * the built or cached model is wrapped in an AnnotatedModel instance.
     * The annotated fields are in the AnnotatedModel instance and the
     * database-backed fields are managed by the Model instance.
     */
    function getOrBuild($modelClass, $fields, $cache=true) {
        // Check for NULL primary key, used with related model fetching. If
        // the PK is NULL, then consider the object to also be NULL
        foreach ($modelClass::getMeta('pk') as $pkf) {
            if (!isset($fields[$pkf])) {
                return null;
            }
        }
        $annotations = $this->queryset->annotations;
        $extras = array();
        // For annotations, drop them from the $fields list and add them to
        // an $extras list. The fields passed to the root model should only
        // be the root model's fields. The annotated fields will be wrapped
        // using an AnnotatedModel instance.
        if ($annotations && $modelClass == $this->model) {
            foreach ($annotations as $name=>$A) {
                if (array_key_exists($name, $fields)) {
                    $extras[$name] = $fields[$name];
                    unset($fields[$name]);
                }
            }
        }
        // Check the cache for the model instance first
        if (!($m = self::checkCache($modelClass, $fields))) {
            // Construct and cache the object
            $m = $modelClass::$meta->newInstance($fields);
            // XXX: defer may refer to fields not in this model
            $m->__deferred__ = $this->queryset->defer;
            $m->__onload();
            if ($cache)
                $this->cache($m);
        }
        elseif (get_class($m) != $modelClass) {
            // Change the class of the object to be returned to match what
            // was expected
            // TODO: Emit a warning?
            $m = $modelClass::$meta->newInstance($m->ht);
        }
        // Wrap annotations in an AnnotatedModel
        if ($extras) {
            $m = new AnnotatedModel($m, $extras);
        }
        // TODO: If the model has deferred fields which are in $fields,
        // those can be resolved here
        return $m;
    }

    /**
     * buildModel
     *
     * This method builds the model including related models from the record
     * received. For related recordsets, a $map should be setup inside this
     * object prior to using this method. The $map is assumed to have this
     * configuration:
     *
     * array(array(<fieldNames>, <modelClass>, <relativePath>))
     *
     * Where $modelClass is the name of the foreign (with respect to the
     * root model ($this->model), $fieldNames is the number and names of
     * fields in the row for this model, $relativePath is the path that
     * describes the relationship between the root model and this model,
     * 'user__account' for instance.
     */
    function buildModel($row) {
        // TODO: Traverse to foreign keys
        if ($this->map) {
            if ($this->model != $this->map[0][1])
                throw new Exception\OrmError('Internal select_related error');

            $offset = 0;
            foreach ($this->map as $info) {
                @list($fields, $model_class, $path) = $info;
                $values = array_slice($row, $offset, count($fields));
                $record = array_combine($fields, $values);
                if (!$path) {
                    // Build the root model
                    $model = $this->getOrBuild($this->model, $record);
                }
                elseif ($model) {
                    $i = 0;
                    // Traverse the declared path and link the related model
                    $tail = array_pop($path);
                    $m = $model;
                    foreach ($path as $field) {
                        if (!($m = $m->get($field)))
                            break;
                    }
                    if ($m)
                        $m->set($tail, $this->getOrBuild($model_class, $record));
                }
                $offset += count($fields);
            }
        }
        else {
            $model = $this->getOrBuild($this->model, $row);
        }
        return $model;
    }

    function iter() {
        $func = ($this->map) ? 'fetchRow' : 'fetchArray';
        if ($row = $this->resource->{$func}()) {
            $model = $this->buildModel($row);
            return array(spl_object_hash($model), $model);
        }
    }

    function rewind() {
        parent::rewind();
        if ($this->resource) {
            $this->map = $this->resource->getMap();
        }
    }

    /**
     * Find the first item in the current set which matches the given criteria.
     * This would be used in favor of ::filter() which might trigger another
     * database query. The criteria is intended to be quite simple and should
     * not traverse relationships which have not already been fetched.
     * Otherwise, the ::filter() or ::window() methods would provide better
     * performance.
     *
     * Example:
     * >>> $a = new User();
     * >>> $a->roles->add(Role::lookup(['name' => 'administator']));
     * >>> $a->roles->findFirst(['roles__name__startswith' => 'admin']);
     * <Role: administrator>
     */
    function findFirst($criteria) {
        $records = $this->findAll($criteria, 1);
        return @$records[0];
    }

    /**
     * Find all the items in the current set which match the given criteria.
     * This would be used in favor of ::filter() which might trigger another
     * database query. The criteria is intended to be quite simple and should
     * not traverse relationships which have not already been fetched.
     * Otherwise, the ::filter() or ::window() methods would provide better
     * performance, as they can provide results with one more trip to the
     * database.
     */
    function findAll($criteria, $limit=false) {
        $records = array();
        if (!$criteria instanceof Util\Q)
            $criteria = new Util\Q($criteria);
        foreach ($this as $record) {
            if ($criteria->matches($record))
                $records[] = $record;
            if ($limit && count($records) == $limit)
                break;
        }
        return $records;
    }
}
