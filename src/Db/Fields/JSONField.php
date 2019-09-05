<?php
namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Db\Compile\Transform;
use Phlite\Db\Exception;

class JSONField
extends TextField {
    static $defaults = [
        # Normally, JSON searching and content should be considered case
        # insensitive
        'case' => true,
        # Return stdClass objects instead of arrays. This is more natural
        # and is also the default for JSON handling in PHP; however, all
        # current PHP versions return an error if you try
        # >>> $model->json->key = 'value'
        # It is possible, however, with parentheses:
        # >>> ($model->json)->key = 'value';
        'object' => false,
    ];

    function to_php($value, Backend $backend) {
        if (is_string($value))
            return json_decode($value, !$this->options['object']);
        if (is_null($value))
            return new \stdClass;
        return $value;
    }

    function to_database($value, Backend $backend) {
        return json_encode($value);
    }

    function getTransform($name, $lhs) {
        // TODO: Consider generic transforms like `__lt`?
        return new JSONFieldTransform($lhs, $name);
    }
}

class JSONFieldTransform
extends Transform {
    protected $jpath;

    function __construct($lhs, $jpath=null) {
        $this->jpath = $jpath;
        parent::__construct($lhs);
    }

    function toSql($compiler, $model, $rhs) {
        $lhs = $this->buildLhs($compiler, $model);
        // TODO: Support JPATH?
        return sprintf("JSON_EXTRACT({$lhs}, '$.{$this->jpath}')");
    }

    function evaluate($rhs, $lhs) {
        // TODO: Support JPATH here
        return $lhs->{$rhs};
    }

    function getOutputFieldType() {
        return TextField::class;
    }
}

/*
 * class User
 * extends ModelBase {
 *     static $metaclass = UserMeta::class;
 *     static $meta = array(
 *         'join' => array(
 *             'tickets' => array(
 *                 'constraint' => ['id' => 'Ticket.edges:user'],
 *                 'list' => true,
 *             ),
 *         ),
 *     );
 * }
 * 
 * class UserMeta
 * extends ModelMeta {
 *     function build() {
 *         Ticket::getMeta()->addJoin('user', [
 *             'user' => array(
 *                 'reverse' => 'User.tickets',
 *             ),
 *         ]);
 *         return parent::build();
 *     }
 * }
 */
