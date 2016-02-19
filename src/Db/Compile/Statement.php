<?php

namespace Phlite\Db\Compile;

use Phlite\Logging\Log;

/**
 * Class: Statement
 *
 * Statement represents the data necessary to execute a query. It contains
 * the SQL text, parameters, and output map. The output map correlates the
 * returned fields with the models to which they pertain.
 */
class Statement {

    var $sql;
    var $params;
    var $map;

    function __construct($sql, $params=false, $map=false) {
        $this->sql = $sql;
        $this->params = $params;
        $this->map = $map;
    }

    function getMap() {
        return $this->map;
    }

    function hasParameters() {
        return count($this->params);
    }
    function getParameters() {
        return $this->params;
    }

    function log($context=array()) {
        Log::getLogger('phlite.db')->debug($this, $context);
    }

    function toString($escape_cb=false) {
        if (!$escape_cb)
            $escape_cb = function($i) {
                if ($i instanceof \DateTime)
                    $i = $i->format('Y-m-d H:i:s');
                return "<$i>";
            };

        $params = $this->params;
        return preg_replace_callback("/:(\d+)/",
        function($m) use ($self) {
            $p = $self->params[$m[1]-1];
            return $escape_cb($p);
        }, $this->sql);
    }
    function __toString() {
        return $this->toString();
    }
}
