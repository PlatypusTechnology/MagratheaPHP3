<?php

use Magrathea\Admin\AdminElements;
use Magrathea\Admin\DumpManager;
use Magrathea\DB\Dump;

$adminElements = AdminElements::Instance();
// POST only: admin CSRF tokens are checked on POST
if(@$_SERVER["REQUEST_METHOD"] !== "POST") {
	http_response_code(405);
	$adminElements->Alert("POST only", "danger");
	return;
}
// without "confirm" (the database's name, typed), nothing runs: the card asks for it
try {
	$import = (new DumpManager())->Import(@$_POST["file"], @$_POST["confirm"], @$_POST["driver"], @$_POST["continue"] === "1");
} catch(\Exception $ex) {
	$adminElements->Alert("Import failed: ".htmlspecialchars($ex->getMessage()), "danger");
	return;
}
$confirm = ($import["status"] === DumpManager::CONFIRM);
$h = fn($value) => htmlspecialchars((string)$value, ENT_QUOTES);
$drivers = ["" => "auto (binary when available)", Dump::BINARY => Dump::BINARY, Dump::PHP => Dump::PHP];

?>

<div class="card">
	<div class="card-header">
		<?=($confirm ? "Confirm:" : "")?>
		Import <?=$h($import["file"])?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<?php
		if($confirm) {
			if($import["error"] !== null) $adminElements->Alert($h($import["error"]), "danger", false);
			$adminElements->Alert("This runs every statement of <b>".$h($import["file"])."</b> on the database <b>".$h($import["database"])."</b>. ".
				"A dump usually drops and recreates its tables: what they hold now is lost. Nothing ran yet.", "warning", false);
			?>
			<input type="hidden" class="dump-confirm-file" value="<?=$h($import["file"])?>">
			<input type="hidden" class="dump-confirm-database" value="<?=$h($import["database"])?>">
			<div class="row">
				<div class="col-6">
					<label>Type the database's name to confirm</label>
					<input type="text" class="form-control dump-confirm-name" autocomplete="off" oninput="dumpConfirmInput(this);">
				</div>
				<div class="col-3">
					<label>Driver</label>
					<select class="form-select dump-confirm-driver">
						<?php foreach($drivers as $value => $label) echo '<option value="'.$h($value).'">'.$h($label).'</option>'; ?>
					</select>
				</div>
				<div class="col-3">
					<label class="form-check mt-4">
						<input type="checkbox" class="form-check-input dump-confirm-continue"> Continue on errors
					</label>
				</div>
			</div>
			<div class="right mt-3">
				<button type="button" class="btn btn-danger dump-confirm-btn" disabled onclick="dumpConfirmImport(this);">Import it</button>
			</div>
			<?php
		} else {
			$result = $import["result"];
			$errors = $result["errors"] ?? [];
			$statements = $result["statements"] === null ? "not counted with the binary driver" : $h($result["statements"])." statements ran";
			$adminElements->Alert("Imported <b>".$h($import["file"])."</b> into <b>".$h($import["database"])."</b> with the <b>".
				$h($result["driver"])."</b> driver: ".$statements.".", $errors ? "warning" : "success", false);
			if($errors) {
				echo "<p><b>".count($errors)." error(s)</b> (continued past them):</p>";
				$adminElements->Table(array_map(fn($e) => is_array($e) ? $e : ["error" => $e], $errors));
			}
		}
		?>
	</div>
</div>
