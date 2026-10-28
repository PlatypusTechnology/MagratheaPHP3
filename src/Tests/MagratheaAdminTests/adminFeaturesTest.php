<?php

use Magrathea\Admin\Admin;
use Magrathea\Tests\TestsHelper;

class adminFeaturesTest extends \PHPUnit\Framework\TestCase {

	private function menuJson(Admin $admin): string {
		return json_encode($admin->BuildMenu()->GetMenu());
	}

	public function testDefaultFeaturesDontIncludeFileEditor() {
		TestsHelper::Print("Testing the File Editor is not a default admin feature");
		$admin = new Admin();
		$admin->SetFeatures();
		$features = $admin->GetFeatures();
		$this->assertArrayNotHasKey("AdminFeatureFileEditor", $features);
		$this->assertArrayHasKey("AdminFeatureAppConfig", $features);
		$this->assertArrayHasKey("AdminFeatureCache", $features);
		$this->assertArrayHasKey("AdminFeatureUser", $features);
		$this->assertArrayHasKey("AdminFeatureUserLog", $features);
	}

	public function testDefaultMenuHasNoFileEditor() {
		TestsHelper::Print("Testing the default admin menu has no File Editor");
		$admin = new Admin();
		$admin->SetFeatures();
		$this->assertStringNotContainsString("File Editor", $this->menuJson($admin));
	}

	public function testFileEditorIsGone() {
		TestsHelper::Print("Testing the File Editor is gone");
		$this->assertFalse(method_exists(Admin::class, "LoadFileEditor"));
		$this->assertFalse(class_exists("\\Magrathea\\Admin\\Features\\FileEditor\\AdminFeatureFileEditor"));
		$this->assertNull((new \Magrathea\Admin\AdminMenu())->GetItem("tools-file-editor"));
		$this->assertFalse(method_exists(\Magrathea\Admin\AdminUrls::class, "FileViewUrl"));
	}

}
