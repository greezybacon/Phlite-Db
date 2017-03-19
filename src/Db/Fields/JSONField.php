<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Db\Compile\SqlCompiler;
use Phlite\Text;

class JSONField
extends TextField {
    function to_php($value, Backend $backend) {
        return is_string($value) ? json_decode($value) : $value;
    }

    function to_database($value, Backend $backend) {
        return json_encode($value);
    }

    function getJoinConstraint($field_name, $table, SqlCompiler $compiler) {
        list($field, $path) = explode(':', $field_name, 2);
        return sprintf("json_extract(%s.%s, '$.%s')", $table,
            $compiler->quote($field), $path);
    }

    /**
     * Fetch a value from the local properties array (__ht__). Usually it is
     * a simple array lookup.
     */
    function extractValue($name, $props) {
        list($name, $path) = explode(':', $name, 2);
        return @$props[$name]->{$path} ?: null;
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
