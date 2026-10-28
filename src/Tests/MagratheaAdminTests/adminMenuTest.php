<?php

use Magrathea\Admin\AdminMenu;
use Magrathea\Admin\Start;
use Magrathea\Tests\TestsHelper;

// Admin Start without session/CSRF setup
class MenuTestAdminStart extends Start {
	protected function Initialize() { return $this; }
}

class adminMenuTest extends \PHPUnit\Framework\TestCase {

	private function PageFromLink(string $link): ?string {
		parse_str(ltrim(parse_url($link, PHP_URL_QUERY) ?? "", "?"), $params);
		return $params["magrathea_page"] ?? null;
	}

	function testEveryMenuPageExists() {
		TestsHelper::Print("testing every admin menu item points to an existing page...");
		$menu = new AdminMenu();
		$items = (fn() => $this->menuItems)->call($menu);
		$this->assertNotEmpty($items);
		$start = MenuTestAdminStart::Instance();
		foreach($items as $key => $item) {
			if(!empty($item["link"])) {
				$page = $this->PageFromLink($item["link"]);
				$this->assertNotNull($page, $key);
				$this->assertNotNull($start->GetViewFile("pages", $page), $key." -> ".$page);
			} else {
				$this->assertNotEmpty($item["feature"] ?? null, $key);
			}
		}
	}

	function testFeatureItems() {
		TestsHelper::Print("testing app-conf points to its feature...");
		$menu = new AdminMenu();
		$this->assertSame(
			["title" => "App Configuration", "feature" => "AdminFeatureAppConfig", "active" => false],
			$menu->GetItem("app-conf")
		);
		$_GET["magrathea_feature"] = "AdminFeatureCache";
		try {
			$this->assertTrue($menu->FeatureItem("Cache", "AdminFeatureCache")["active"]);
			$this->assertFalse($menu->FeatureItem("App Configuration", "AdminFeatureAppConfig")["active"]);
		} finally {
			unset($_GET["magrathea_feature"]);
		}
	}

	function testEveryPageUrlInAdminSourceExists() {
		TestsHelper::Print("testing every GetPageUrl(\"...\") in the admin points to an existing page...");
		$start = MenuTestAdminStart::Instance();
		$admin = realpath(__DIR__."/../../Admin");
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($admin, \FilesystemIterator::SKIP_DOTS));
		$found = 0;
		foreach($files as $file) {
			if($file->getExtension() !== "php") continue;
			preg_match_all('/GetPageUrl\(\s*["\']([^"\']+)["\']/', file_get_contents($file->getPathname()), $m);
			foreach($m[1] as $page) {
				$found++;
				$this->assertNotNull($start->GetViewFile("pages", $page), $file->getPathname()." -> ".$page);
			}
		}
		$this->assertGreaterThan(0, $found);
	}

	function testQueryTesterIsGone() {
		TestsHelper::Print("testing the Query Tester (eval of posted code) is gone from the menu and the views...");
		$menu = new AdminMenu();
		$this->assertNull($menu->GetItem("mag-query"));
		$links = array_column(array_filter($menu->GetDatabaseSection()), "link");
		foreach($links as $link) {
			$this->assertNotSame("query-creator", $this->PageFromLink($link));
		}
		$this->assertCount(3, $links);
		$start = MenuTestAdminStart::Instance();
		$this->assertNull($start->GetViewFile("pages", "query-creator"));
		$this->assertNull($start->GetViewFile("actions", "query-creator"));
		$this->assertFileDoesNotExist(__DIR__."/../../Admin/views/javascript/query.js");
	}

	function testCodeGeneratorIsGone() {
		TestsHelper::Print("testing the code generator is gone from the menu and the views...");
		$menu = new AdminMenu();
		foreach(["objects-create", "objects-conf", "objects", "objects-edit", "code-gen"] as $key) {
			$this->assertNull($menu->GetItem($key), $key);
		}
		$this->assertFalse(method_exists($menu, "GetObjectSection"));
		$start = MenuTestAdminStart::Instance();
		foreach(["objects-config", "objects-create", "objects-edit", "objects-view", "generate-code"] as $page) {
			$this->assertNull($start->GetViewFile("pages", $page), $page);
		}
		$this->assertNull($start->GetViewFile("actions", "object-detail"));
		$this->assertFalse(class_exists("\\Magrathea\\Admin\\ObjectManager"));
		$this->assertFalse(class_exists("\\Magrathea\\Admin\\CodeCreator"));
	}

	function testAddIgnoresNull() {
		TestsHelper::Print("testing AdminMenu::Add() ignores null items...");
		$menu = new AdminMenu();
		$menu->Add(null)->Add($menu->GetItem("mag-query"));
		$this->assertSame([], $menu->GetMenu());
		$menu->Add([$menu->GetItem("mag-query"), $menu->GetItem("logs"), null]);
		$this->assertSame([$menu->GetItem("logs")], $menu->GetMenu());
		$menu->Add($menu->GetItem("tests"));
		$this->assertSame([$menu->GetItem("logs"), $menu->GetItem("tests")], $menu->GetMenu());
	}

}
