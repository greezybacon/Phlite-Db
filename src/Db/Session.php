<?php
namespace Phlite\Db;

/**
 * Class: Session
 *
 * Represents a connection between the database Manager and a transaction
 * coordinator and supplies convenience methods to serve as the intermediary.
 */
class Session {
    protected $transaction;
    protected $autostart;
    protected $flags;

    function __construct($autostart=true, $flags=0) {
        $this->autostart = $autostart;
        $this->flags = $flags;
    }

    /**
     * Fetch the current transaction optionally beginning a new transaction
     * if not already started.
     */
     function getTransaction($autostart=null, $flags=null) {
        if (!isset($this->transaction)
            && (isset($autostart) ? $autostart : $this->autostart)
        ) {
            $this->transaction = $this->openTransaction(
                isset($flags) ? $flags : $this->flags);
        }
        return $this->transaction;
    }

    /**
     * Add a model to a new or current transaction. The model will be
     * inserted or updated depending on whether or not the model is marked
     * as new. The transaction will need to be flushed before the model
     * will be sent to the database, and a commit will need to be made
     * before the change will be permanent.
     */
    function add(Model\ModelBase $model, $callback=null, $args=null) {
        return $this->getTransaction()->add($model, $callback, $args);
    }

    /**
     * Delete a model inside the current transaction. The model will be
     * marked as deleted immediately, but will be deleted from the database
     * when the transaction is flushed. It will be permanently removed after
     * the transaction is committed.
     */
    function remove(Model\ModelBase $model, $callback=null, $args=null) {
        return $this->getTransaction()->delete($model, $callback, $args);
    }

    /**
     * Start all model updates in a transaction. All future calls to
     * ::save() and ::delete() will be placed in the transaction and
     * commited with the transaction.
     *
     * Parameters:
     * $mode - Operation mode for the transaction. See the FLAG_* flags on
     *      the TransactionCoordinator class for valid flag settings.
     *
     * Returns:
     * <TransactionCoordinatory> newly created transaction
     */
    function openTransaction($mode=0) {
        if (isset($this->transaction))
            throw new Exception\OrmError(
                'Transaction already started. Use `commit` or `rollback` to complete the current transaction before staring a new one');

        $this->transaction = new TransactionCoordinator($this, $mode);
        return $this->transaction;
    }

    function flush() {
        if ($this->transaction)
            return $this->transaction->flush();
    }

    /**
     * Commit the current transaction. Transactions must be started with
     * ::beginTransaction(). Transactions are automatically coordinated
     * among several databases where supported.
     */
    function commit() {
        if (!isset($this->transaction))
            throw new Exception\OrmError('Transaction not started');

        $rv = $this->transaction->commit();
        if ($rv)
            unset($this->transaction);

        return $rv;
    }

    function rollback() {
        if (!isset($this->transaction))
            throw new Exception\OrmError('Transaction not started');

        $rv = $this->transaction->rollback();
        unset($this->transaction);

        // XXX: The internal model cache will likely contain references to
        // model objects whose changes were rolled back.

        return $rv;
    }

    function retry($transaction=null) {
        $transaction = $transaction ?: $this->transaction;
        if (!isset($transaction))
            throw new Exception\OrmError('No transaction specified to be retried');

        $transaction->retry($this);
    }
}