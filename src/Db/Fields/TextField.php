<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Db\Compile\Transform;
use Phlite\Text;

class TextField
extends BaseField {
    static $defaults = array(
        'case' => false,
        'charset' => false,
        'collation' => false,
    );

    function __construct(array $options=array()) {
        parent::__construct($options);
        if (false && !isset($this->length))
            throw new \InvalidArgumentException('`length` is required for text fields');
    }

    function to_php($value, Backend $backend) {
        if ($this->charset && $this->charset != $backend->charset) {
            return new Text\Unicode($value, $backend->charset);
        }
        return $value;
    }

    function to_database($value, Backend $backend) {
        if ($value instanceof Text\Unicode) {
            return $value->get($backend->charset);
        }
        return $value;
    }
}

abstract class LikeTransform
extends Transform {
    static $template = '%s LIKE %s';

    // Thanks, http://stackoverflow.com/a/3683868
    function like_escape($what, $e='\\') {
        return str_replace(array($e, '%', '_'), array($e.$e, $e.'%', $e.'_'), $what);
    }
}

class ContainsTransform
extends LikeTransform {
    static $name = 'contains';
    
    function toSql($compiler, $model, $rhs) {
        $rhs = $this->like_escape($rhs);
        return parent::toSql($compiler, $model, "%{$rhs}%");
    }    

    function evaluate($rhs, $lhs=null) { return stripos($lhs, $rhs) !== false; }
}

class StartswithTransform
extends LikeTransform {
    static $name = 'startswith';

    function toSql($compiler, $model, $rhs) {
        $rhs = $this->like_escape($rhs);
        return parent::toSql($compiler, $model, "{$rhs}%");
    }

    function evaluate($rhs, $lhs=null) { return stripos($lhs, $rhs) !== false; }
}

class EndswithTransform
extends LikeTransform {
    static $name = 'endswith';

    function toSql($compiler, $model, $rhs) {
        $rhs = $this->like_escape($rhs);
        return parent::toSql($compiler, $model, "%{$rhs}");
    }

    function evaluate($rhs, $lhs=null) { return $rhs === '' || strcasecmp(substr($lhs, -strlen($rhs))) === 0; }
}

class RegexTransform
extends Transform {
    static $name = 'regex';
    static $template = '%s REGEXP %s';

    function toSql($compiler, $model, $rhs) {
        // Strip slashes and options
        if ($rhs[0] == '/')
            $rhs = preg_replace('`/[^/]*$`', '', substr($rhs, 1));
        return parent::toSql($compiler, $model, $rhs);
    }

    function evaluate($rhs, $lhs=null) { return preg_match("/$lhs/iu", $rhs); }
}

TextField::registerTransform(ContainsTransform::class);
TextField::registerTransform(StartswithTransform::class);
TextField::registerTransform(EndswithTransform::class);
TextField::registerTransform(RegexTransform::class);