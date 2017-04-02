<?php
use Phlite\Db;

class User
extends Db\Model\ModelBase {
    static $meta = [
        'table' => 'user',
        'pk' => ['id'],
        'joins' => [
            'email' => [
                'constraint' => ['email_id' => 'EmailAddress.id'],
            ],
        ],
    ];
}

class EmailAddress
extends Db\Model\ModelBase {
    static $meta = [
        'table' => 'user_email',
        'pk' => ['id'],
    ];
}
