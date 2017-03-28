<?php
namespace Test\EdgeRelationTest;

use Phlite\Db;
use Phlite\Db\Fields;
use Phlite\Db\Migrations\Migration;

abstract class TestModelBase
extends Db\Model\ModelBase {
    use Db\Model\Ext\ActiveRecord;

    static $meta = [
        'label' => 'edge',
        'abstract' => true,
    ];
}

class Article
extends TestModelBase {
    static $meta = [
        'table' => 'article',
        'pk' => ['id'],
        'edges' => [
            'publications' => [
                'target' => Publication::class,
                'through' => PublishedArticle::class,
            ]
        ]
    ];
}

class PublishedArticle
extends TestModelBase {
    static $meta = [
        'table' => 'published_article',
        'pk' => ['pub_id', 'article_id'],
        'joins' => [
            'article' => [
                'constraint' => ['article_id' => 'Article.id'],
            ],
            'publication' => [
                'constraint' => ['pub_id' => 'Publication.id'],
            ]
        ]
    ];
}

class Publication
extends TestModelBase {
    static $meta = [
        'table' => 'publication',
        'pk' => ['id'],
        'edges' => [
            'articles' => [
                'target' => Article::class,
                'through' => PublishedArticle::class,
            ]
        ]
    ];
}

class CreateModels
extends Db\Migrations\Migration {
    function getOperations() {
        return [
            new Db\Migrations\CreateModel(Article::class, [
                'id'        => new Fields\AutoIdField(['pk' => true]),
                'headline'  => new Fields\TextField(['length' => 64]),
            ]),
            new Db\Migrations\CreateModel(Publication::class, [
                'id'        => new Fields\AutoIdField(['pk' => true]),
                'title'     => new Fields\TextField(['length' => 64]),
            ]),
            new Db\Migrations\CreateModel(PublishedArticle::class, [
                'pub_id'            => new Fields\IntegerField(),
                'article_id'        => new Fields\IntegerField(),
                'date_published'    => new Fields\DatetimeField(),
            ]),
        ];
    }
}

class EdgeRelationshipTest
extends \PHPUnit_Framework_TestCase {
    static function setUpBeforeClass() {
        Db\Manager::addConnection([
            'BACKEND' => 'Phlite\Db\Backends\SQLite',
            'FILE' => ':memory:',
        ], 'default');
        Db\Manager::migrate(new CreateModels());
    }

    static function tearDownAfterClass() {
        Db\Manager::migrate(new CreateModels(), Migration::BACKWARDS);
        Db\Manager::removeConnection('default');
    }
    
    function testCreateSomeData() {
        $p1 = new Publication(['title'=>'The PHP Journal']);
        $this->assertTrue($p1->save());
        $p2 = new Publication(['title'=>'Science News']);
        $this->assertTrue($p2->save());
        $p3 = new Publication(['title'=>'Science Weekly']);
        $this->assertTrue($p3->save());
        
        $a1 = new Article(['headline'=>'Phlite lets you build databases easily']);
        $this->assertTrue($a1->save());
    }
    
    function testPopulateEdge() {
        $p1 = Publication::objects()->first();
        $this->assertTrue($p1 !== null);
        
        $a1 = Article::objects()->first();
        $this->assertTrue($a1 !== null);
        
        $edge = $p1->articles->add($a1);
        $this->assertTrue($edge->save());
        
        // Add a published date
        $now = new \DateTime();
        $edge->date_published = $now;
        $this->assertTrue($edge->save());
        
        // Verify the other end of the chain
        $this->assertEquals($a1->publications->count(), 1);
        $this->assertEquals($a1->publications[0]->title, 'The PHP Journal');
        $this->assertEquals($a1->publications[0]->date_published, $now);
    }
}