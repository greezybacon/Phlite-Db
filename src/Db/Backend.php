<?php
namespace Phlite\Db;

use Phlite\Db\Model;

/**
 * Class: Db\Backend
 *
 * Connector between the database Manager and the backend Compiler.
 */
abstract class Backend {
    var $charset;

    abstract function __construct(array $info);

    abstract function connect();

    function close() {}

    /**
     * Gets a compiler compatible with this database engine that can compile
     * and execute a queryset or DML request.
     */
    abstract function getCompiler($options=false);

    abstract function getDdlCompiler($options=false);

    abstract function getDriver(Compile\Statement $stmt);

    function execute(Compile\Statement $stmt) {
        $exec = $this->getDriver($stmt);
        $exec->execute();
        return $exec;
    }

    /**
     * deleteModel
     *
     * Delete a model object from the underlying database. This method will
     * also clear the model cache of the specified model so future lookups
     * would mean database lookups or NULL.
     *
     * Returns:
     * <SqlExecutor> — an instance of SqlExecutor which can perform the
     * actual execution (via ::execute())
     */
     function deleteModel(Model\ModelBase $model) {
        $stmt = $this->getCompiler()->compileDelete($model);
        $ex = $this->getDriver($stmt);
        $ex->execute();
        if ($ex->affected_rows() != 1)
            return false;

        return $ex;
    }

    /**
     * saveModel
     *
     * Send model changes to the database. This method will compile an
     * insert or an update as necessary.
     *
     * Returns:
     * <SqlDriver> — an instance of SqlDriver which can perform the
     * actual save of the model (via ::execute()). Thereafter, query
     * ::insert_id() for an auto id value and ::affected_rows() for the
     * count of affected rows by the update (should be 1).
     */
    function saveModel(Model\ModelBase $model) {
        $compiler = $this->getCompiler();
        if ($model->__new__)
            $stmt = $compiler->compileInsert($model);
        else
            $stmt = $compiler->compileUpdate($model);

        $ex = $this->getDriver($stmt);
        $ex->execute();
        if ($ex->affected_rows() != 1)
            return false;

        return $ex;
    }
}
