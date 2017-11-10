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

    const TYPE_UPDATE = 1;
    const TYPE_DELETE = 2;
    const TYPE_INSERT = 3;

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
        case self::TYPE_INSERT:
            return self::TYPE_DELETE;
        case self::TYPE_DELETE:
            return self::TYPE_INSERT;
        }
        return $this->type;
    }
    
    function isDeleted() {
        return $this->type = self::TYPE_DELETE;
    }
    
    // Update the dirty list using the new stuff as priority
    function update($dirty) {
        $this->dirty = $dirty + $this->dirty;
    }
    
    // Send the update to the database. Since ActiveRecord is already
    // implemented for the models, just use it here.
    function send() {
        if ($this->type === self::TYPE_DELETE) {
            return $this->model->delete();
        }
        else {
            return $this->model->save();
        }
    }
    
    // Undo the in-memory representation of what would be changed with
    // ::send() and rolled back in the database
    function revert() {
        // Restore the model's dirty list
        foreach ($this->dirty as $field=>$old_new) {
            list($old,) = $old_new;
            $this->model->__dirty__[$field] = $old;
        }

        switch ($this->type) {
        case self::TYPE_INSERT:
            Model\ModelInstanceManager::uncache($this->model);
            break;
        case self::TYPE_DELETE:
            Model\ModelInstanceManager::cache($this->model);
            $this->model->__deleted__ = false;
            break;
        case self::TYPE_UPDATE:
            foreach ($this->dirty as $f=>$v) {
                list($old, $new) = $v;
                $this->model->set($f, $old);
            }
        }
    }

    /**
     * Similar to ::send, except that this entry is replayed back to the
     * log in the way that it was originally added. For instance, if it
     * was originally played as a removal, then it is removed again here.
     * After this method, a flush and/or commit should be issued for the
     * edits to be sent to the database (unless FLAG_AUTO_FLUSH is enabled).
     */
    function replay(TransactionCoordinator $trans) {
        switch ($this->type) {
        case self::TYPE_DELETE:
            $trans->remove($this->model);
            break;
        case self::TYPE_UPDATE:
            $model = $this->model;
            foreach ($this->dirty as $field=>$old_new) {
                list($old, $new) = $old_new;
                $model->set($field, $new);
            }
            $trans->add($model);
            break;
        case self::TYPE_INSERT:
            $this->model->__new__ = true;
            $this->model->__deleted__ = false;
            $trans->add($this->model);
            break;
        }
    }

    function getReverse() {
        $type = $this->getReverseType();
        $rdirty = array();
        foreach ($this->dirty as $f=>$old_new) {
            $rdirty[$f] = array_reverse($old_new);
        }
        return new static($type, $this->model, $rdirty);
    }
}