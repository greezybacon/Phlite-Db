<?php
namespace Phlite\Db\Util;

interface IContextManager {
    function __enter();

    // If the finally-suite was reached through normal completion of BLOCK or
    // through a non-local goto (a break, continue or return statement in
    // BLOCK), mgr.__exit() is called with three None arguments.  If
    // the finally-suite was reached through an exception raised in
    // BLOCK, mgr.__exit() is called with three arguments representing
    // the exception type, value, and traceback.
    //
    // IMPORTANT: if mgr.__exit() returns a "true" value, the exception
    // is "swallowed".  That is, if it returns "true", execution
    // continues at the next statement after the with-statement, even if
    // an exception happened inside the with-statement.  However, if the
    // with-statement was left via a non-local goto (break, continue or
    // return), this non-local return is resumed when mgr.__exit()
    // returns regardless of the return value.  The motivation for this
    // detail is to make it possible for mgr.__exit() to swallow
    // exceptions, without making it too easy (since the default return
    // value, None, is false and this causes the exception to be
    // re-raised).  The main use case for swallowing exceptions is to
    // make it possible to write the @contextmanager decorator so
    // that a try/except block in a decorated generator behaves exactly
    // as if the body of the generator were expanded in-line at the place
    // of the with-statement.
    function __exit(/* \Error */ $e);
}