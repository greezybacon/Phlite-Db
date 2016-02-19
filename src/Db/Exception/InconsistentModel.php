<?php

namespace Phlite\Db\Exception;

// Database fields/tables do not match codebase
class InconsistentModel extends DbError {
    function __construct() {
        // TODO: Drop the model cache (just incase)
        call_user_func_array(array('parent', '__construct'), func_get_args());
    }
}
