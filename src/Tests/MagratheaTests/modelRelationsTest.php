<?php

namespace ModelRelationsTest;

use Magrathea\Exceptions\MagratheaModelException;
use Magrathea\MagratheaModel;

// only "external": the shape the CRUD admin needs for a relation select
class Book extends MagratheaModel {
	protected $dbTable = "books";
	protected $dbPk = "id";
	protected $dbValues = [ "id" => "int", "title" => "string", "author_id" => "int" ];
	protected $relations = [
		"external" => [ "author_id" => "\\App\\Author\\Author" ],
	];
	public $id, $title, $author_id;
}

// half-filled $relations: properties without their lazyload/method entries
class Shelf extends MagratheaModel {
	protected $dbTable = "shelves";
	protected $dbPk = "id";
	protected $dbValues = [ "id" => "int", "name" => "string" ];
	protected $relations = [
		"properties" => [ "Books" => null, "Owner" => null ],
		"lazyload" => [ "Owner" => true ],
	];
	public $id, $name;
}

// complete lazyload relation
class Library extends MagratheaModel {
	protected $dbTable = "libraries";
	protected $dbPk = "id";
	protected $dbValues = [ "id" => "int", "name" => "string" ];
	protected $relations = [
		"properties" => [ "Books" => null ],
		"lazyload" => [ "Books" => true ],
		"methods" => [ "Books" => "LoadBooks" ],
	];
	public $id, $name;
	public $loads = 0;
	public function LoadBooks() {
		$this->loads++;
		return [ "a", "b" ];
	}
}

class ModelRelationsTest extends \PHPUnit\Framework\TestCase {

	private function failOnWarnings(): void {
		set_error_handler(function($errno, $errstr, $errfile, $errline) {
			throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
		});
	}
	protected function tearDown(): void {
		restore_error_handler();
	}

	/**
	 * @covers MagratheaModel::ToArray
	 */
	public function testToArrayWithOnlyExternalHasNoWarning() {
		$this->failOnWarnings();
		$book = new Book();
		$book->id = 3;
		$book->title = "Mort";
		$book->author_id = 7;
		$this->assertSame([ "id" => 3, "title" => "Mort", "author_id" => 7 ], $book->ToArray());
	}

	/**
	 * @covers MagratheaModel::ToJson
	 */
	public function testToJsonWithOnlyExternalHasNoWarning() {
		$this->failOnWarnings();
		$book = new Book();
		$book->id = 3;
		$json = $book->ToJson();
		$this->assertSame(3, $json["id"]);
		$this->assertSame([ "id" => 3, "title" => null, "author_id" => null ], $json["fields"]);
	}

	/**
	 * @covers MagratheaModel::ToArray
	 */
	public function testToArrayIncludesRelationProperties() {
		$this->failOnWarnings();
		$shelf = new Shelf();
		$shelf->id = 1;
		$shelf->Books = [ "x" ];
		$this->assertSame([ "id" => 1, "name" => null, "Books" => [ "x" ], "Owner" => null ], $shelf->ToArray());
	}

	/**
	 * @covers MagratheaModel::Get
	 */
	public function testGetWithoutLazyloadEntryReturnsNullWithNoWarning() {
		$this->failOnWarnings();
		$shelf = new Shelf();
		$this->assertNull($shelf->Books);
	}

	/**
	 * @covers MagratheaModel::Get
	 */
	public function testGetLazyloadWithoutMethodThrowsNamingTheProperty() {
		$this->failOnWarnings();
		$shelf = new Shelf();
		$this->expectException(MagratheaModelException::class);
		$this->expectExceptionMessage("Relation Owner of ModelRelationsTest\\Shelf is lazyload but has no method");
		$shelf->Owner;
	}

	/**
	 * @covers MagratheaModel::Get
	 */
	public function testGetLazyloadCallsTheMethodOnce() {
		$this->failOnWarnings();
		$library = new Library();
		$this->assertSame([ "a", "b" ], $library->Books);
		$this->assertSame([ "a", "b" ], $library->Books);
		$this->assertSame(1, $library->loads);
	}

	/**
	 * @covers MagratheaModel::Set
	 */
	public function testSetRelationPropertyWithoutMethodHasNoWarning() {
		$this->failOnWarnings();
		$library = new Library();
		$library->Books = [ "c" ];
		$this->assertSame([ "c" ], $library->Books);
		$this->assertSame(0, $library->loads);
		$shelf = new Shelf();
		$shelf->Owner = "Rincewind";
		$this->assertSame("Rincewind", $shelf->Owner);
	}
}
