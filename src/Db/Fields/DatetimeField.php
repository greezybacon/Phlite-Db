<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Compile\Transform;
use Phlite\Db\Fields\IntegerField;

class DateTimeField
extends TextField {
    static $defaults = array(
        'on_update_now' => false,
        'auto_now' => 10,
        'timezone' => false,
    );
}

class YearTransform
extends Transform {
    static $name = 'year';
    static $template = 'EXTRACT(YEAR FROM %s)';

    function toSql($compiler, $model, $rhs) {
        // TODO: Determine actual database platform
        $lhs = $this->buildLhs($compiler, $model);
        return "CAST(STRFTIME('%Y', {$lhs}) AS INT)";
    }

    function evaluate($rhs, $lhs) {
        if (is_string($lhs))
            $lhs = strtotime($lhs);
        return (int) strftime('%Y', $lhs);
    }

    function getOutputFieldType() {
        return IntegerField::class;
    }
}

DateTimeField::registerTransform(YearTransform::class);