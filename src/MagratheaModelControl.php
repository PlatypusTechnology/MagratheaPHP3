<?php

namespace Magrathea;
use Magrathea\DB\Database;
use Magrathea\DB\Query;
use Magrathea\Exceptions\MagratheaModelException;

#######################################################################################
####
####	MAGRATHEA PHP
####	v. 2.0
####
####	Magrathea by Paulo Henrique Martins
####	Platypus technology
####
#######################################################################################
####
####	Model Control Class
####	Class and interface for model control
####	
####	created: 2012-12 by Paulo Martins
####	updated: 2023-02 by Paulo Martins
####
#######################################################################################

abstract class MagratheaModelControl{
	/** model's class name (`Article::class`); when set, `$modelNamespace` and `$modelName` are not needed */
	protected static $model;
	protected static $modelNamespace;
	protected static $modelName;
	/** model's table; when not set, it's read from the model */
	protected static $dbTable;
	private static $dbTableCache = [];

	/**
	 * Model's class name: `$model` when it's set, else `$modelNamespace\$modelName`
	 * @return 	string 	model's class name
	 */
	public static function GetModelName() {
		if(!empty(static::$model)) return static::$model;
		return static::$modelNamespace."\\".static::$modelName;
	}

	/**
	 * Model's table: `$dbTable` when it's set, else the table of a new (empty) model, cached per Control
	 * @return 	string 	table name
	 */
	public static function GetDbTable() {
		if(!empty(static::$dbTable)) return static::$dbTable;
		if(!array_key_exists(static::class, self::$dbTableCache)) {
			$modelName = static::GetModelName();
			self::$dbTableCache[static::class] = (new $modelName())->GetDbTable();
		}
		return self::$dbTableCache[static::class];
	}

	private static function ConvertToObject($item) {
		$modelName = static::GetModelName();
		$splitResult = Query::SplitArrayResult($item);
		$new_object = new $modelName();
		if(count($splitResult) > 0)
			$item = $splitResult[$new_object->GetDbTable()];
		$new_object->LoadObjectFromTableRow($item);
		return $new_object;
	}

	/**
	 * Run a query and return a list of the objects
	 * @param 	string 	$sql 	query string
	 * @return  array<object> 	List of objects
	 */
	public static function RunQuery(string $sql){
		$magdb = Database::Instance();
		$result = $magdb->queryAll($sql);
		$objects = array();
		foreach($result as $item){
			$obj = static::ConvertToObject($item);
			array_push($objects, $obj);
		}
		return $objects;
	}
	/**
	 * Run a query and return the first object available
	 * @param 	string 	$sql 	query string
	 * @return  object 	First object found
	 */
	public static function RunRow(string $sql){
		$magdb = Database::Instance();
		$row = $magdb->QueryRow($sql);
		$obj = null;
		if(!empty($row)){
			$obj = static::ConvertToObject($row);
		}
		return $obj;
	}
	/**
	 * Runs a query and returns the result
	 * @param 	string 	$sql 	query string
	 * @return  array		database result
	 */
	public static function QueryResult(string $sql){
		return Database::Instance()->QueryAll($sql);
	}
	/**
	 * Runs a query and returns the first row of result
	 * @param 	string 	$sql 	query string
	 * @return  array		database result (first line)
	 */
	public static function QueryRow(string $sql){
		return Database::Instance()->QueryRow($sql);
	}
	/**
	 * Runs a query and returns the first result
	 * @param 	string 	$sql 	query string
	 * @return  object		database result (first item)
	 */
	public static function QueryOne(string $sql){
		return Database::Instance()->QueryOne($sql);
	}

	/**
	 * Runs a Magrathea Query and returns a list of objects
	 * (calls Run function)
	 * @param 	Query  	$magQuery  		MagratheaQuery query
	 * @return  array<object> 		List of objects
	 */
	public static function RunMagQuery(Query $magQuery){ 
		return self::Run($magQuery); 
	}
	/**
	 * Runs a Magrathea Query and returns the count of matching rows
	 * @param   Query   $magQuery   MagratheaQuery query
	 * @return  int     Number of matching rows
	 */
	public static function Count(Query $magQuery): int {
		$q = self::QueryOne($magQuery->CountSQL());
		return intval($q);
	}

	/**
	 * Runs query with Pagination.
	 * 	This way, is not necessary to worry about including pagination on Magrathea Query, this function can deal with it
	 * @param 	Query  	$magQuery 		MagratheaQuery query
	 * @param 	int  				&$total   		total of rows (it will be stored in this variable; it's a pointer!)
	 * @param 	integer 			$page     		page to get (0 = first)
	 * @param 	integer 			$limit    		quantity per page (20 = default)
	 * @param 	boolean 			$withTotal 		if false, skips the COUNT(*) query and leaves $total as null
	 * @return  array<object> 		List of objects
	 */
	public static function RunPagination(Query $magQuery, &$total, $page=0, $limit=20, $withTotal=true){
		$total = $withTotal ? self::QueryOne($magQuery->CountSQL()) : null;
		$magQuery->Limit($limit)->Page($page);
		return self::Run($magQuery);
	}

	/**
	 * Runs query with Pagination and returns a MagratheaPagination object, ready to be
	 * returned directly from an API controller (MagratheaApi::ReturnSuccess() recognizes it).
	 * 	By default ($withTotal=false), avoids the extra COUNT(*) query: it fetches one row
	 * 	more than requested to determine `has_more`, then trims the result back to $limit.
	 * @param 	Query  	$magQuery 		MagratheaQuery query
	 * @param 	integer 			$page     		page to get (0 = first)
	 * @param 	integer 			$limit    		quantity per page (20 = default)
	 * @param 	boolean 			$withTotal 		if true, runs a COUNT(*) query and fills MagratheaPagination::$total
	 * @return  MagratheaPagination
	 */
	public static function GetPagination(Query $magQuery, $page=0, $limit=20, $withTotal=false) {
		$total = null;
		$fetchLimit = $withTotal ? $limit : $limit + 1;
		$rows = self::RunPagination($magQuery, $total, $page, $fetchLimit, $withTotal);

		if($withTotal) {
			$hasMore = (($page + 1) * $limit) < $total;
		} else {
			$hasMore = count($rows) > $limit;
			if($hasMore) $rows = array_slice($rows, 0, $limit);
		}

		return new MagratheaPagination($rows, $page, count($rows), $withTotal ? $total : null, $hasMore);
	}
	/**
	 * Runs a Magrathea Query and returns a list of objects
	 * @param 	Query  	$magQuery  		MagratheaQuery query
	 * @param 	boolean 			$onlyFirst 		returns all of it or only first row?
	 * @return  array<object> 		List of objects
	 */
	public static function Run(Query $magQuery, $onlyFirst=false){
		$array_joins = $magQuery->GetJoins();
		$arrayObjs = array();

		if(count($array_joins) > 0){
			$objects = [];
			$result = static::QueryResult($magQuery->SQL());
			foreach ($result as $r) {
				$splitResult = Query::SplitArrayResult($r);
				$modelName = static::GetModelName();
				$new_object = new $modelName();
				if(count($splitResult) > 1)
					$r = $splitResult[$new_object->GetDbTable()];
				$new_object->LoadObjectFromTableRow($r);
				foreach($array_joins as $join){
					$obj = $join["obj"];
					if(empty($obj)) continue;
					$obj->LoadObjectFromTableRow($splitResult[$obj->GetDbTable()]);
					if(empty($obj->GetPK())) continue;
					$objname = $obj->ModelName();
					if($join["type"] == "has_many"){
						$objnameField = $objname."s";
						if( empty($arrayObjs[$new_object->GetID()]) )
							$arrayObjs[$new_object->GetID()] = array();
						if( empty($arrayObjs[$new_object->GetID()][$objnameField]) )
							$arrayObjs[$new_object->GetID()][$objnameField] = array();
						$objIndex = count($arrayObjs[$new_object->GetID()][$objnameField]);
						$arrayObjs[$new_object->GetID()][$objnameField][$objIndex] = clone $obj;
						$new_object->$objnameField = $arrayObjs[$new_object->GetID()][$objnameField];
					} else {
						$new_object->$objname = clone $obj;
					}
					unset($obj);
				}
				$objects[$new_object->GetPK()] = clone $new_object;
			}
			if($onlyFirst){
				if(count($objects) > 0) return $objects[0];
			} else return array_values($objects);
		} else {
			return $onlyFirst ? self::RunRow($magQuery->SQL()) : self::RunQuery($magQuery->SQL());
		}
	}

	/**
	 * Gets all from this object
	 * @return  array<object> 	List of objects
	 */
	public static function GetAll() {
		$sql = "SELECT * FROM ".static::GetDbTable()." ORDER BY created_at ASC";
		return static::RunQuery($sql);
	}

	/**
	 * Gets all from this object
	 * @return  array<object> 	List of objects
	 */
	public static function GetListPage(int $limit=20, int $page=0) {
		$offset = $page * $limit;
		$sql = "SELECT * FROM ".static::GetDbTable()." ORDER BY created_at DESC LIMIT ".$offset.",".$limit;
		return static::RunQuery($sql);
	}

	/**
	 * Gets all from this object
	 * @return  array<object> 	List of objects
	 */
	public static function GetSelectArray() {
		$relational = self::GetAll();
		$selects = [];
		foreach($relational as $s) {
			$selects[$s->GetID()] = $s->Ref();
		}
		return $selects;
	}

	/**
	 * Builds query with where clause
	 * @param 	string 					$whereSql 		where clause
	 * @return  array<object> 	List of objects
	 */
	public static function GetSimpleWhere(string $whereSql){
		$sql = "SELECT * FROM ".static::GetDbTable()." WHERE ".$whereSql;
		return static::RunQuery($sql);
	}

	/**
	 * Builds query with where clause
	 * @param 	string|array 	$arr 		where clause
	 * @param 	string 				$condition 	"AND" or "OR" for multiple clauses
	 * @return  array<object> 		List of objects
	 */
	public static function GetWhere(string|array $arr, $condition = "AND"){
		if(!is_array($arr)){
			return static::GetSimpleWhere($arr);
		}
		$whereSql = Query::BuildWhere($arr, $condition);
		$sql = "SELECT * FROM ".static::GetDbTable()." WHERE ".$whereSql." ORDER BY created_at DESC";
		return static::RunQuery($sql);
	}

	/**
	 * Builds query with where clause, returning only first row
	 * @param 	string|array 			$arr 		where clause
	 * @param 	string 						$condition 	"AND" or "OR" for multiple clauses
	 * @return  object|null 			First object available (null when none)
	 */
	public static function GetRowWhere(string|array $arr, $condition = "AND"){
		$whereSql = is_array($arr) ? Query::BuildWhere($arr, $condition) : $arr;
		$sql = "SELECT * FROM ".static::GetDbTable()." WHERE ".$whereSql;
		return static::RunRow($sql);
	}

	/**
	 * This function allows to build a query getting multiple objects at once
	 * @param 	array<object> 	$array_objects Array of objects
	 * @param 	string 					$joinGlue      join string to be used on query
	 * @param 	string 					$where         Where clause
	 * @return  array<object> 	List of objects
	 */
	public static function GetMultipleObjects($array_objects, $joinGlue, $where=""){
		$magQuery = new Query();
		$magQuery->Table(static::GetDbTable())->SelectArrObj($array_objects)->Join($joinGlue)->Where($where);

		// db:
		$objects = array();
		$result = Database::Instance()->queryAll($magQuery->SQL());

		foreach($result as $item){
			// we have the result... but we have to separate it in the objects... shit, how can I do that?
			$splitResult = Query::SplitArrayResult($item);
			$itemArr = array();
			foreach ($array_objects as $key => $value) {
				$new_object = new $value();
				$new_object->LoadObjectFromTableRow($splitResult[$new_object->GetDbTable()]);
				$itemArr[$key] = $new_object;
			}
			array_push($objects, $itemArr);
		}
		return $objects;
	}

	public function __toString(): string {
		$rs = "";
		$rs .= "[MAGRATHEA MODEL CLASS == ".get_class($this)."]\n";
		$rs .= "Model: ".static::GetModelName()." / Table: ".static::GetDbTable();
		return $rs;
	}

}
