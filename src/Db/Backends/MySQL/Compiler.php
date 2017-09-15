<?php

namespace Phlite\Db\Backends\MySQL;

use Phlite\Db\Compile\Statement;
use Phlite\Db\Compile\SqlCompiler;
use Phlite\Db\Compile\CompiledExpression;
use Phlite\Db\Exception;
use Phlite\Db\Fields;
use Phlite\Db\Manager;
use Phlite\Db\Model\ModelBase;
use Phlite\Db\Model\QuerySet;
use Phlite\Db\Model\QuerysetView;
use Phlite\Db\Util;

class Compiler extends SqlCompiler {

    protected $input_join_count = 0;
    protected $conn;

    static $operators = array(
        'exact' => '%1$s = %2$s',
        'contains' => array('self', '__contains'),
        'startswith' => array('self', '__startswith'),
        'endswith' => array('self', '__endswith'),
        'regex' => array('self', '__regex'),
        'gt' => '%1$s > %2$s',
        'lt' => '%1$s < %2$s',
        'gte' => '%1$s >= %2$s',
        'lte' => '%1$s <= %2$s',
        'isnull' => array('self', '__isnull'),
        'like' => '%1$s LIKE %2$s',
        'hasbit' => '%1$s & %2$s != 0',
        'in' => array('self', '__in'),
        'intersect' => array('self', '__find_in_set'),
        'range' => array('self', '__between'),
    );

    // Thanks, http://stackoverflow.com/a/3683868
    function like_escape($what, $e='\\') {
        return str_replace(array($e, '%', '_'), array($e.$e, $e.'%', $e.'_'), $what);
    }

    function __contains($a, $b) {
        # {%a} like %{$b}%
        # Escape $b
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("%$b%"));
    }
    function __startswith($a, $b) {
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("$b%"));
    }
    function __endswith($a, $b) {
        $b = $this->like_escape($b);
        return sprintf('%s LIKE %s', $a, $this->input("%$b"));
    }

    function __in($a, $b) {
        if (is_array($b)) {
            $vals = array_map(array($this, 'input'), $b);
            $b = '('.implode(', ', $vals).')';
        }
        // MySQL is almost always faster with a join. Use one if possible
        // MySQL doesn't support LIMIT or OFFSET in subqueries. Instead, add
        // the query as a JOIN and add the join constraint into the WHERE
        // clause.
        elseif ($b instanceof QuerySet
            && ($b->isWindowed() || $b->countSelectFields() > 1 || $b->chain)
        ) {
            if (count($b->values) < 1)
                throw new Exception\OrmError('Did you forget to specify a column with ->values()?');
            $f1 = array_values($b->values)[0];
            $view = $b->asView();
            $alias = $this->pushJoin($view, $a, $view, array('constraint'=>array()));
            return sprintf('%s = %s.%s', $a, $alias, $this->quote($f1));
        }
        else {
            $b = $this->input($b);
        }
        return sprintf('%s IN %s', $a, $b);
    }

    function __regex($a, $b) {
        // Strip slashes and options
        if ($b[0] == '/')
            $b = preg_replace('`/[^/]*$`', '', substr($b, 1));
        return sprintf('%s REGEXP %s', $a, $this->input($b));
    }

    function __isnull($a, $b) {
        return $b
            ? sprintf('%s IS NULL', $a)
            : sprintf('%s IS NOT NULL', $a);
    }

    function __find_in_set($a, $b) {
        if (is_array($b)) {
            $sql = array();
            foreach (array_map(array($this, 'input'), $b) as $b) {
                $sql[] = sprintf('FIND_IN_SET(%s, %s)', $b, $a);
            }
            $parens = count($sql) > 1;
            $sql = implode(' OR ', $sql);
            return $parens ? ('('.$sql.')') : $sql;
        }
        return sprintf('FIND_IN_SET(%s, %s)', $b, $a);
    }

    function __between($a, $b) {
        // FIXME: Crash if b not an array of exactly two items
        $b = array_map(array($this, 'input'), $b);
        return sprintf('%1$s BETWEEN %2$s AND %3$s', $a, $b[0], $b[1]);
    }

    function compileJoinConstraint($model, $local, $foreign, $table, $alias) {
        list($rmodel, $right) = $foreign;
        // Support a constant constraint with
        // "'constant'" => "Model.field_name"
        if ($local[0] == "'") {
            $lhs = $this->input(trim($local, '\'"'));
        }
        else {
            $lhs = $model::getMeta()->getField($local)
                ->getJoinConstraint($local, $table, $this);
        }
        // Support local constraint
        // field_name => "'constant'"
        if ($rmodel[0] == "'" && !$right) {
            $rhs = $this->input(trim($rmodel, '\'"'));
        }
        else {
            $rhs = $rmodel::getMeta()->getField($right)
                ->getJoinConstraint($right, $alias, $this);
        }
        return array($lhs, $rhs);
    }

    function compileJoin($tip, $model, $alias, $info, $extra=false) {
        $constraints = array();
        $join = ' JOIN ';
        if (isset($info['null']) && $info['null'])
            $join = ' LEFT'.$join;
        if (isset($this->joins[$tip]))
            $table = $this->joins[$tip]['alias'];
        elseif (!$model instanceof QuerysetView)
            $table = $this->quote($model::getMeta('table'));
        foreach ($info['constraint'] as $local => $foreign) {
            list($rmodel) = $foreign;
            list($lhs, $rhs) = $this->compileJoinConstraint($model, $local, 
                $foreign, $table, $alias);
            $constraints[] = "$lhs = $rhs";
        }
        // Support extra join constraints
        if ($extra instanceof Util\Q) {
            $constraints[] = $this->compileQ($extra, $model);
        }
        if (!isset($rmodel))
            $rmodel = $model;
        // Support inline views
        $table = ($rmodel instanceof QuerysetView)
            // XXX: Support parameters from the nested query
            ? $rmodel->getSqlAddParams($this)
            : $this->quote($rmodel::getMeta('table'));
        $base = "{$join}{$table} {$alias}";
        return array($base, $constraints);
    }

    function addParam($what) {
        $this->params[] = $what;
        return ':'.(count($this->params));
    }

    function quote($what) {
        return "`$what`";
    }

    function escape($what, $quote=true) {
        // Use a connection to do this
    }

    /**
     * getWhereClause
     *
     * This builds the WHERE ... part of a DML statement. This should be
     * called before ::getJoins(), because it may add joins into the
     * statement based on the relationships used in the where clause
     */
    protected function getWhereHavingClause($queryset) {
        $model = $queryset->model;
        $constraints = $this->compileConstraints($queryset->constraints, $model);
        $where = $having = array();
        foreach ($constraints as $C) {
            if ($C->type == CompiledExpression::TYPE_WHERE)
                $where[] = $C;
            else
                $having[] = $C;
        }
        if (isset($queryset->extra['where'])) {
            foreach ($queryset->extra['where'] as $S) {
                $where[] = "($S)";
            }
        }
        if ($where)
            $where = ' WHERE '.implode(' AND ', $where);
        if ($having)
            $having = ' HAVING '.implode(' AND ', $having);
        return array($where ?: '', $having ?: '');
    }

    protected function getLimit($queryset) {
        $sql = '';
        if ($queryset->limit)
            $sql .= ' LIMIT '.$queryset->limit;
        if ($queryset->offset)
            $sql .= ' OFFSET '.$queryset->offset;
        return $sql;
    }

    function compileCount(QuerySet $queryset) {
        $q = clone $queryset;
        // Drop extra fields from the queryset
        $q->related = $q->anotations = false;
        $model = $q->model;
        $q->values = $model::getMeta('pk');
        $stmt = $q->getQuery(array(QuerySet::OPT_NOSORT => true));
        $stmt->sql = 'SELECT COUNT(*) FROM ('.$stmt->sql.') __';
        return $stmt;
    }

    function getOrderByFields(QuerySet $queryset, $model) {
        $orders = array();
        if (!($columns = $queryset->getSortFields()))
            return $orders;

        foreach ($columns as $sort) {
            $dir = 'ASC';
            if (is_array($sort)) {
                list($sort, $dir) = $sort;
            }
            if ($sort instanceof Util\Expression) {
                $field = $sort->toSql($this, $model);
            }
            else {
                if ($sort[0] == '-') {
                    $dir = 'DESC';
                    $sort = substr($sort, 1);
                }
                // If the field is already an annotation, then don't
                // compile the annotation again below. It's included in
                // the select clause, which is sufficient
                if (isset($this->annotations[$sort]))
                    $field = $this->quote($sort);
                else
                    list($field) = $this->getField($sort, $model);
            }
            if ($field instanceof Util\Expression)
                $field = $field->toSql($this, $model);
            // TODO: Throw exception if $field can be indentified as
            //       invalid

            $orders[] = "{$field} {$dir}";
        }
        return $orders;
    }

    function compileSelect(QuerySet $queryset) {
        $model = $queryset->model;

        // Use an alias for the root model table
        $table = $model::getMeta('table');
        $this->joins[''] = array('alias' => ($rootAlias = $this->nextAlias()));

        // Compile the WHERE clause
        $this->annotations = $queryset->annotations ?: array();
        list($where, $having) = $this->getWhereHavingClause($queryset);

        // Compile the ORDER BY clause
        $sort = '';
        if (!isset($this->options[QuerySet::OPT_NOSORT])) {
            if ($orders = $this->getOrderByFields($queryset, $model))
                $sort = ' ORDER BY '.implode(', ', $orders);
        }

        // Compile the field listing
        $fields = $group_by = $fieldMap = array();
        $table = $this->quote($model::getMeta('table')).' '.$rootAlias;

        // Handle fields in the recordset (including those in related tables)
        if ($queryset->related) {
            $count = 0;
            $theseFields = array();
            $defer = $queryset->defer ?: array();
            // Add local fields first
            foreach ($model::getMeta()->getFieldNames() as $f) {
                // Handle deferreds
                if (isset($defer[$f]))
                    continue;
                $fields[$rootAlias . '.' . $this->quote($f)] = true;
                $theseFields[] = $f;
            }
            $fieldMap[] = array($theseFields, $model);
            // Add the JOINs to this query
            foreach ($queryset->related as $sr) {
                // XXX: Sort related by the paths so that the shortest paths
                //      are resolved first when building out the models.
                $full_path = '';
                $parts = array();
                // Track each model traversal and fetch data for each of the
                // models in the path of the related table
                foreach (explode('__', $sr) as $field) {
                    $full_path .= $field;
                    $parts[] = $field;
                    $theseFields = array();
                    list($fmodel, $alias) = $this->explodePath($parts, $model);
                    foreach ($fmodel::getMeta()->getFieldNames() as $f) {
                        // Handle deferreds
                        if (isset($defer[$sr . '__' . $f]))
                            continue;
                        elseif (isset($fields[$alias.'.'.$this->quote($f)]))
                            continue;
                        $fields[$alias . '.' . $this->quote($f)] = true;
                        $theseFields[] = $f;
                    }
                    if ($theseFields) {
                        $fieldMap[] = array($theseFields, $fmodel, $parts);
                    }
                    $full_path .= '__';
                }
            }
        }
        // Support retrieving only a list of values rather than a model
        elseif ($queryset->values) {
            foreach ($queryset->values as $alias=>$v) {
                list($f) = $this->getField($v, $model);
                $unaliased = $f;
                if ($f instanceof Util\Expression)
                    $fields[$f->toSql($this, $model, $alias)] = true;
                else {
                    if (!is_int($alias))
                        $f .= ' AS '.$this->quote($alias);
                    $fields[$f] = true;
                }
                // If there are annotations, add in these fields to the
                // GROUP BY clause
                if ($queryset->annotations && !$queryset->distinct)
                    $group_by[] = $unaliased;
            }
        }
        // Simple selection from one table, with deferred fields
        elseif ($queryset->defer) {
            foreach ($model::getMeta('fields') as $f) {
                if (isset($queryset->defer[$f]))
                    continue;
                $fields[$rootAlias .'.'. $this->quote($f)] = true;
            }
        }
        elseif (!$queryset->aggregated) {
            $fields[$rootAlias.'.*'] = true;
        }
        $fields = array_keys($fields);

        // Add in annotations
        if ($queryset->annotations) {
            foreach ($queryset->annotations as $alias=>$A) {
                // The root model will receive the annotations, add in the
                // annotation after the root model's fields
                $T = $A->toSql($this, $model, $alias);
                if ($fieldMap) {
                    array_splice($fields, count($fieldMap[0][0]), 0, array($T));
                    $fieldMap[0][0][] = $alias;
                }
                else {
                    // No field map — just add to end of field list
                    $fields[] = $T;
                }
            }
            // If no group by has been set yet, use the root model pk
            if (!$group_by && !$queryset->aggregated) {
                foreach ($model::getMeta('pk') as $pk)
                    $group_by[] = $rootAlias .'.'. $pk;
            }
        }

        // Add in SELECT extras
        if (isset($queryset->extra['select'])) {
            foreach ($queryset->extra['select'] as $name=>$expr) {
                if ($expr instanceof Util\Expression)
                    $expr = $expr->toSql($this, false, $name);
                else
                    $expr = sprintf('%s AS %s', $expr, $this->quote($name));
                $fields[] = $expr;
            }
        }

        // Consider DISTINCT criteria
        if (isset($queryset->distinct)) {
            foreach ($queryset->distinct as $d)
                list($group_by[]) = $this->getField($d, $model);
        }
        $joins = $this->getJoins($queryset);
        $group_by = $group_by ? ' GROUP BY '.implode(', ', $group_by) : '';
        $limit = $this->getLimit($queryset);

        $sql = 'SELECT '.implode(', ', $fields).' FROM '
            .$table.$joins.$where.$group_by.$having.$sort.$limit;

        // UNIONS
        if ($queryset->chain) {
            // If the main query is sorted, it will need parentheses
            if ($parens = (bool) $sort)
                $sql = "($sql)";
            foreach ($queryset->chain as $qs) {
                list($qs, $all) = $qs;
                $q = $qs->getQuery(array(QuerySet::OPT_NOSORT => true));
                // Rewrite the parameter numbers so they fit the parameter numbers
                // of the current parameters of the $compiler
                $self = $this;
                $S = preg_replace_callback("/:(\d+)/",
                function($m) use ($self, $q) {
                    $self->params[] = $q->params[$m[1]-1];
                    return ':'.count($self->params);
                }, $q->sql);
                // Wrap unions in parentheses if they are windowed or sorted
                if ($parens || $qs->isWindowed() || count($qs->getSortFields()))
                    $S = "($S)";
                $sql .= ' UNION '.($all ? 'ALL ' : '').$S;
            }
        }

        switch ($queryset->lock) {
        case QuerySet::LOCK_EXCLUSIVE:
            $sql .= ' FOR UPDATE';
            break;
        case QuerySet::LOCK_SHARED:
            $sql .= ' LOCK IN SHARE MODE';
            break;
        }

        return new Statement($sql, $this->params, $fieldMap);
    }

    function __compileUpdateSet($model, array $pk) {
        $fields = array();
        foreach ($model->__dirty__ as $field=>$old) {
            if ($model->__new__ or !in_array($field, $pk)) {
                $fields[] = sprintf('%s = %s', $this->quote($field),
                    $this->input($model->get($field)));
            }
        }
        return ' SET '.implode(', ', $fields);
    }

    function compileUpdate(ModelBase $model, $limit1=true) {
        $pk = $model::getMeta('pk');
        $sql = 'UPDATE '.$this->quote($model::getMeta('table'));
        $sql .= $this->__compileUpdateSet($model, $pk);
        // Support PK updates
        $criteria = array();
        foreach ($pk as $f) {
            $criteria[$f] = @$model->__dirty__[$f] ?: $model->get($f);
        }
        $sql .= ' WHERE '.$this->compileQ(new Util\Q($criteria), $model);
        if ($limit1)
            $sql .= ' LIMIT 1';

        return new Statement($sql, $this->params);
    }

    function compileInsert(ModelBase $model) {
        $pk = $model::getMeta('pk');
        $sql = 'INSERT INTO '.$this->quote($model::getMeta('table'));
        $sql .= $this->__compileUpdateSet($model, $pk);

        return new Statement($sql, $this->params);
    }

    function compileDelete(ModelBase $model, $limit1=true) {
        $table = $model::getMeta('table');

        $where = ' WHERE '.implode(' AND ',
            $this->compileConstraints(array(new Util\Q($model->pk)), $model));
        $sql = 'DELETE FROM '.$this->quote($table).$where;
        if ($limit1)
            $sql .= ' LIMIT 1';
        return new Statement($sql, $this->params);
    }

    function compileBulkDelete(QuerySet $queryset) {
        $model = $queryset->model;
        $table = $model::getMeta('table');
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'DELETE '.$this->quote($table).'.* FROM '
            .$this->quote($table).$joins.$where;
        return new Statement($sql, $this->params);
    }

    function compileBulkUpdate(QuerySet $queryset, array $what) {
        $model = $queryset->model;
        $table = $model::getMeta('table');
        $set = array();
        foreach ($what as $field=>$value)
            $set[] = sprintf('%s = %s', $this->quote($field),
                $this->input($value, $model));
        $set = implode(', ', $set);
        list($where, $having) = $this->getWhereHavingClause($queryset);
        $joins = $this->getJoins($queryset);
        $sql = 'UPDATE '.$this->quote($table).$joins.' SET '.$set.$where;
        return new Statement($sql, $this->params);
    }

    // Returns meta data about the table used to build queries
    function inspectTable($meta, $details=false, $cacheable=true) {
        static $cache = array();
        $table = $meta['table'];
        $hints = $meta['field_types'];

        // XXX: Assuming schema is not changing — add support to track
        //      current schema
        if ($cacheable && isset($cache[$table]))
            return $cache[$table];

        $stmt = new Statement("SELECT * FROM `$table` WHERE 1=0");
        $driv = $this->conn->getDriver($stmt);
        $driv->execute();
        $fields = $driv->res->fetch_fields();
        foreach ($fields as $F) {
            $C = ($details) ? $this->makeField($F, $hints) : $F->name;
            $columns[$F->name] = $C;
        }
        // XXX: This assumes usage of mysqli
        mysqli_free_result($fields);

        return $cacheable ? ($cache[$table] = $columns) : $columns;
    }

    protected function makeField($F, array $hints) {
        $props = [
            'name' => $F->name,
            'table' => $F->table,
            'schema' => $F->db,
            'length' => $F->max_length ?: $F->length,
            'nullable' => ($F->flags & 1) == 0,
            'pk' => ($F->flags & 2) == 2,
        ];
        switch (true) {
        case $F->flags & 512:
            $class = Fields\AutoIdField::class;
            break;
        case in_array($F->type, [1, 2, 3, 8, 9]):
            $class = Fields\IntegerField::class;
            $props += [
                'unsigned' => ($F->flags & 32) == 32,
            ];
            break;
        case in_array($F->type, [12, 10, 7]):
            $class = Fields\DatetimeField::class;
            break;
        case in_array($F->type, [252, 253, 254]):
        default:
            $class = Fields\TextField::class;
            $props += [
                'binary' => ($F->flags & 128) == 128,
                'blob' => ($F->flags & 16) == 16,
            ];
        }

        if (isset($hints[$props['name']]))
            $class = $hints[$props['name']];
        
        return new $class($props);
    }

    function compileCreate($modelClass, $fields, $constraints=array()) {
          $meta = $modelClass::getMeta();
          $columns = array();
          $extras = array();
          foreach ($fields as $name=>$F) {
              $columns[] = $F->getCreateSql($name, $this);
              $extras = array_merge($extras, $F->getConstraints($name));
          }
          foreach ($this->getExtraCreateConstraints($modelClass, $fields) as $C)
              $constraints[] = $C;
          // TODO: Add ForeignKey constraints
          foreach ($constraints as $C) {
              $extras[] = $C->getCreateSql($this);
          }
          return new Statement(sprintf('CREATE TABLE %s (%s%s)',
              $this->quote($meta['table']),
              implode(', ', $columns),
              $extras ? (', ' . implode(', ', $extras)) : ''
          ));
    }

    function getExtraCreateConstraints($modelClass, $fields) {
        return [new Fields\PrimaryKey($modelClass::getMeta('pk'))];
    }

    function compileDrop($modelClass) {
        $sql = sprintf('DROP TABLE %s', $this->quote($modelClass::getMeta('table')));
        return new Statement($sql);
    }
}
