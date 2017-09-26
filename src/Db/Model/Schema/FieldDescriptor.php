<?php
namespace Phlite\Db\Model\Schema;

/**
 * Placeholder class used to describe a field to be added or removed from
 * a database model.
 */
class FieldDescriptor {
    var $field;
    var $field_name;
    var $disposition;
    var $position = self::POS_LAST;
    var $position_ref;
    
    const POS_FIRST = 1;
    const POS_AFTER = 2;
    const POS_BEFORE = 3;
    const POS_LAST = 4;

    function __construct($field, $name, $disposition) {
        $this->field = $field;
        $this->field_name = $name;
        $this->disposition = $disposition;
    }
    
    function getField() {
        return $this->field;
    }
    
    function getFieldName() {
        return $this->field_name;
    }
    
    // Positional modifiers -----------------------------------
    function after($other) {
        $this->position = self::POS_AFTER;
        $this->position_ref = $other;
    }
    
    function before($other) {
        $this->position = self::POS_BEFORE;
        $this->position_ref = $other;
    }
    
    function first() {
        $this->position = self::POS_FIRST;
    }
    
    function last() {
        $this->position = self::POS_LAST;
    }
    
    function getPosition() {
        return $this->position;
    }
    
    function rename($to) {
    }
    
    function drop() {
        $this->disposition = SchemaEditor::TYPE_REMOVE;
    }
}