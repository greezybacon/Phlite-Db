<?php

namespace Phlite\Db;

use Phlite\Db\Model;

/**
 * Utility class used to keep track of all updates performed inside a single
 * transaction. The transaction could then be undone at a later time using
 * the transaction log. Furthermore, the transaction log could be used to
 * track the transaction in an audit log after the transaction is committed.
 *
 * This can also be used by for database backends which do not support
 * distributed transactions to keep an individualized log of updates sent to
 * the database backend. If an ::undoCommit() is requested, then this log
 * could be used to reverse a committed transaction.
 *
 * This log also keeps a recently-added list. It can be iterated over via
 * the ::iterJournal() method which will send the recently added items in
 * order, oldest first. Items retrieved from the journal are automatically
 * removed.
 *
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
class TransactionLog
extends Util\ArrayObject {
    var $history;

    protected function getKey(Model\ModelBase $model) {
        return sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->get('pk')));
    }

    /**
     * Add model updates to the transaction log.
     */
    function add(Model\ModelBase $model) {
        $key = $this->getKey($model);
        $_dirty = null;
        if (isset($this->storage[$key])) {
            // Type is immutable once in the log
            list($type,,$_dirty) = $this->log[$key];
        }
        else {
            $type = $model->__new__
                ? TransactionCoordinator::TYPE_INSERT
                : TransactionCoordinator::TYPE_UPDATE
        }
        $dirty = $this->getDirtyList($model, $_dirty);
        return $this[$key] = array($type, $model, $dirty);
    }

    /**
     * Mark a model for deletion. The current fields of the model are
     * recorded so that the model could be recreated if this log were
     * reversed.
     */
    function remove(Model\ModelBase $model) {
        $key = $this->getKey($model);
        // Capture the model's current state for deletes, so that a reverse
        // would be able to recreate the record
        $dirty = array();
        foreach ($model->getDbFields() as $f=>$v) {
            $dirty[$f] = array($v, null);
        }
        return $this[$key] = array(
            TransactionCoordinator::TYPE_DELETE,
            $model, $dirty);
    }

    /**
     * Check to see if the cited model has been marked for deletion
     */
    function isDeleted($model) {
        $key = $this->getKey($model);
        if (!isset($this->storage[$key]))
            return false;

        list($type) = $this->storage[$key];
        return $type = TransactionCoordinator::TYPE_DELETE;
    }

    /**
     * For a model, fetch a list of changes as an array of (old, new)
     * values. The array is indexed by the model field names.
     *
     * Parameters:
     * $model - <ModelBase> the model to inspect
     * $old_dirty - A previous return value from this function. If passed,
     *      the current dirty list will be merged.
     */
    function getDirtyList($model, $old_dirty=null) {
        $changed = $old_dirty ?: array();
        foreach ($model->__dirty__ as $field=>$old) {
            $changed[$field] = array($old, $model->__ht__[$field]);
        }
        return $changed;
    }

    /**
     * Reverse the log update types and dirty list direction so that the
     * records in the log could be used to replay a transaction in reverse.
     * That is, the log could be used to revert a committed transaction.
     *
     * Returns:
     * <TransactionLog> log with the log events reversed from this log.
     */
    function reverse() {
        $reverse = array();
        $log = new static();
        foreach ($this as $key=>$info) {
            list($type, $model, $dirty) = $info;
            switch ($type) {
            case TransactionCoordinator::TYPE_INSERT:
                $type = TransactionCoordinator::TYPE_DELETE;
                break;
            case TransactionCoordinator::TYPE_DELETE:
                $type = TransactionCoordinator::TYPE_INSERT;
                break;
            }
            $rdirty = array();
            foreach ($dirty as $f=>$old_new) {
                $rdirty[$f] = array_reverse($old_new);
            }
            $log[$key] = array($type, $model, $rdirty);
        }
        return $log;
    }

    /**
     * Add the item to the log and also record it in the recently-added
     * (journal) list. Only the key is stored again, so as not to waste
     * memory.
     */
    function offsetSet($offset, $item) {
        parent::offsetSet($offset, $item);
        $this->journal[$offset] = 1;
    }

    /**
     * Read items recently added to the transaction log. This method uses an
     * generator and so supports interruption. As items are iterated over
     * from this method, they are removed from the dirty list. Therefore,
     * subsequent calls to this method will not result in the same items
     * returned.
     */
    function iterJournal() {
        reset($this->journal);
        while (list($key,) = each($this->journal)) {
            yield $this->storage[$key];
            unset($this->journal[$key];
        }
    }

    /**
     * Push the current state on a history stack, accessible via
     * ::getPrevious(). The state of this log is then reset to an empty
     * list.
     */
    function reset() {
        $this->history = clone $this;
        $this->clear();
    }

    /**
     * If this log was previously ::reset(), then this will retrieve the
     * previous content of the log. If multiple resets have been issued,
     * then the histories can be traversed by following the string of
     * ::getPrevious() calls. The oldest log will return NULL for a
     * previous history request.
     */
    function getPrevious() {
        return $this->history;
    }
}
