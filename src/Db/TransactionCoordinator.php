<?php
namespace Phlite\Db;

use Phlite\Db\Exception;
use Phlite\Db\Router;
use Phlite\Db\Signals;

/**
 * Utility class to allow several operations to be performed atomically in a
 * transaction at the database layer. Instead of using the ActiveRecord
 * patttern and saving the objects directly, objects are added to a
 * transaction. The transaction can then be rollled-back, or committed.
 *
 * Transactions across multiple databases and connections are supported. If
 * any transaction fails to commit, all transactions not yet committed are
 * rolled back. If the database backends support two-phase commits, then the
 * transactions are only committed if all connections committed
 * successfully.
 *
 * A transaction log is used to track all requested database operations. The
 * log can be fetched at any time (which might be useful for audit logging).
 * The transaction can also be retried using the log, in the event a commit
 * failed for reasons where a retry would succeed.
 *
 * Transactions can also be used to group similar operations together to
 * support, for instance, things like bulk inserts.
 *
 * Transaction objects should not be created directly. Use the manager
 * instance to get access to a current transaction.
 */
class TransactionCoordinator {
    protected $session;
    protected $mode;
    protected $dirty = array();
    protected $backends = array();
    protected $distributed;
    var $started = false;
    var $log;

    const FLAG_AUTOFLUSH    = 0x0001;
    const FLAG_RETRY_COMMIT = 0x0002;
    const FLAG_NO_TRACK     = 0x0004;

    function __construct(Session $session, $flags=0, $log=null) {
        $this->session = $session;
        $this->log = $log ?: new TransactionLog();
        $this->setFlag($flags);
    }

    /**
     * Set the mode of the transaction.
     *
     * FLAG_AUTOFLUSH
     *      Send updates and deletes to the database immediately. The save
     *      callback is invoked when the object is added to the transaction
     *      and updates to the same object are not deduplicated.
     *
     * FLAG_RETRY_COMMIT
     *      Retry the commit one time if the commit fails
     *
     * FLAG_NO_TRACK
     *      Do not use a log to track the transaction changes. Perhaps a
     *      performance boost. Cannot be used with FLAG_RETRY_COMMIT, because
     *      commit retry requires a log.
     */
    function setFlag($mode) {
        $this->mode |= $mode;
    }

    function getLog() {
        return $this->log;
    }

    function add(Model\ModelBase $model) {
        // If there's nothing in the model to be saved, then we're done
        if (count($model->__dirty__) === 0)
            return;

        if ($this->log->isDeleted($model))
            // No further changes necessary as the object will be deleted
            return;

        $this->captureBackend($model);
        $record = $this->log->add($model);
        if ($this->mode & self::FLAG_AUTOFLUSH)
            return $record->send();
    }

    function delete(Model\ModelBase $model) {
        $this->captureBackend($model);
        $record = $this->log->remove($model);

        if ($this->mode & self::FLAG_AUTOFLUSH)
            return $this->play($record);
    }

    protected function captureBackend($model) {
        // Capture the number of backends we're dealing with
        $backend = Router::getBackend($model);
        $bkkey = spl_object_hash($backend);

        if ($this->started && count($this->backends) && !isset($this->backends[$bkkey])) {
            if (!$this->distributed) {
                $this->rollback();
                throw new Exception\OrmError('Cannot add a backend to an open local transaction. Transaction must be started distributed');
            }
            elseif (!$backend instanceof Db\Backends\DistributedTransaction) {
                throw new Exception\OrmError(sprintf(
                    '%s: Cannot participate in distributed transactions',
                    get_class($backend)
                ));
            }
            // Attempt to join the party
            $backend->startDistributed();
        }

        $this->backends[$bkkey] = $backend;
    }

    /**
     * Start a transaction on the database backends if not yet started.
     *
     * Parameters:
     * $distributed - (boolean:auto) if set to TRUE, then the transaction
     *      started will configure the backends seen thus far for a
     *      distributed transaction. In such a mode, models added to the
     *      transaction in other backends will automatically join the
     *      distributed transaction. The default is to automatically start a
     *      distributed transaction if more than one backend is represented
     *      among the dirty models in the log. If set to FALSE, then
     *      distributed transactions are never set up.
     */
    protected function start($distributed=null) {
        if ($this->started)
            return;

        $distributed = isset($distributed) ? $distributed
            : count($this->backends) > 1;

        if (!$distributed && count($this->backends) > 1)
            throw new Exception\OrmError('Multiple backends require a distributed transaction');

        foreach ($this->backends as $bk) {
            if ($distributed && $bk instanceof DistributedTransaction)
                $bk->startDistributed();
            elseif (!$distributed && $bk instanceof Transaction)
                $bk->beginTransaction();
            else
                throw new Exception\OrmError(sprintf(
                    '%s: Does not support transactions',
                    get_class($bk)
                ));
        }

        // Assume that the transaction starters would throw an exception if
        // unable to start.
        $this->started = true;
        $this->distributed = $distributed;
    }

    function isStarted() {
        return $this->started;
    }

    /**
     * Send all dirty records to the database. This does not imply a commit,
     * it just syncs the underlying databases and calls the save callbacks
     * for the models. It does, however, imply starting a transaction if one
     * has not yet been started.
     */
    function flush() {
        $this->start();
        foreach ($this->log->getSortedJournal() as $entry) {
            $entry->send();
        }
    }

    /**
     * Commit changes recorded in the session at the database layer. This is
     * performed first by performing a flush() and sending any outstanding
     * changes to the databases as part of the transaction. Then a COMMIT is
     * requested, followed by a second COMMIT for distributed transactions.
     *
     * Parameters:
     * retry - (boolean) if TRUE, then the transaction will be automatically 
     *      replayed and attempted a second time if the COMMIT fails. This
     *      might be useful for situations similar to Galera, where a
     *      a transaction can fail simply because similar edits were made and
     *      successfully committed on a neighbor server before this
     *      transaction was completed with a commit.
     *
     *      If unspecified, then the FLAG_RETRY_COMMIT flag will be used to
     *      provide the default.
     */
    function commit($retry=null) {
        $this->flush();

        // If $retry not specified, used configured default
        if (!isset($retry)) {
            $retry = $this->mode & self::FLAG_RETRY_COMMIT > 0;
        }

        $success = true;
        if (!$this->distributed) {
            // NOTE: There's only one backend here, but it's in a list
            reset($this->backends);
            $bk = current($this->backends);
            try {
                if (!$bk->commit())
                    $success = false;
            }
            catch (\Exception $ex) {
                // XXX: Rollback if unsuccessful?
                throw $ex;
            }
        }
        else {
            foreach ($this->backends as $bk) {
                if (!($success = $bk->tryCommit()))
                    break;
            }
            foreach ($this->backends as $bk) {
                if ($success)   $bk->finishCommit();
                else            $bk->undoCommit();
            }
        }

        if (!$success) {
            // Attempt retry if configured
            if ($retry) {
                $this->replayLog($this->log);
                if ($success = $this->commit(false))
                    return $success;
            }

            if ($this->distributed)
                // An exception is necessary here because the callbacks for
                // the model updates have already been executed.
                throw new Exception\OrmError('Distributed transaction commit failed');
        }

        $this->reset();
        return $success;
    }

    function reset($rollback=false) {
        if (!$this->started)
            return true;

        if ($rollback)
            $this->rollback();

        $this->started = false;
        $this->log->reset();
    }

    /**
     * Parameters:
     * $$revert - (boolean:false), if set to TRUE, the original state of the
     *      ORM models will be restored in memory after the transaction has
     *      been aborted in the database.
     *
     * Returns:
     * (boolean) TRUE if the rollback succeeded, and FALSE otherwise.
     */
    function rollback($revert=true) {
        if (!$this->started)
            // Yay! nothing to do at the database layer
            return true;

        if ($this->distributed) {
            // TODO: Abandon distributed transactions
        }

        // NOTE: There's only one backend here, but it's in a list
        $success = true;
        foreach ($this->backends as $bk) {
            if (!($rv = $bk->rollback()))
                $success = $rv;
        }

        if ($revert) {
            $this->revert();
        }
        $this->log->clear();
        $this->started = false;
        return $success;
    }

    /**
     * Undo everything applied in this log (everything not yet committed).
     * All changes made to the models are undone. Then, the log is cleared
     * so that further flushes and commits will not send the reverted
     * changes to the database.
     *
     * This method should ot be used directly. Instead, it is intended to
     * be used with ::rollback() so that a failed transaction can rolled
     * back at the database layer, and then rolled back in memory in PHP
     * (using this method).
     */
    function revert(TransactionLog $log=null) {
        // Don't send to the database, nor start a transaction. This will
        // attempt to synchronize the state or the models before the
        // transaction log was started.
        $log = $log ?: $this->log;
        foreach ($log as $entry) {
            $entry->revert();
        }
    }

    /**
     * Revert a committed transaction. This will result in aborting the
     * current transaction, starting a new transaction, and playing the
     * current transaction log in reverse. That is, inserts are played as
     * deletes and so forth.
     *
     * The changes applied are not automatically committed.
     */
    function undoCommit($transaction_id=false) {
        $previous = $this->log->getPrevious();
        $this->reset();
        $this->start($this->distributed);
        $this->revert($previous);
        $this->replayLog($previous->reverse());
        $this->flush();
    }

    /**
     * Fetch the current transaction log ID, which can be used if it should
     * be reverted by the ::undoCommit() method.
     */
    function getId() {
        return $this->log->getId();
    }

    /**
     * Replay a transaction log. The log must be specified. To use the
     * internal log, use
     *
     * >>> $session->replayLog($session->getLog());
     */
    function replayLog(TransactionLog $log) {
        return $log->replay($this);
    }

    /**
     * Retry the log. This is performed by replaying the edits recorded in
     * the log and then optionally sending them the database and requesting
     * a commit.
     */
    function retry($commit=true) {
        $this->log->replay();
        if (!$commit)
            return $this->commit(false);
    }
}
