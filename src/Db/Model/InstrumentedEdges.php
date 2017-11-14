<?php
namespace Phlite\Db\Model;

class EdgeModelInstanceManager
extends ModelInstanceManager {
    protected $relation;
    protected $middleModel;

    function __construct($qs, $glueClass, $relation) {
        parent::__construct($qs);
        $this->relation = $relation;
        $this->middleModel = $glueClass;
    }

    function getOrBuild($modelClass, $fields, $cache=true) {
        $m = parent::getOrBuild($modelClass, $fields, $cache);
        if ($m && $modelClass == $this->middleModel) {
            $m = AnnotatedModel::wrap($m->get($this->relation), $m);
        }
        return $m;
    }
}

class InstrumentedEdges
extends InstrumentedList {
    var $relation;

    function __construct($fkey, $queryset=false) {
        list($middleModel, , $join) = $fkey;
        if (!isset($join['through']))
            throw new \Exception('Edges must define a "through" model');

        list($this->relation, ) = $join['through'];
        parent::__construct($fkey, $queryset);
    }

    protected function setupIterator($queryset) {
        $queryset = $this->queryset->select_related($this->relation);
        return new EdgeModelInstanceManager($queryset, $this->model, $this->relation);
    }

    function add($what, $glue=null) {
        // Since this is a many-to-many relationship, to add an object to the
        // list represented by this relationship, it is a record to the
        // intermediate table which should actually be added to the list.
        // However, the intermediate model (the edge) is overlayed over the
        // target model of the relationship.
        $class = $this->model;
        $glue = $glue ?: new $class();
        $glue->set($this->relation, $what);
        $edge = AnnotatedModel::wrap($what, $glue);
        parent::add($glue);
        return $edge;
    }
}