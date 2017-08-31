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

    protected static $routers = array();

    /**
     * getBackend
     *
     * Fetch a connection object for a particular model and for a particular
     * reason. The reason code is selected from the constants defined in the
     * Router class.
     *
     * Parameters:
     * $model - (string) model class (fully qualified) for which a database
     *      backend is requested. Class should extend from ModelBase. A
     *      ModelBase instance can also be passed.
     * $reason - (int) type of activity for which a database connection is
     *      requestd. Defaults to Router::READ. Useful if backend routers use
     *      varying backends based on read/write activity.
     *
     * Returns:
     * (Connection) object which can handle queries for this model.
     * Optionally, the $key passed to ::addConnection() can be returned and
     * this Manager will lookup the Connection automatically.
     */
     static function getBackend($model, $reason=Router::READ) {
        if ($model instanceof Model\ModelBase)
            $model = get_class($model);
        foreach (static::$routers as $R) {
            if ($C = $R->getBackendForModel($model, $reason)) {
                if (is_string($C)) {
                    $C = Manager::getConnection($C);
                }
                return $C;
            }
        }
        try {
            return Manager::getConnection('default');
        }
        catch (\Exception $e) {
            throw new \Exception("'default' database not specified");
        }
    }

    static function addRouter(Router $router) {
        static::$routers[] = $router;
    }


}
