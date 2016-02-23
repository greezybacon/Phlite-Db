<?php
namespace Phlite\Db\Backends;

interface Transaction {

    // Transaction interface
    function rollback();
    function commit();
    function beginTransaction();
}
