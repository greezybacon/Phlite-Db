<?php
namespace Phlite\Db;

interface Transaction {

    // Transaction interface
    function rollback();
    function commit();
    function beginTransaction();
}
