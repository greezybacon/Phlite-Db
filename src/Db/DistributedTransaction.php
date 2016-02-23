<?php
namespace Phlite\Db\Backends;

interface DistributedTransaction {

    /**
     * Start a distributed transaction on this connection. An optional
     * transaction id can be returned which should be passed to the other
     * methods managing the global transaction.
     */
    function startDistributed();

    function tryCommit($id=false);
    function undoCommit($id=false);
    function finishCommit($id=false);
}
