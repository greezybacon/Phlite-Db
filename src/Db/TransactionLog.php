<?php

namespace Phlite\Db;

use Phlite\Db\Model;
use Phlite\Util;

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
 */
class TransactionLog
extends Util\ArrayObject {
    static $uid=0;
    protected $journal = array();
    protected $history;
    var $id;

    function __construct(/* Iterable */ $iterable=array()) {
        parent::__construct($iterable);
        $this->id = ++static::$uid;
    }

    function getId() {
        return $this->id;
    }

    protected function getKey(Model\ModelBase $model) {
        if ($model->__new__)
            return spl_object_hash($model);

        return sprintf('%s.%s',
            $model::$meta->model, implode('.', $model->get('pk')));
    }

    /**
     * Add model updates to the transaction log.
     */
    function add(Model\ModelBase $model) {
        $key = $this->getKey($model);
        $dirty = $this->getDirtyList($model);
        if (isset($this->storage[$key])) {
            // Type is immutable once in the log
            return $this->storage[$key]->update($dirty);
        }
        $type = $model->__new__
            ? TransactionLogEntry::TYPE_INSERT
            : TransactionLogEntry::TYPE_UPDATE;
        return $this[$key] = new TransactionLogEntry($type, $model, $dirty);
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
            $dirty[$f] = array(null, $v);
        }
        return $this[$key] = new TransactionLogEntry(
            TransactionLogEntry::TYPE_DELETE,
            $model, $dirty);
    }

    /**
     * Check to see if the cited model has been marked for deletion
     */
    function isDeleted($model) {
        if ($model->__new__)
            return false;

        $key = $this->getKey($model);
        if (!isset($this->storage[$key]))
            return false;

        $this->storage[$key]->isDeleted();
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
        foreach ($this as $key=>$entry) {
            $log[$key] = $entry->getReverse();
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
        $this->journal[] = $item;
    }

    /**
     * Read items recently added to the transaction log. This method uses an
     * generator and so supports interruption. As items are iterated over
     * from this method, they are removed from the dirty list. Therefore,
     * subsequent calls to this method will not result in the same items
     * returned.
     */
    function iterJournal() {
        foreach ($this->journal as $key=>$record) {
            yield $record;
            unset($this->journal[$key]);
        }
    }

    /**
     * Fetch a list of the dirty items added to the journal. The journal is
     * also cleared. Multiple calls to this method will yield differing
     * results.
     */
    function getJournal() {
        // Fetch and clear the journal
        $j = new Util\ArrayObject($this->journal);
        $this->journal = array();
        return $j;
    }

    /**
     * Fetch a list of dirty items in the journal sorted in such a way that
     * items in the list with foreign key save dependencies are sorted later
     * in the list. That is, if two objects were created, and one references
     * the other, the other will sort earlier in the list so that it can be
     * saved and its ID number can be placed in the latter one before it is
     * saved.
     */
    function getSortedJournal() {
        $journal = $this->getJournal();
        $journal->sort(function($record) {
            $model = $record->getModel();
            // If the model has references to foreign primary keys, then
            // those objects should be saved first.
            $fkeys = 0;
            $pk = $model::getMeta('pk');
            foreach ($model::getMeta('joins') as $prop => $j) {
                if (// Model has a relationship for this join
                    isset($model->__ht__[$prop])
                    // ... and its an object, a Model object
                    && ($foreign = $model->__ht__[$prop])
                    && $foreign instanceof Model\ModelBase
                    // ... and the fkey is not part of this model's pkey
                    && !in_array($j['local'], $pk)
                    // ... and the local fkey field is not set
                    && null === $model->get($j['local'])
                    // ... and the foreign object is new
                    && $foreign->__new__
                ) {
                    $fkeys++;
                }
            }
            return $fkeys;
        });
        return $journal;
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

    function __clone() {
        $this->id = ++static::$uid;
    }

    /**
     * If this log was previously ::reset(), then this will retrieve the
     * previous content of the log. If multiple resets have been issued,
     * then the histories can be traversed by following the string of
     * ::getPrevious() calls. The oldest log will return NULL for a
     * previous history request.
     */
    function getPrevious($transaction_id=false) {
        $history = $this->history;
        if ($transaction_id)
            while ($history->getId() != $transaction_id)
                if (!($history = $history->getPrevious()))
                    throw new \Exception("{$transaction_id}: No such transaction in this history");
        return $history;
    }

    /**
     * (re)apply and entry to this log. This is generally used for retry and
     * undoCommit operations.
     */
    function replay(TransactionCoordinator $trans) {
        foreach ($this as $entry) {
            $entry->replay($trans);
        }
    }
}
