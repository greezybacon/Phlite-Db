<?php
namespace Phlite\Db;

use Phlite\Db\Exception;
use Phlite\Db\Manager;

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
    protected $manager;
    protected $mode;
    protected $dirty = array();
    protected $backends = array();
    protected $distributed;
    var $started = false;
    var $log;

    const FLAG_AUTOFLUSH    = 0x0001;
    const FLAG_RETRY_COMMIT = 0x0002;

    const TYPE_UPDATE = 1;
    const TYPE_DELETE = 2;
    const TYPE_INSERT = 3;

    function __construct(Manager $manager, $flags=0, $log=null) {
        $this->manager = $manager;
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
     */
    function setFlag($mode) {
        $this->mode |= $mode;
    }

    function getLog() {
        return $this->log;
    }

    function add(Model\ModelBase $model, $callback, $args=null) {
        $this->captureBackend($model);
        if ($this->log->isDeleted($model)) {
            // No further changes necessary as the object will be deleted
            return;

        $record = $this->log->add($type, $model);
        if ($this->mode & self::FLAG_AUTOFLUSH)
            $this->play($record);
    }

    function delete(Model\ModelBase $model) {
        $this->captureBackend($model);
        $record = $this->log->remove($type, $model);

        if ($this->mode & self::FLAG_AUTOFLUSH)
            $this->play($record);
    }

    protected function captureBackend($model) {
        // Capture the number of backends we're dealing with
        $backend = $this->manager->getConnection($model); 
        $bkkey = spl_object_hash($backend);

        if ($this->started && !isset($this->backend[$bkkey])) {
            if (!$this->distributed) {
                $this->rollback();
                throw new Exception\OrmError('Unable to add a backed to a local transaction');
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
            if ($distributed && $backend instanceof DistributedTransaction)
                $bk->startDistributed();
            elseif (!$distributed && $backend instanceof Transaction)
                $bk->beginTransaction();
            else
                throw new Exception\OrmError(sprintf(
                    '%s: Does not support transactions',
                    get_class($backend)
                ));
        }

        // Assume that the transaction starters would throw an exception if
        // unable to start.
        $this->started = true;
        $this->distributed = $distributed;
    }

    /**
     * Send all dirty records to the database. This does not imply a commit,
     * it just syncs the underlying databases and calls the save callbacks
     * for the models. It does, however, imply starting a transaction if one
     * has not yet been started.
     */
    function flush() {
        $this->start();
        foreach ($this->log->iterDirty() as $tmd) {
            $this->play($tmd);
        }
    }

    /**
     * Actually process a log entry and send it to the database
     */
    protected function play($tmd) {
        list($type, $model, $dirty) = $tmd;
        if ($type === self::TYPE_DELETE) {
            $this->manager->_delete($model);
        }
        else {
            $this->manager->_save($model);
        }
    }

    function commit($retry=null) {
        $this->flush();

        // If $retry not specified, used configured default
        if (!isset($retry)) {
            $retry = $this->flags & self::FLAG_RETRY_COMMIT > 0;
        }

        if (!$this->distributed) {
            // NOTE: There's only one backend here, but it's in a list
            foreach ($this->backends as $bk) {
                try {
                    if ($bk->commit()) {
                        $this->log->reset();
                    }
                }
                catch (Exception $ex) {
                    // XXX: Rollback if unsuccessful?
                }
            }
        }

        $success = true;
        foreach ($this->backends as $bk) {
            if (!($success &= $bk->tryCommit()))
                break;
        }
        foreach ($this->backends as $bk) {
            if ($success)   $bk->finishCommit();
            else            $bk->undoCommit();
        }
        if (!$success) {
            // Attempt retry if configured
            if ($retry) {
                $this->replayLog($this->log);
                if ($success = $this->commit(false))
                    return $success;
            }

            // An exception is necessary here because the callbacks for
            // the model updates have already been executed.
            throw new Exception\OrmError('Distributed transaction commit failed');
        }
        $this->log->reset();
        return $success;
    }

    function rollback() {
        // Anything currently dirty is no longer dirty
        foreach ($this->log->iterDirty() as $tmd) {
            list($type, $model, $dirty) = $tmd;
            $model->__rollback($dirty);
        }

        if (!$this->started)
            // Yay! nothing to do at the database layer
            return true;

        if ($this->distributed) {
            // TODO: Abandon distributed transactions
        }

        // NOTE: There's only one backend here, but it's in a list
        foreach ($this->backends as $bk) {
            return $bk->rollback();
        }
    }

    /**
     * Revert a committed transaction. This will result in aborting the
     * current transaction, starting a new transaction, and playing the
     * current transaction log in reverse. That is, inserts are played as
     * deletes and so forth.
     */
    function revert() {
        $this->rollback();
        $this->start($this->distributed);
        $this->replayLog($this->log->reverse());
    }

    /**
     * Replay a transaction log. The log must be specified. To use the
     * internal log, use
     *
     * >>> $session->replayLog($session->getLog());
     */
    function replayLog(TransactionLog $log) {
        foreach ($log as $record)
            $this->play($record);
    }
}
