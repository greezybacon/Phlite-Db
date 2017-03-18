<?php

namespace Phlite\Db\Fields;

use Phlite\Db\Backend;
use Phlite\Text;

class JSONField
extends TextField {
    function to_php($value, Backend $backend) {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    function to_database($value, Backend $backend) {
        return json_encode($value);
    }

    function getJoinConstraint($field_name, $table, Backend $backend) {
        list($field, $path) = explode(':', $field_name, 2);
        return sprintf("json_extract(%s.%s, '$.%s')", $table,
            $backend->quote($field), $path);
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
 *         $rv = parent::build();
 *         Ticket::getMeta()->addJoin([
 *             'user' => array(
 *                 'reverse' => 'User.tickets',
 *             ),
 *         ]);
 *         return $rv;
 *     }
 * }
 */
