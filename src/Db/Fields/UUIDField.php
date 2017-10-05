<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Db\Util\Uuid;

class UUIDField
extends TextField {
    static $defaults=[
        'length' => 21,
        'auto' => true,
        'version' => 4,
        'bpc' => 4,
    ];
    
    function to_database($value, Backend $bk) {
        if (!$value && $this->options['auto']) {
            $value = Uuid::generate($this->options['version']);
        }
        elseif ($value instanceof Uuid) {
            $value = $value->asBpc($this->options['bpc']);
        }
    }
    
    function to_php($value, Backend $bk) {
        return Uuid::fromString($value, $this->options['bpc']);
    }

    function to_export($value) {
        return (string) $value;
    }
}