<?php

namespace Phlite\Db\Compile;

use Phlite\Db\Backend;

interface SqlDriver {

    function __construct(Statement $stmt, Backend $bk);

    // Execute the statement — necessary for DML statements
    function execute();
    // Release resouces from the statement and records
    function close();

    function fetchRow();
    function fetchArray();

    /**
     * insert_id
     *
     * Fetch auto-id of previous insert statement
     */
    function insert_id();

    /**
     * affected_rows
     *
     * Retrieve the number of affected rows from the previous DML statement
     */
    function affected_rows();

    /**
     * Retrieve information about the recordset columns. Used to inspect
     * the database schema.
     */
    function getColumnNames();
}
