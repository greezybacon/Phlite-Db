<?php
namespace Phlite\Db\Model;

use Phlite\Db\Router;

class ModelManager {
    protected $model;
    protected $backend;

    function __construct($model) {
        $this->model = $model;
    }

    // TODO: Provide a way to capture a database Backend instance and pass it
    // along with the ::getQueryset() method.
    function withBackend(Backend $bk) {
        $c = clone $this;
        $c->backend = $bk;
        return $c;
    }

    function getQueryset() {
        $qs = new QuerySet($this->model);
        if (isset($this->backend))
            $qs = $qs->withBackend($this->backend);
        return $qs;
    }

    function __call($func, $args) {
        return $this->getQueryset()->$func(...$args);
    }

    protected function getCompiler(ModelBase $model) {
        $backend = $this->backend ?: Router::getBackend($model);
        return $backend->getCompiler();
    }

    /**
     * lookup
     *
     * Retrieve a record by its primary key. This method may be short
     * circuited by model caching if the record has already been loaded by
     * the database. In such a case, the database will not be consulted for
     * the model's data.
     *
     * This method can be called with an array of keyword arguments matching
     * the PK of the object or the values of the primary key. Both of these
     * usages are correct:
     *
     * >>> User::lookup(1)
     * >>> User::lookup(array('id'=>1))
     *
     * For composite primary keys and the first usage, pass the values in
     * the order they are given in the Model's 'pk' declaration in its meta
     * data. For example:
     *
     * >>> UserPrivilege::lookup(1, 2)
     *
     * Parameters:
     * $criteria - (mixed) primary key for the sought model either as
     *      arguments or key/value array as the function's first argument
     *
     * Returns:
     * (Object<ModelBase>|null) a single instance of the sought model or
     * null if no such instance exists.
     *
     * Throws:
     * Db\Exception\NotUnique if the criteria does not hit a single object
     */
    function lookup($criteria) {
        // Model::lookup(1), where >1< is the pk value
        if (!is_array($criteria)) {
            $args = func_get_args();
            $criteria = array();
            $pk = $this->model::getMeta('pk');
            foreach ($args as $i=>$f)
                $criteria[$pk[$i]] = $f;

            // Only consult cache for PK lookup, which is assumed if the
            // values are passed as args rather than an array
            if ($cached = ModelInstanceManager::checkCache($this->model,
                    $criteria))
                return $cached;
        }

        return $this->filter($criteria)->one();
    }

    /**
     * delete
     *
     * Delete a model object from the underlying database. This method will
     * also clear the model cache of the specified model so future lookups
     * would mean database lookups or NULL.
     *
     * Returns:
     * <SqlDriver> — an instance of SqlDriver which has already performed
     * the write operation. Or FALSE if the operation did not succeed.
     */
    function deleteModel(ModelBase $model) {
        ModelInstanceManager::uncache($model);
        $backend = $this->backend ?: Router::getBackend($model, Router::WRITE);
        return $backend->deleteModel($model);
    }

    /**
     * save
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
     function saveModel(ModelBase $model) {
        $backend = $this->backend ?: Router::getBackend($model, Router::WRITE);
        return $backend->saveModel($model);
    }
}