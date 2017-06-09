<?php
namespace Phlite\Db\Util;

/**
 * Python-esce context manager which allows for handling a teardown of an
 * object regardless of the outcome of how it is used. For instance, if an
 * exception is thrown while a database transaction is in progress, then
 * the transaction should be rolled back before execution continues so that
 * the state of the database is consistent and predictable.
 */
class ContextManager {
    var $manager;

    static function with(IContextManager $what) {
        return new static($what);
    }

    function __construct(IContextManager $what) {
        $this->manager = $what;
    }

    function do(callable $something) {
        $exc = false;
        $manager = $this->manager;
        try {
            $value = $manager->__enter();
            $exc = true;
            try {
                // This is a difference from Python. Since the usage of the
                // context manager is and expression, the return value should
                // be captured.
                // TODO: Allow $value to be unpacked as args
                return $something($value);
            }
            catch (\Error $e) {
                $exc = false;
                if (!$manager->__exit($e)) {
                    throw $e;
                }
                // The exception is swallowed if exit() returns true
            }
        }
        finally {
            // The normal and non-local-goto cases are handled here
            if ($exc)
                $manager->__exit(null);
        }
    }
}