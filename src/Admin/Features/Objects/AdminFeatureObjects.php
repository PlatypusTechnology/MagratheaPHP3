<?php

namespace Magrathea\Admin\Features\Objects;

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\AdminFeature;
use Magrathea\Admin\AdminManager;
use Magrathea\Admin\iAdminFeature;
use Magrathea\Admin\ObjectsExplorer;
use Magrathea\Exceptions\MagratheaDBException;
use Magrathea\Exceptions\MagratheaModelException;

#######################################################################################
####
####    MAGRATHEA PHP Admin Objects
####    Magrathea by Paulo Henrique Martins
####    Platypus technology
####    Objects created: 2026-11 by Paulo Martins
####
#######################################################################################

/**
* The admin's Objects area: every model of the project (`ObjectsExplorer::Models()`), with its structure, its rows,
* and a form to create, edit and delete its objects. On by default (`Admin::SetFeatures()`);
* `Admin::RemoveFeature("AdminFeatureObjects")` takes it out.
*/
class AdminFeatureObjects extends AdminFeature implements iAdminFeature {

	public string $featureName = "Objects";
	public string $featureId = "AdminFeatureObjects";

	private ?ObjectsExplorer $manager = null;

	public function __construct() {
		parent::__construct();
		$this->SetClassPath(__DIR__);
		$this->AddJs(__DIR__."/scripts.js");
	}

	/**
	* Sets the manager (tests use fakes)
	* @param 	ObjectsExplorer 	$manager
	* @return 	AdminFeatureObjects 	itself
	*/
	public function SetManager(ObjectsExplorer $manager): AdminFeatureObjects {
		$this->manager = $manager;
		return $this;
	}
	protected function GetManager(): ObjectsExplorer {
		return $this->manager ??= new ObjectsExplorer();
	}

	/**
	* The models' list, or one model's page (`?object=<class>`)
	*/
	public function Index() {
		$manager = $this->GetManager();
		$objectClass = @$_GET["object"];
		if(is_string($objectClass) && $objectClass !== "") include(__DIR__."/model.php");
		else include(__DIR__."/index.php");
	}

	/**
	* A page of a model's rows (GET `object`, `page`)
	*/
	public function List() {
		$manager = $this->GetManager();
		$objectClass = @$_GET["object"];
		$page = @$_GET["page"];
		$page = (is_string($page) && ctype_digit($page)) ? (int)$page : 0;
		try {
			$data = $manager->Page($objectClass, $page);
		} catch(\Exception $ex) {
			AdminElements::Instance()->ErrorCard(htmlspecialchars($ex->getMessage()), "Couldn't read the objects");
			return;
		}
		include(__DIR__."/list.php");
	}

	/**
	* The form for an object (GET `object`, `id`; no id: a new object)
	*/
	public function Form() {
		$manager = $this->GetManager();
		$objectClass = @$_GET["object"];
		$id = @$_GET["id"];
		try {
			$model = $manager->Model($objectClass);
			$object = $manager->Load($objectClass, $id);
		} catch(\Exception $ex) {
			AdminElements::Instance()->ErrorCard(htmlspecialchars($ex->getMessage()), "Couldn't open the object");
			return;
		}
		$isNew = ($id === null || $id === "");
		include(__DIR__."/form.php");
	}

	/**
	* Saves an object (POST `magrathea_object`, `magrathea_object_id` and the fields): JSON
	*/
	public function Save() {
		$this->RefuseIfNotPost();
		$class = @$_POST["magrathea_object"];
		try {
			$saved = $this->GetManager()->Save($class, @$_POST["magrathea_object_id"], $_POST);
		} catch(MagratheaModelException | MagratheaDBException $ex) {
			$this->Json(["success" => false, "error" => htmlspecialchars($ex->getMessage())]);
			return;
		}
		$this->LogAction("Objects ".$saved["type"]." ".$saved["object"]->ModelName(), $saved["object"]);
		$this->Json(["success" => true, "type" => $saved["type"], "id" => $saved["id"]]);
	}

	/**
	* Deletes an object (POST `magrathea_object`, `magrathea_object_id`): JSON
	*/
	public function Delete() {
		$this->RefuseIfNotPost();
		$class = @$_POST["magrathea_object"];
		$id = @$_POST["magrathea_object_id"];
		try {
			$object = $this->GetManager()->Load($class, $id);
			$success = $this->GetManager()->Delete($class, $id);
		} catch(MagratheaModelException | MagratheaDBException $ex) {
			$this->Json(["success" => false, "error" => htmlspecialchars($ex->getMessage())]);
			return;
		}
		if($success) $this->LogAction("Objects delete ".$object->ModelName(), $object);
		$this->Json(["success" => $success, "type" => "delete"]);
	}

	/**
	* Answers 405 (JSON error) and stops, unless it's a POST: admin CSRF tokens are checked on POST only
	*/
	protected function RefuseIfNotPost(): void {
		if(@$_SERVER["REQUEST_METHOD"] === "POST") return;
		http_response_code(405);
		$this->Json(["success" => false, "error" => "this action requires a POST request"]);
		die;
	}

	protected function Json(array $data): void {
		echo json_encode($data);
	}

	/**
	* Logs a write in the admin's user logs (a failed log doesn't undo the write)
	*/
	protected function LogAction(string $action, $object): void {
		try {
			AdminManager::Instance()->Log($action, $object);
		} catch(\Throwable $ex) {}
	}

}
