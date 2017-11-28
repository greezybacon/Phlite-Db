<?php

namespace Phlite\Db\Compile;

use Phlite\Db\Backend;
use Phlite\Db\Exception;
use Phlite\Db\Fields\IsNullTransform;
use Phlite\Db\Model;
use Phlite\Db\Util;

abstract class SqlCompiler {
    static $platform = 'unknown';

    var $options = array();
    var $joins = array();
    var $aliases = array();
    var $alias_num = 1;

    protected $params = array();
    protected $conn;

    function __construct(Backend $conn, $options=false) {
        if ($options)
            $this->options = array_merge($this->options, $options);
        $this->conn = $conn;
        if (isset($options['subquery']) && $options['subquery'])
            $this->alias_num += 150;
    }

    function getParent() {
        return $this->options['parent'];
    }

    function getBackend() {
        return $this->conn;
    }

    /**
     * Split a path for a model object into several pieces:
     *
     * (1) Object to which the path points
     * (2) Model on which (1) is defined
     * (3) Field on (2) represented by (1)
     * (4) Remaining path (not consumed walking to (1))
     *
     * So the result for splitPath('address__street__exact', <User>)
     * might become ['1 Infinite Way', <Address>, 'street', ['exact']].
     */
    static function splitPath(array $path, $model) {
        $current = $model;
        $prev = $field = null;
        while (count($path)) {
            $P = $path[0];
            if (!isset($current->$P))
                break;
            $prev = $current;
            $current = $current->$P;
            $field = array_shift($path);
        }
        return [$current, $prev, $field, $path];
    }

    /**
     * Check if the values match given the transform. This performs the same
     * operation as the database would in a filter() expression (which would
     * be compiled into the WHERE clause). However, the evaluation is
     * performed in pure PHP.
     *
     * Parameters:
     * $record - <ModelBase> An model instance representing a row from the
     *      database
     * $field - Field path including transform used as the evaluated
     *      expression base. To check if field `name` startswith something,
     *      $field would be `name__startswith`.
     * $check - <mixed> value used as the comparison. This would be the RHS
     *      of the condition expressed with $field.
     *
     * Performance:
     * 13.4us per call. Inclues call to ::splitPath() (~4us) and a call to
     * Trasform::transorm method (~1us). That leaves 8us for getField and
     * getTransform?
     */
    static function evaluate(Model\ModelBase $record, $field, $check) {
        $path = explode('__', $field);
        list($value, $model, $transform, $path) = static::splitPath($path, $record);
        $field = $model::getMeta()->getField($transform);
        for (;;) {
            $path = $path ?: ['exact'];
            foreach ($path as $P) {
                $field = $transform = $field->getTransform($P, $transform);
            }
            // If ending with a Transform instance, add an `exact` lookup
            if ($transform instanceof Lookup)
                break;
            $path = false;
        }
        return $transform->transform($check, $value);
    }

    /**
     * Handles breaking down a field or model search descriptor into the
     * model search path, field, and operator parts. When used in a queryset
     * filter, an expression such as
     *
     * user__email__hostname__contains => 'foobar'
     *
     * would be broken down to search from the root model (passed in,
     * perhaps a ticket) to the user and email models by inspecting the
     * model metadata 'joins' property. The 'constraint' value found there
     * will be used to build the JOIN sql clauses.
     *
     * The 'hostname' will be the field on 'email' model that should be
     * compared in the WHERE clause. The comparison should be made using a
     * 'contains' function, which in MySQL, might be implemented using
     * something like "<lhs> LIKE '%foobar%'"
     *
     * This function will rely heavily on the pushJoin() function which will
     * handle keeping track of joins made previously in the query and
     * therefore prevent multiple joins to the same table for the same
     * reason. (Self joins are still supported).
     *
     * Comparison functions supported by this function are defined are
     * defined as Transforms and are registered to respective Field classes
     *
     * If no transform function is declared in the field descriptor,
     * 'exact' is assumed.
     *
     * Parameters:
     * $path - (string) name of the field to join
     * $model - (Model\ModelBase) root model for references in the $field
     *      parameter
     *
     * Returns:
     * 3-tuple [$field, $model, $transform], where field is the
     * compiled text, $model is the foreign model determined from the path,
     * and $transform is the lookup mechanism to be used to form the
     * comparison.
     */
    function getField($path, $model) {
        if (is_string($path))
            $path = explode('__', $path);

        // Find the model with the field in question
        list($model, $alias, $path) = $this->explodePath($path, $model);

        // Process the transform part of the path
        list($transform, $field) = $this->getFieldTransform($path, $model, $alias);

        // Note: $alias could be removed here and fetched with a ::getAlias($path)
        // method
        return [$field, $model, $transform];
    }

    /**
     * Explode a path into the destination model, corresponding alias,
     * and remaining path. The path is walked as far as possible with the
     * specified root model. When no more components of the path represent
     * properties of the target model, the rest of the path is assumed
     * to be a transformation.
     *
     * If the last item in the path represents a foreign key property, a
     * join to the field in the remote model is assumed.
     */
    function explodePath(array $path, $model) {
        // Walk the $path
        $null = false;
        $leading = '';
        while (count($path)) {
            $next = $path[0];

            $J = $model::getMeta('joins');
            if (!isset($J[$next]))
                break;

            $info = $J[$next];

            // Propogate LEFT joins through other joins. That is, if a
            // multi-join expression is used, the first LEFT join should
            // result in further joins also being LEFT
            if (isset($info['null']))
                $null = $null || $info['null'];
            $info['null'] = $null;

            $tip = $leading;
            $leading = $leading ? "{$leading}__{$next}" : $next;
            $alias = $this->pushJoin($tip, $leading, $model, $info);

            // Roll to foreign model
            list($model, $tail) = $info['fkey'];
            array_shift($path);
        }
        // There are two reasons to arrive here:
        // (1) the next item in the path does not represent a join. In this
        // case, we need to make sure the next item in the path represents
        // a field on the model
        if (count($path) > 0) {
            if (!$model::getMeta()->hasField($path[0])) {
                // Use the $tail, if possible
                if (isset($tail) && $model::getMeta()->hasField($tail)) {
                    array_unshift($path, $tail);
                }
                // If it's an annotation, that's ok
                elseif (!isset($this->annotations[$path[0]])) {
                    throw new \Exception(sprintf(
                        '%s: Model does not have relation called `%s`%s',
                        $model, $path[0], isset($tail)
                            ? sprintf(', and `%s` does not represent a field on the model', $tail)
                            : ''
                    ));
                }
            }
        }
        // (2) the path is empty. In this case, a field for the transform
        // needs to be specified.
        elseif (isset($tail)) {
            $path = [$tail];
        }

        // If no join was followed, use the root model alias. Fall back to
        // the table name if no alias has yet been configured.
        if (!isset($alias))
            $alias = $this->getAlias('') ?: $model::getMeta('table');

        return [$model, $alias, $path];
    }

    /**
     * From the specified model, walk the remaining path to extract the field
     * and a transformation. It is assumed that the path argument does not
     * specify any joins. If joins are needed to be walked, use the
     * ::explodePath method first to obtain the proper forein model and remove
     * the joins from the path.
     *
     * If no transform is specified in the $path, then `exact` is assumed.
     *
     * Returns:
     * Tuple of [transform, field_name], where the transform is a Transform
     * instance which can be used to either evaluate values for the target
     * path or used to build an SQL query.
     *
     * The field_name is the textual name of the field used in the path or
     * an Expression instance representing an annotation to the model. For the
     * former, the field name is prepended with the alias of the target model
     *
     * FIXME:
     * Disambiguate the field_name used as the return, which could be either
     * an annotation or a SQL field text.
     */
    function getFieldTransform(array $path, $model, $alias) {
        $field_name = array_shift($path);

        if (isset($this->annotations[$field_name])) {
            $field = $field_name = $this->annotations[$field_name];
        }
        elseif ($field = $model::getMeta()->getField($field_name)) {
            $field_name = $this->quote($field_name);
            if ($alias)
                $field_name = "{$alias}.{$field_name}";
        }
        else {
            throw new Exception\OrmError(sprintf(
               'Model `%s` does not have a relation called `%s`',
                $model, $field_name));
        }

        $transform = $field_name;
        for (;;) {
            if (!$path)
                $path = ['exact'];
            foreach ($path as $P) {
                // This might look a bit cryptic. Basically, the first transform
                // should be based on the $field and the field_name. The subsequent
                // ones should become nested transforms.
                $field = $transform = $field->getTransform($P, $transform);
            }
            if ($field instanceof Lookup)
                break;
            // If the transform is a pseudo field (like year), then add `exact`
            // to the path and continue.
            $path = false;
        }
        return [$transform, $field_name];
    }

    /**
     * Fetch the alias for the given $path used in this compiler, optionally
     * creating a new alias for the path.
     *
     * If searching for an alias, the path is walked backwards and scanned
     * for the longest path already having an alias. It is assumed that the
     * parts of the path which would be discarded are to define a
     * transformation.
     *
     * If the create flag is specified, then the path is associated with a
     * new alias if one does not yet exist. In this case, it is assumed that
     * the transformation parts of the path have already been stripped.
     */
    function getAlias($path, $create=false) {
        if (!$create) {
            while ($path && !isset($this->aliases[$path])) {
                $path = substr($path, 0, strrpos($path, '__'));
            }
            if (!isset($this->aliases[$path]))
                return null;
        }
        elseif (!isset($this->aliases[$path])) {
            $alias = $this->nextAlias();
            $this->aliases[$path] = $alias;
        }
        return $this->aliases[$path];
    }

    /**
     * Uses the compiler-specific `compileJoin` function to compile the join
     * statement fragment, and caches the result in the local $joins list. A
     * new alias is acquired using the `nextAlias` function which will be
     * associated with the join. If the same path is requested again, the
     * algorithm is short-circuited and the originally-assigned table alias
     * is returned immediately.
     */
    function pushJoin($tip, $path, $model, $info) {
        // TODO: Build the join statement fragment and return the table
        // alias. The table alias will be useful where the join is used in
        // the WHERE and ORDER BY clauses

        // If the join already exists for the statement-being-compiled, just
        // return the alias being used.
        if (isset($this->joins[$path]))
            return $this->joins[$path]['alias'];

        $alias = $this->getAlias($path, true);

        // Correlate path and alias immediately so that they could be
        // referenced in the ::compileJoin method if necessary.
        $T = array('alias' => $alias);
        $this->joins[$path] = $T;
        $this->joins[$path]['sql'] = $this->compileJoin($tip, $model, $alias, $info, null);
        return $alias;
    }

    abstract function compileJoin($tip, $model, $alias, $info, $extra=false);

    /**
     * compileQ
     *
     * Build a constraint represented in an arbitrarily nested Q instance.
     * The placement of the compiled constraint is also considered and
     * represented in the resulting CompiledExpression instance.
     *
     * Parameters:
     * $Q - (Model\Q) constraint represented in a Q instance
     * $model - (string) root model class for all the field references in
     *      the Model\Q instance
     *
     * Returns:
     * (CompiledExpression) object containing the compiled expression (with
     * AND, OR, and NOT operators added). Furthermore, the $type attribute
     * of the CompiledExpression will allow the compiler to place the
     * constraint properly in the WHERE or HAVING clause appropriately.
     */
    function compileQ(Model\Q $Q, $model) {
        $filter = array();
        $type = CompiledExpression::TYPE_WHERE;
        foreach ($Q->constraints as $field=>$value) {
            // Handle nested constraints
            if ($value instanceof Model\Q) {
                $filter[] = $T = $this->compileQ($value, $model);
                // Bubble up HAVING constraints
                if ($T instanceof CompiledExpression
                        && $T->type == CompiledExpression::TYPE_HAVING)
                    $type = $T->type;
            }
            // Handle relationship comparisons with model objects
            elseif ($value instanceof Model\ModelBase) {
                $criteria = array();
                foreach ($value->pk as $f=>$v) {
                    $f = $field . '__' . $f;
                    $criteria[$f] = $v;
                }
                $filter[] = $this->compileQ(new Model\Q($criteria), $model);
            }
            // Handle simple field = <value> constraints
            else {
                list($field, $model, $transform, ) = $this->getField($field, $model);
                if ($value === null) {
                    $transform = new IsNullTransform($field);
                    $value = true;
                }
                $filter[] = $transform->toSql($this, $model, $value);

                if ($transform->isAggregate())
                    $type = CompiledExpression::TYPE_HAVING;
            }
        }
        $glue = $Q->isOred() ? ' OR ' : ' AND ';
        $clause = implode($glue, $filter);
        if (count($filter) > 1)
            $clause = '(' . $clause . ')';
        if ($Q->isNegated())
            $clause = 'NOT '.$clause;
        return new CompiledExpression($clause, $type);
    }

    function compileConstraints($where, $model) {
        $constraints = array();
        foreach ($where as $Q) {
            $constraints[] = $this->compileQ($Q, $model);
        }
        return $constraints;
    }

    /**
     * input
     *
     * Generate a parameterized input for a database query.
     *
     * Parameters:
     * $what - (mixed) value to be sent to the database. No escaping is
     *      necessary. Pass a raw value here.
     * $model - (Class : ModelBase) model used to derive expressions from,
     *      in the event that $what is an Expression.
     *
     * Returns:
     * (string) token to be placed into the compiled SQL statement. This
     * is a colon followed by a number
     */
    function input($what, $model=false) {
        if ($what instanceof Model\QuerySet) {
            $q = $what->getQuery(array(
                Model\Queryset::OPT_NOSORT => !($what->limit || $what->offset))
            );
            $this->params = array_merge($this->params, $q->params);
            return "($q->sql)";
        }
        elseif ($what instanceof Util\Expression) {
            return $what->toSql($this, $model);
        }
        elseif (!isset($what)) {
            return 'NULL';
        }
        else {
            return $this->addParam($what);
        }
    }

    /**
     * Add a parameter to the internal parameters list ($this->params).
     * This is the part of ::input() that is specific to the database backend
     * implementation.
     *
     * Parameters:
     * @see ::input() documentation.
     *
     * Returns:
     * (String) string to be embedded in the statement where the parameter
     * should be used server-side.
     */
    abstract function addParam($what);

    function getParams() {
        return $this->params;
    }

    function getJoins($queryset) {
        $sql = '';
        foreach ($this->joins as $path => $j) {
            if (!isset($j['sql']))
                continue;
            list($base, $constraints) = $j['sql'];
            // Add in path-specific constraints, if any
            if (isset($queryset->path_constraints[$path])) {
                foreach ($queryset->path_constraints[$path] as $Q) {
                    $constraints[] = $this->compileQ($Q, $queryset->model);
                }
            }
            $sql .= $base;
            if ($constraints)
                $sql .= ' ON ('.implode(' AND ', $constraints).')';
        }
        // Add extra items from QuerySet
        if (isset($queryset->extra['tables'])) {
            foreach ($queryset->extra['tables'] as $S) {
                $join = ' JOIN ';
                // Left joins require an ON () clause
                if ($lastparen = strrpos($S, '(')) {
                    if (preg_match('/\bon\b/i', substr($S, $lastparen - 4, 4)))
                        $join = ' LEFT' . $join;
                }
                $sql .= $join.$S;
            }
        }
        return $sql;
    }

    /**
     * quote
     *
     * Quote a field or table for usage in a statement.
     */
    abstract function quote($what);

    /**
     * escape
     *
     * Properly escape a value for use in a query, optionally wrapping in
     * quotes
     */
    abstract function escape($what, $quote=true);

    function nextAlias() {
        // Use alias A1-A9,B1-B9,...
        $alias = chr(65 + (int)($this->alias_num / 9)) . $this->alias_num % 9;
        $this->alias_num++;
        return $alias;
    }

    // Statement compilations
    abstract function compileCount(Model\QuerySet $qs);
    abstract function compileSelect(Model\QuerySet $qs);
    abstract function compileUpdate(Model\ModelBase $model);
    abstract function compileInsert(Model\ModelBase $model);
    abstract function compileDelete(Model\ModelBase $model);
    abstract function compileBulkDelete(Model\QuerySet $queryset);
    abstract function compileBulkUpdate(Model\QuerySet $queryset, array $what);

    // XXX: Place this in another interface to define more specific type and forms of inspection
    abstract function inspectTable($meta, $details=false, $cacheable=true);

    // Database platform inspection
    function getPlatform() {
        return static::$platform;
    }

    function isPlatform($platform) {
        return $this->getPlatform() == $platform;
    }
}
