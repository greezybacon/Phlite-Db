<?php

namespace Phlite\Db\Compile;

use Phlite\Db\Backend;
use Phlite\Db\Exception;
use Phlite\Db\Model;
use Phlite\Db\Util;

abstract class SqlCompiler {

    var $options = array();
    var $joins = array();
    var $aliases = array();
    var $alias_num = 1;

    protected $params = array();
    protected $conn;

    static $operators = array(
        'exact' => '%$1s = %$2s'
    );

    function __construct(Backend $conn, $options=false) {
        if ($options)
            $this->options = array_merge($this->options, $options);
        $this->conn = $conn;
        if ($options['subquery'])
            $this->alias_num += 150;
    }

    function getParent() {
        return $this->options['parent'];
    }

    /**
     * Split a criteria item into the identifying pieces: path, field, and
     * operator.
     */
    static function splitCriteria($criteria) {
        static $operators = array(
            'exact' => 1, 'isnull' => 1,
            'gt' => 1, 'lt' => 1, 'gte' => 1, 'lte' => 1, 'range' => 1,
            'contains' => 1, 'like' => 1, 'startswith' => 1, 'endswith' => 1, 'regex' => 1,
            'in' => 1, 'intersect' => 1,
            'hasbit' => 1,
        );
        $path = explode('__', $criteria);
        $operator = false;
        if (!isset($options['table'])) {
            $field = array_pop($path);
            if (isset($operators[$field])) {
                $operator = $field;
                $field = array_pop($path);
            }
        }
        return array($field, $path, $operator ?: 'exact');
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
     * Comparison functions supported by this function are defined for each
     * respective SqlCompiler subclass; however at least these functions
     * should be defined:
     *
     *      function    a__function => b
     *      ----------+------------------------------------------------
     *      exact     | a is exactly equal to b
     *      gt        | a is greater than b
     *      lte       | b is greater than a
     *      lt        | a is less than b
     *      gte       | b is less than a
     *      ----------+------------------------------------------------
     *      contains  | (string) b is contained within a
     *      statswith | (string) first len(b) chars of a are exactly b
     *      endswith  | (string) last len(b) chars of a are exactly b
     *      like      | (string) a matches pattern b
     *      ----------+------------------------------------------------
     *      in        | a is in the list or the nested queryset b
     *      ----------+------------------------------------------------
     *      isnull    | a is null (if b) else a is not null
     *
     * If no comparison function is declared in the field descriptor,
     * 'exact' is assumed.
     *
     * Parameters:
     * $field - (string) name of the field to join
     * $model - (VerySimpleModel) root model for references in the $field
     *      parameter
     * $options - (array) extra options for the compiler
     *      'table' => return the table alias rather than the field-name
     *      'model' => return the target model class rather than the operator
     *      'constraint' => extra constraint for join clause
     *
     * Returns:
     * (mixed) Usually array<field-name, operator> where field-name is the
     * name of the field in the destination model, and operator is the
     * requestion comparison method.
     */
    function getField($field, $model, $options=array()) {
        // Break apart the field descriptor by __ (double-underbars). The
        // first part is assumed to be the root field in the given model.
        // The parts after each of the __ pieces are links to other tables.
        // The last item (after the last __) is allowed to be an operator
        // specifiction.
        list($field, $parts, $op) = static::splitCriteria($field);
        $operator = static::$operators[$op];
        $path = '';
        $rootModel = $model;

        // Call pushJoin for each segment in the join path. A new JOIN
        // fragment will need to be emitted and/or cached
        $joins = array();
        $push = function($p, $model) use (&$joins, &$path) {
            $J = $model::getMeta('joins');
            if (!($info = $J[$p])) {
                throw new Exception\OrmError(sprintf(
                   'Model `%s` does not have a relation called `%s`',
                    $model, $p));
            }
            $crumb = $path;
            $path = ($path) ? "{$path}__{$p}" : $p;
            $joins[] = array($crumb, $path, $model, $info);
            // Roll to foreign model
            return $info['fkey'];
        };

        foreach ($parts as $i=>$p) {
            list($model) = $push($p, $model);
        }

        // If comparing a relationship, join the foreign table
        // This is a comparison with a relationship — use the foreign key
        $J = $model::getMeta('joins');
        if (isset($J[$field])) {
            list($model, $field) = $push($field, $model);
        }

        // Apply the joins list to $this->pushJoin
        $last = count($joins) - 1;
        $constraint = false;
        foreach ($joins as $i=>$A) {
            // Add the conststraint as the last arg to the last join
            if ($i == $last)
                $constraint = $options['constraint'];
            $alias = $this->pushJoin($A[0], $A[1], $A[2], $A[3], $constraint);
        }

        if (!isset($alias)) {
            // Determine the alias for the root model table
            $alias = (isset($this->joins['']))
                ? $this->joins['']['alias']
                : $this->quote($rootModel::getMeta('table'));
        }

        if (isset($options['table']) && $options['table'])
            $field = $alias;
        elseif (isset($this->annotations[$field]))
            $field = $this->annotations[$field];
        elseif ($alias)
            $field = $alias.'.'.$this->quote($field);
        else
            $field = $this->quote($field);
        if (isset($options['model']) && $options['model'])
            $operator = $model;
        return array($field, $operator);
    }

    /**
     * Uses the compiler-specific `compileJoin` function to compile the join
     * statement fragment, and caches the result in the local $joins list. A
     * new alias is acquired using the `nextAlias` function which will be
     * associated with the join. If the same path is requested again, the
     * algorithm is short-circuited and the originally-assigned table alias
     * is returned immediately.
     */
    function pushJoin($tip, $path, $model, $info, $constraint=false) {
        // TODO: Build the join statement fragment and return the table
        // alias. The table alias will be useful where the join is used in
        // the WHERE and ORDER BY clauses

        // If the join already exists for the statement-being-compiled, just
        // return the alias being used.
        if (!$constraint && isset($this->joins[$path]))
            return $this->joins[$path]['alias'];

        // TODO: Support only using aliases if necessary. Use actual table
        // names for everything except oddities like self-joins

        $alias = $this->nextAlias();
        // Keep an association between the table alias and the model. This
        // will make model construction much easier when we have the data
        // and the table alias from the database.
        $this->aliases[$alias] = $model;

        // TODO: Stash joins and join constraints into local ->joins array.
        // This will be useful metadata in the executor to construct the
        // final models for fetching
        // TODO: Always use a table alias. This will further help with
        // coordination between the data returned from the database (where
        // table alias is available) and the corresponding data.

        // Correlate path and alias immediately so that they could be
        // referenced in the ::compileJoin method if necessary.
        $T = array('alias' => $alias);
        $this->joins[$path] = $T;
        $this->joins[$path]['sql'] = $this->compileJoin($tip, $model, $alias, $info, $constraint);
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
     * $Q - (Util\Q) constraint represented in a Q instance
     * $model - (string) root model class for all the field references in
     *      the Util\Q instance
     * $slot - (int) slot for inputs to be placed. Useful to differenciate
     *      inputs placed in the joins and where clauses for SQL backends
     *      which do not support named parameters.
     *
     * Returns:
     * (CompiledExpression) object containing the compiled expression (with
     * AND, OR, and NOT operators added). Furthermore, the $type attribute
     * of the CompiledExpression will allow the compiler to place the
     * constraint properly in the WHERE or HAVING clause appropriately.
     */
    function compileQ(Util\Q $Q, $model, $slot=false) {
        $filter = array();
        $type = CompiledExpression::TYPE_WHERE;
        foreach ($Q->constraints as $field=>$value) {
            // Handle nested constraints
            if ($value instanceof Util\Q) {
                $filter[] = $T = $this->compileQ($value, $model, $slot);
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
                $filter[] = $this->compileQ(new Util\Q($criteria), $model, $slot);
            }
            // Handle simple field = <value> constraints
            else {
                list($field, $op) = $this->getField($field, $model);
                if ($field instanceof Util\Aggregate) {
                    // This constraint has to go in the HAVING clause
                    $field = $field->toSql($this, $model);
                    $type = CompiledExpression::TYPE_HAVING;
                }
                if ($value === null)
                    $filter[] = sprintf('%s IS NULL', $field);
                elseif ($value instanceof Util\Field)
                    $filter[] = sprintf($op, $field, $value->toSql($this, $model));
                // Allow operators to be callable rather than sprintf
                // strings
                elseif (is_callable($op))
                    $filter[] = call_user_func($op, $field, $value, $model);
                else
                    $filter[] = sprintf($op, $field, $this->input($value, $slot));
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
            return $q->sql;
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
    abstract function addParam($what, $param=false);

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
    abstract function inspectTable($table, $details=false, $cacheable=true);

    // XXX: Move this to another interface to include complete support for
    //      model migrations
    abstract function compileCreate($modelClass, $fields, $constraints=array());
    abstract function compileDrop($modelClass);
}
