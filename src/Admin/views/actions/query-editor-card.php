<?php

use Magrathea\Admin\QueryEditor;
use Magrathea\Admin\QueryRunner;

$adminElements = \Magrathea\Admin\AdminElements::Instance();

// the spec is posted as JSON; "run" runs it, "confirm" confirms a write
$specJson = (string)@$_POST["spec"];
$spec = json_decode($specJson, true);
if(!is_array($spec)) $spec = [];
try {
	$built = QueryEditor::Build($spec);
} catch(\Throwable $ex) {
	$adminElements->Alert(htmlspecialchars($ex->getMessage()), "danger");
	return;
}
$run = (@$_POST["run"] === "1") ? (new QueryRunner())->Run($built["sql"], @$_POST["confirm"] === "1") : null;
$error = ($run !== null && $run["status"] === QueryRunner::ERROR);
$confirm = ($run !== null && $run["status"] === QueryRunner::CONFIRM);

?>

<div class="card">
	<div class="card-header <?=($error ? "error" : "")?>">
		<?=($error ? "ERROR!" : "")?>
		<?=($confirm ? "Confirm:" : "")?>
		Query editor: <?=htmlspecialchars(strtoupper($spec["type"]))?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<?php // built from names Schema listed and escaped values, but still data: escaped ?>
		<div class="row">
			<div class="col-12">
				<label>PHP</label>
				<pre class="code-light qe-php"><?=htmlspecialchars($built["php"])?></pre>
				<label>SQL</label>
				<pre class="code-light qe-sql"><?=htmlspecialchars($built["sql"])?></pre>
				<div class="right">
				<?php
				$adminElements->Button("Copy PHP", "copyFromCard(this, '.qe-php');", ["btn-secondary"]);
				$adminElements->Button("Copy SQL", "copyFromCard(this, '.qe-sql');", ["btn-secondary"]);
				?>
				</div>
			</div>
		</div>
		<?php
		if($confirm) {
			$adminElements->Alert("This query writes. Nothing ran yet: check it, then run it.", "warning", false);
			?>
			<textarea class="confirm-spec" style="display: none;"><?=htmlspecialchars($specJson)?></textarea>
			<div class="right">
			<?php
			$adminElements->Button("Run it", "ConfirmEditorQuery(this);", ["btn-danger"]);
			?>
			</div>
			<?php
		} elseif($run !== null) {
			$adminElements->QueryResults($run);
		}
		?>
	</div>
</div>
