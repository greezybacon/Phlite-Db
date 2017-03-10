<?php
use Phlite\Db;

require_once 'Models.php';

class CreateModels
extends Db\Migrations\Migration {
    function getOperations() {
        return [
            new Db\Migrations\CreateModel('EmailAddress', [
                'id'        => new Db\Fields\AutoIdField(['pk' => true]),
                'flags'     => new Db\Fields\IntegerField(['unsigned' => true]),
                'address'   => new Db\Fields\TextField(['length' => 64]),
            ]),
            new Db\Migrations\CreateModel('User', [
                'id'        => new Db\Fields\AutoIdField(['pk' => true]),
                'email_id'  => new Db\Fields\ForeignKey('EmailAddress.id'),
                'name'      => new Db\Fields\TextField(['length' => 64]),
                'username'  => new Db\Fields\TextField(['length' => 32]),
            ]),
        ];
    }
}

