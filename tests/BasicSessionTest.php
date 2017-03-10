<?php
use Phlite\Db;

require_once 'Migrations.php';

class BasicSessionTest
extends PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
        Db\Manager::migrate(new CreateModels());
    }

    function testCreateSession() {
        $user = new User(['name' => 'John Doe', 'username' => 'doejo']);

        Db\Manager::add($user);
        $this->assertNull($user->id);

        Db\Manager::flush($user);
        $this->assertNotNull($user->id);

        $this->assertTrue(Db\Manager::commit());
    }

    function testSaveRelated() {
        $user = new User(['name' => 'John Doe', 'username' => 'doejo']);
        $email = $user->email = new EmailAddress(['address' => 'nomail@nothanks.tld']);
        $user->save();

        $this->assertNotNull($email->id);
        $this->assertNotNull($user->email_id);
    }

    function testSaveRelated_Session() {
        $user = new User(['name' => 'John Doe', 'username' => 'doejo']);
        $email = $user->email = new EmailAddress(['address' => 'nomail@nothanks.tld']);
        $session = Db\Manager::getTransaction();
        $session->add($user);
        $session->add($email);
        $session->flush();

        $this->assertNotNull($email->id);
        $this->assertNotNull($user->email_id);
    }
}
