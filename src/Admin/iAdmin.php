<?php

namespace Magrathea\Admin;

interface iAdmin {
	public function Initialize();
	public function Auth($user): bool;
	public function SetFeatures();
	public function BuildMenu(): AdminMenu;
}
