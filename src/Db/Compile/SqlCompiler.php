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
     * Check if the values match given the operator.
     *
     * Parameters:
     * $record - <ModelBase> An model instance representing a row from the
     *      database
     * $field - Field path including operator used as the evaluated
     *      expression base. To check if field `name` startswith something,
     *      $field would be `name__startswith`.
     * $check - <mixed> value used as the comparison. This would be the RHS
     *      of the condition expressed with $field. This can also be a Q
     *      instance, in which case, $field is not considered, and the Q
     *      will be used to evaluate the $record directly.
     *
     * Throws:
     * OrmException - if $operator is not supported     *
     */
    static function evaluate($record, $field, $check) {
        static $ops; if (!isset($ops)) { $ops = array(
            'exact' => function($a, $b) { return is_string($a) ? strcasecmp($a, $b) == 0 : $a == $b; },
            'isnull' => function($a, $b) { return is_null($a) == $b; },
            'gt' => function($a, $b) { return $a > $b; },
            'gte' => function($a, $b) { return $a >= $b; },
            'lt' => function($a, $b) { return $a < $b; },
            'lte' => function($a, $b) { return $a <= $b; },
            'range' => function($a, $b) { return $a >= $b[0] && $a <= $b[1]; },
            'contains' => function($a, $b) { return stripos($a, $b) !== false; },
            'startswith' => function($a, $b) { return stripos($a, $b) === 0; },
            'endswith' => function($a, $b) { return $b === '' || strcasecmp(substr($a, -strlen($b))) === 0; },
            'regex' => function($a, $b) { return preg_match("/$a/iu", $b); },
            'hasbit' => function($a, $b) { return ($a & $b) == $b; },
        ); }
        list($field, $path, $operator) = static::splitCriteria($field);
        if (!isset($ops[$operator]))
            throw new Exception\OrmError($operator.': Unsupported operator');

        if ($record instanceof Model\ModelBase) {
            if ($path)
                $record = $record->getByPath($path);
            $field = $record->get($field);
        }
        else {
            $field = $record[$field];
        }

        //var_dump($operator, $field, $check);
        return $ops[$operator]($field, $check);
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
     * $path - (string) name of the field to join
     * $model - (VerySimpleModel) root model for references in the $field
     *      parameter
     *
     * Returns:
     * 4-tuple [$field, $model, $transform, $alias], where field is the
     * compiled text, $model is the foreign model determined from the path,
     * $transform is the lookup mechanism to be used to form the comparison,
     * and $alias is the table alias of the foreign model in the query.
     */
    function getField($path, $model) {
        if (is_string($path))
            $path = explode('__', $path);

        // Find the model with the field in question
        list($model, $alias, $path) = $this->explodePath($path, $model);
        
        // Process the transform part of the path
        list($transform, $field) = $this->getFieldTransform($path, $model, $alias);
        
        // Note: $alias could be removed here and fetched with a ::getAlias($model)
        // method
        return [$field, $model, $transform, $alias];
    }
    
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
        // case, we need to make sure the next item in the path represenets
        // a field on the model
        if (count($path) > 0) {
            if (!$model::getMeta()->hasField($path[0])) {
                // Use the $tail, if possible
                if (isset($tail) && $model::getMeta()->hasField($tail)) {
                    array_unshift($path, $tail);
                }
                // If it's an annotation, that's ok
                elseif (!isset($this->annotations[$path[0]])) {
                    throw new \Exception('Bad news dood');
                }
            }
        }
        // (2) the path is empty. In this case, a field for the transform
        // needs to be specified.
        elseif (isset($tail)) {
            $path = [$tail];
        }
        
        // If no join was followed, use the root model alias
        if (!isset($alias))
            $alias = $this->joins['']['alias'];
        
        return [$model, $alias, $path];
    }
    
    function getFieldTransform(array $path, $model, $alias) {
        $field_name = array_shift($path);

        if (isset($this->annotations[$field_name])) {
            $field = $field_name = $this->annotations[$field_name];
        }
        elseif ($field = $model::getMeta()->getField($field_name)) {
            $field_name = "{$alias}.".$this->quote($field_name);
        }
        else {
            throw new Exception\OrmError(sprintf(
               'Model `%s` does not have a relation called `%s`',
                $model, $field_name));   
        }
        
        if (!$path)
            $path = ['exact'];

        $transform = $field_name;
        while (count($path)) {
            // This might look a bit cryptic. Basically, the first transform
            // should be based on the $field and the field_name. The subsequent
            // ones should become nested transforms.
            $field = $transform = $field->getTransform($path[0], $transform);
            array_shift($path);

            // TODO: If the transform is a pseudo field (like year), then add 
            // `exact` to the path and continue.
        }
        
        return [$transform, $field_name];
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
                list($field, $model, $transform, ) = $this->getField($field, $model);
                if ($value === null)
                    $filter[] = sprintf('%s IS NULL', $field);
                else
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

    // XXX: Move this to another interface to include complete support for
    //      model migrations
    abstract function compileCreate($modelClass, $fields, $constraints=array());
    abstract function compileDrop($modelClass);
}
