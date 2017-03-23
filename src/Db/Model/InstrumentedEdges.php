<?php
namespace Phlite\Db\Model;

class EdgeModelInstanceManager
extends ModelInstanceManager {
    function getOrBuild($modelClass, $fields, $cache=true) {
        $m = parent::getOrBuild($modelClass, $fields, $cache);
        if ($m) {
            $m = AnnotatedModel::wrap($m->{$this->relation}, $m, $this->targetModel);
        }
        return $m;
    }
}

class InstrumentedEdges
extends InstrumentedList {
    var $targetModel;
    var $relation;

    function __construct($fkey, $queryset=false,
        $iterator=EdgeModelInstanceManager::class
    ) {
        parent::__construct($fkey, $queryset, $iterator);
        list(, , $join) = $fkey;
        list($this->relation, $this->targetModel) = $join['through'];
        $this->queryset = $this->queryset->select_related($this->relation);
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