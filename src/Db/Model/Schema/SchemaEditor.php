<?php
namespace Phlite\Db\Model\Schema;

use Phlite\Db\Fields;

/**
 * Class to describe changes to a model. This is used to describe an ALTER TABLE
 * statement to be issued to an SQL database, or similar for document oriented
 * databases.
 */
class SchemaEditor
implements \IteratorAggregate {
     protected $changes = array();
     
     const TYPE_ADD = 1;
     const TYPE_REMOVE = 2;
     const TYPE_REPLACE = 3;

     function __construct($model) {
         $this->model = $model;
     }

     // Changes to fields -------------------------------------

     function addFields($fields) {
         foreach ($fields as $name=>$F)
             $this->addField($name, $F);
     }

     function addField($name, Fields\BaseField $field) {
         $T = $this->changes[$name] = new FieldDescriptor($field, $name, self::TYPE_ADD);
         return $T;
     }
     
     function dropField($name) {
         $T = $this->getField($name);
         $T->disposition = self::TYPE_REMOVE;
         return $T;
     }
     
     /**
      * Fetch a field which will be replaced with changes made
      */
     function getField($name) {
         if (!isset($this->changes[$name])) {
             $field = $this->model::getMeta()->getField($name);
             $this->changes[$name] = new FieldDescriptor($field, $name, self::TYPE_REPLACE);
         }
         return $this->changes[$name];
     }
     
     function getIterator() {
         return new \ArrayIterator($this->changes);
     }
}