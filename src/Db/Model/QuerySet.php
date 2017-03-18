<?php

namespace Phlite\Db\Model;

use Phlite\Db\Exception;
use Phlite\Db\Manager;
use Phlite\Db\Util;

class QuerySet
implements \IteratorAggregate, \ArrayAccess, \Serializable, \Countable {
    var $model;

    var $constraints = array();
    var $path_constraints = array();
    var $ordering = array();
    var $limit = false;
    var $offset = 0;
    var $related = array();
    var $values = array();
    var $defer = array();
    var $aggregated = false;
    var $annotations = array();
    var $lock = false;
    var $extra = array();
    var $distinct = array();
    var $chain = array();
    var $options = array();

    const LOCK_EXCLUSIVE = 1;
    const LOCK_SHARED = 2;

    const ASC = 'ASC';
    const DESC = 'DESC';

    const OPT_NOSORT    = 'nosort';
    const OPT_NOCACHE   = 'nocache';

    const ITER_MODELS   = 1;
    const ITER_HASH     = 2;
    const ITER_ROW      = 3;

    var $iter = self::ITER_MODELS;

    var $query;
    var $_count;
    var $_iterator;

    function __construct($model) {
        $this->model = $model;
    }

    function filter() {
        foreach (func_get_args() as $Q) {
            $this->constraints[] = $Q instanceof Util\Q ? $Q : new Util\Q($Q);
        }
        return $this;
    }

    function exclude() {
        foreach (func_get_args() as $Q) {
            $this->constraints[] = $Q instanceof Util\Q ? $Q->negate() : Util\Q::not($Q);
        }
        return $this;
    }

    /**
     * Add a path constraint for the query. This is different from ::filter
     * in that the constraint is added to a join clause which is normally
     * built from the model meta data. The ::filter() method on the other
     * hand adds the constraint to the where clause. This is generally useful
     * for aggregate queries and left join queries where multiple rows might
     * match a filter in the where clause and would produce incorrect results.
     *
     * Example:
     * Find users with personal email hosted with gmail.
     * >>> $Q = User::objects();
     * >>> $Q->constrain(['user__emails' => new Q(['type' => 'personal']))
     * >>> $Q->filter(['user__emails__address__contains' => '@gmail.com'])
     */
    function constrain() {
        foreach (func_get_args() as $join=>$I) {
            foreach ($I as $path => $Q) {
                // TODO: Consider ensure / auto-adding $path to all paths
                // listed in $Q
                if (!is_array($Q) && !$Q instanceof Q) {
                    // ->constrain(array('field__path__op' => val));
                    $Q = array($path => $Q);
                    list(, $path) = SqlCompiler::splitCriteria($path);
                    $path = implode('__', $path);
                }
                $this->path_constraints[$path][] = $Q instanceof Q ? $Q : Q::all($Q);
            }
        }
        return $this;
    }

    function defer() {
        foreach (func_get_args() as $f)
            $this->defer[$f] = true;
        return $this;
    }

    function order_by($order, $direction=false) {
        if ($order === false)
            return $this->options(array(self::OPT_NOSORT => true));

        $args = func_get_args();
        if (in_array($direction, array(self::ASC, self::DESC))) {
            $args = array($args[0]);
        }
        else
            $direction = false;

        $new = is_array($order) ?  $order : $args;
        if ($direction) {
            foreach ($new as $i=>$x) {
                $new[$i] = array($x, $direction);
            }
        }
        $this->ordering = array_merge($this->ordering, $new);
        return $this;
    }

    function getSortFields() {
        $ordering = $this->ordering;
        if (isset($this->extra['order_by']))
            $ordering = array_merge($ordering, $this->extra['order_by']);
        return $ordering;
    }

    function countSelectFields() {
        $count = count($this->values) + count($this->annotations);
        if (isset($this->extra['select']))
            foreach (@$this->extra['select'] as $S)
                $count += count($S);
        return $count;
    }

    function for_update() {
        return $this->lock(self::LOCK_EXCLUSIVE);
    }
    function lock($how=false) {
        $this->lock = $how ?: self::LOCK_EXCLUSIVE;
        return $this;
    }

    function limit($count) {
        $this->limit = $count;
        return $this;
    }

    function offset($at) {
        $this->offset = $at;
        return $this;
    }

    function isWindowed() {
        return $this->limit || $this->offset;
    }

    function select_related() {
        $this->related = array_merge($this->related, func_get_args());
        return $this;
    }

    function extra(array $extra) {
        foreach ($extra as $section=>$info) {
            $this->extra[$section] = array_merge($this->extra[$section] ?: array(), $info);
        }
        return $this;
    }

    /**
     * Add extra distinct fields to the QuerySet. By default, fields not used
     * in aggregate expressions are implied to be distinct. However, any
     * fields may be explictly defined here.
     */
    function distinct() {
        foreach (func_get_args() as $D)
            $this->distinct[] = $D;
        return $this;
    }

    function models() {
        $this->iter = self::ITER_MODELS;
        $this->values = $this->related = array();
        return $this;
    }

    /**
     * Instead of returning objects of the root model, return a hash array
     * where the keys are the field names passed in here, and the values
     * are the values from the database. This function can be called more
     * than once. Each time, the arguments are added to the use of values
     * retrieved from the database.
     */
    function values() {
        foreach (func_get_args() as $A)
            $this->values[$A] = $A;
        $this->iter = self::ITER_HASH;
        // This disables related models
        $this->related = false;
        return $this;
    }

    function values_flat() {
        $this->values = func_get_args();
        $this->iter = self::ITER_ROW;
        // This disables related models
        $this->related = false;
        return $this;
    }

    /**
     * Fetch a copy of this QuerySet instance. Changes made to the copy are
     * independent from this instance.
     */
    function copy() {
        return clone $this;
    }

    /**
     * Fetch an interator for this QuerySet. The iterator can be used like
     * an array. Multiple calls to this method will retrieve the same iterator
     */
    function all() {
        return $this->getIterator();
    }

    /**
     * Retrieve the first record or NULL from this QuerySet. This is
     * implemented by executing the statement and limiting the results to
     * one record. If no record is fetched, NULL is retured.
     */
    function first() {
        $this->limit(1);
        return $this[0];
    }

    /**
     * one
     *
     * Finds and returns a single model instance based on the criteria in
     * this QuerySet instance.
     *
     * Throws:
     * DoesNotExist - if no such model exists with the given criteria
     * ObjectNotUnique - if more than one model matches the given criteria
     *
     * Returns:
     * (Object<Model>) a single instance of the sought model is guarenteed.
     * If no such model or multiple models exist, an exception is thrown.
     */
    function one() {
        $list = $this->all();
        if (count($list) == 0)
            throw new Exception\DoesNotExist();
        elseif (count($list) > 1)
            throw new Exception\NotUnique('One object was expected; however '
                .'multiple objects in the database matched the query. '
                .sprintf('In fact, there are %d matching objects.', count($list))
            );
        return $list[0];
    }

    /**
     * count
     *
     * Fetch a count of records represented by this QuerySet. If not already
     * fetching, a SELECT COUNT(*) query will be requested of the database
     * and cached locally. Multiple calls to this method will receive the
     * cached value. If already fetching from the recordset, the rest of the
     * records will be retrieved and the count of those records will be
     * returned.
     *
     * Returns:
     * <int> — number of records matched by this QuerySet
     */
    function count() {
        // Defer to the iterator if fetching already started
        if (isset($this->_iterator)
            && $this->_iterator instanceof \Countable
        ) {
            return $this->_iterator->count();
        }
        // Returned cached count if available
        elseif (isset($this->_count)) {
            return $this->_count;
        }
        $backend = Manager::getBackend($this->model);
        $compiler = $this->getCompiler();
        $stmt = $compiler->compileCount($this);
        $exec = $backend->execute($stmt);
        $row = $exec->fetchRow();
        return $this->_count = $row[0];
    }

    function toSql($compiler, $model, $alias=false) {
        // FIXME: Force root model of the compiler to $model
        $exec = $this->getQuery(array('compiler' => get_class($compiler),
             'parent' => $compiler, 'subquery' => true));
        // Rewrite the parameter numbers so they fit the parameter numbers
        // of the current parameters of the $compiler
        $sql = preg_replace_callback("/:(\d+)/",
        function($m) use ($compiler, $exec) {
            $compiler->params[] = $exec->params[$m[1]-1];
            return ':'.count($compiler->params);
        }, $exec->sql);
        return "({$sql})".($alias ? " AS {$alias}" : '');
    }

    /**
     * exists
     *
     * Determines if there are any rows in this QuerySet. This can be
     * achieved either by evaluating a SELECT COUNT(*) query or by
     * attempting to fetch the first row from the recordset and return
     * boolean success.
     *
     * Parameters:
     * $fetch - (bool) TRUE if a compile and fetch should be attempted
     *      instead of a SELECT COUNT(*). This would be recommended if an
     *      accurate count is not required and the records would be fetched
     *      if this method returns TRUE.
     *
     * Returns:
     * (bool) TRUE if there would be at least one record in this QuerySet
     */
    function exists($fetch=false) {
        if ($fetch) {
            return (bool) $this[0];
        }
        return $this->count() > 0;
    }

    function annotate($annotations) {
        if (!is_array($annotations))
            $annotations = func_get_args();
        foreach ($annotations as $name=>$A) {
            if ($A instanceof Util\Aggregate) {
                if (is_int($name))
                    $name = $A->getFieldName();
                $A->setAlias($name);
            }
            $this->annotations[$name] = $A;
        }
        return $this;
    }

    function aggregate($annotations) {
        // Aggregate works like annotate, except that it sets up values
        // fetching which will disable model creation
        $this->annotate($annotations);
        $this->values();
        // Disable other fields from being fetched
        $this->aggregated = true;
        $this->related = false;
        return $this;
    }

    function options($options) {
        // Make an array with $options as the only key
        if (!is_array($options))
            $options = array($options => 1);

        $this->options = array_merge($this->options, $options);
        return $this;
    }

    function hasOption($option) {
        return isset($this->options[$option]);
    }

    function union(QuerySet $other, $all=true) {
        // Values and values_list _must_ match for this to work
        if ($this->countSelectFields() != $other->countSelectFields())
            throw new Exception\OrmError('Union queries must have matching values counts');

        // TODO: Clear OFFSET and LIMIT in the $other query

        $this->chain[] = array($other, $all);
        return $this;
    }

    protected function getCompiler() {
        return Manager::getBackend($this->model)->getCompiler();
    }

    /**
     * Purge the database records for the records matching the criteria in
     * this QuerySet. Returns the number of records deleted as reported by
     * the database.
     */
    function delete() {
        $backend = Manager::getBackend($this->model);
        $compiler = $backend->getCompiler();
        // XXX: Mark all in-memory cached objects as deleted
        $stmt = $compiler->compileBulkDelete($this);
        $exec = $backend->execute($stmt);
        return $exec->affected_rows();
    }

    /**
     * Perform a bulk update operation. Send a keyed array of fields and new
     * new values for the update.
     */
    function update(array $what) {
        $backend = Manager::getBackend($this->model);
        $compiler = $backend->getCompiler();
        $stmt = $compiler->compileBulkUpdate($this, $what);
        $exec = $backend->execute($stmt);
        return $exec->affected_rows();
    }

    function __clone() {
        unset($this->_iterator);
        unset($this->_count);
        unset($this->query);
    }

    // Delegate other methods to the iterator
    function __call($func, $args) {
        return call_user_func_array(array($this->getIterator(), $func), $args);
    }

    // IteratorAggregate interface
    function getIterator($iterator=false) {
        if (!isset($this->_iterator)) {
            $class = $iterator ?: $this->getIteratorClass();
            $it = new $class($this);
            if (!isset($this->options[self::OPT_NOCACHE])) {
                if ($this->iter == self::ITER_MODELS)
                    // Add findFirst() and such
                    $it = new ModelResultSet($it);
                else
                    $it = new CachedResultSet($it);
            }
            else {
                $it = $it->getIterator();
            }
            $this->_iterator = $it;
        }
        return $this->_iterator;
    }

    function getIteratorClass() {
        switch ($this->iter) {
        case self::ITER_MODELS:
            return __NAMESPACE__.'\ModelInstanceManager';
        case self::ITER_HASH:
            return __NAMESPACE__.'\HashArrayIterator';
        case self::ITER_ROW:
            return __NAMESPACE__.'\FlatArrayIterator';
        }
    }

    // ArrayAccess interface
    function offsetExists($offset) {
        return $this->getIterator()->offsetExists($offset);
    }
    function offsetGet($offset) {
        return $this->getIterator()->offsetGet($offset);
    }
    function offsetUnset($a) {
        throw new \Exception(__('QuerySet is read-only'));
    }
    function offsetSet($a, $b) {
        throw new \Exception(__('QuerySet is read-only'));
    }

    function __toString() {
        return (string) $this->getQuery();
    }

    function getQuery($options=array()) {
        if (isset($this->query))
            return $this->query;

        // Load defaults from model
        $model = $this->model;

        $query = clone $this;
        $options += $this->options;
        // Be careful not to make local modifications based on model meta
        // compilation preferences
        if (isset($options[self::OPT_NOSORT]))
            $query->ordering = array();
        elseif (!$query->ordering && $model::getMeta('ordering'))
            $query->ordering = $model::$meta['ordering'];
        if (false !== $query->related && !$query->values && $model::getMeta('select_related'))
            $query->related = $model::getMeta('select_related');
        if (!$query->defer && $model::getMeta('defer'))
            $query->defer = $model::getMeta('defer');

        $compiler = Manager::getBackend($model)->getCompiler();
        return $this->query = $compiler->compileSelect($query);
    }

    /**
     * Fetch a model class which can be used to render the QuerySet as a
     * subquery to be used as a JOIN.
     */
    function asView() {
        $that = $this;
        return new class extends ModelBase {
            static $meta = array(
                'view' => true,
            );

            static function getQuery($compiler) {
                return ' ('.$that->getQuery().') ';
            }

            static function getSqlAddParams($compiler) {
                return $that->toSql($compiler, $that->model);
            }
        };
    }

    function serialize() {
        $info = get_object_vars($this);
        unset($info['query']);
        unset($info['limit']);
        unset($info['offset']);
        unset($info['_iterator']);
        unset($info['_count']);
        return serialize($info);
    }

    function unserialize($data) {
        $data = unserialize($data);
        foreach ($data as $name => $val) {
            $this->{$name} = $val;
        }
    }
}
