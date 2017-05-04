<?php

namespace Phlite\Db;

abstract class Router {
    const READ    = 1;
    const WRITE   = 2;
    const MIGRATE = 3;

    /**
     * Return a Connection instance for the model in question. This is used
     * to connect models to various database connections and backends based
     * on criteria defined in Router instances. Use Manager::addRouter() to
     * register a new router.
     *
     * If a router does not handle a particular model or a particular reason,
     * it need not return a value.
     *
     * Parameters:
     * $model - The model needing a database connection
     * $reason - (READ|WRITE|MIGRATE) the reason for the connection. This
     *    allows routers to distribute connections based on the request to
     *    be sent.
     *
     * Returns:
     * (string) name of the database connection information registered in
     * the project settings file.
     */
    abstract function getConnectionForModel(ModelBase $model, $reason);
}
