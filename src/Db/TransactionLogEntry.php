<?php
namespace Phlite\Db;

/**
 * Log-Entry-Structure:
 * Internally, the log is stored as a list of arrays with the change type,
 * the model instance involved, and the dirty list. The dirty list itself is
 * a keyed array containing information about the old and new values updated
 * in the model instance.
 *
 * $model_pk => array(
 *      TYPE_UPDATE,
 *      <ModelBase instance>,
 *      array(
 *          $field => array(
 *              $old,
 *              $new
 *          ),
 *      ),
 *  );
 */
class TransactionLogEntry {
    protected $type;
    protected $model;
    protected $dirty;
    
    function __construct($type, $model, $dirty) {
        $this->type = $type;
        $this->model = $model;
        $this->dirty = $dirty;
    }
    
    function getType()      { return $this->type; }
    function getModel()     { return $this->model; }
    function getDirty()     { return $this->dirty; }
    
    function getReverseType() {
        switch ($this->type) {
        case TransactionCoordinator::TYPE_INSERT:
            return TransactionCoordinator::TYPE_DELETE;
        case TransactionCoordinator::TYPE_DELETE:
            return TransactionCoordinator::TYPE_INSERT;
        }
        return $this->type;
    }
    
    function isDeleted() {
        return $this->type = TransactionCoordinator::TYPE_DELETE;
    }
    
    // Update the dirty list using the new stuff as priority
    function update($dirty) {
        $this->dirty = $dirty + $this->dirty;
    }
    
    // Send the update to the database. Since ActiveRecord is already
    // implemented for the models, just use it here.
    function send() {
        if ($this->type === TransactionCoordinator::TYPE_DELETE) {
            return $this->model->delete();
        }
        else {
            return $this->model->save();
        }
    }
    
    // Undo the in-memory representation of what would be changed with
    // ::send() and rolled back in the database
    function revert() {        
        switch ($this->type) {
        case TransactionCoordinator::TYPE_INSERT:
            Model\ModelInstanceManager::uncache($this->model);
            break;
        case TransactionCoordinator::TYPE_DELETE:
            Model\ModelInstanceManager::cache($this->model);
            break;
        case TransactionCoordinator::TYPE_UPDATE:
            foreach ($this->dirty as $f=>$v) {
                list($old, $new) = $v;
                $this->model->set($f, $old);
            }
        }
    }
}