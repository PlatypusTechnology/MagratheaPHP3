<?php

use Magrathea\Admin\QueryRunner;

$adminElements = \Magrathea\Admin\AdminElements::Instance();

$query = (string)@$_POST["q"];
$run = (new QueryRunner())->Run($query, @$_POST["confirm"] === "1");

if($run["status"] === QueryRunner::EMPTY) {
	$adminElements->Alert("query empty!", "danger");
	die;
}

$error = ($run["status"] === QueryRunner::ERROR);
$confirm = ($run["status"] === QueryRunner::CONFIRM);
$types = implode(", ", array_unique(array_filter(array_column($run["statements"], "type"))));
$count = count($run["statements"]);

?>

<div class="card ">
	<div class="card-header <?=($error ? "error" : "")?>">
		<?=($error ? "ERROR!" : "")?>
		<?=($confirm ? "Confirm:" : "")?>
		Query <?=htmlspecialchars($types)?>
		<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
	</div>
	<div class="card-body">
		<?php
		// the query, its results and the errors come from the database: escaped
		if($confirm) {
			?>
			<div class="row">
				<div class="col-12">
					<?php
					$adminElements->Alert(
						"This SQL isn't read-only (".$count." statement".($count > 1 ? "s" : "")."). Nothing ran yet: check it, then run it.",
						"warning", false);
					foreach($run["statements"] as $statement) {
						echo '<pre class="code-light">'.htmlspecialchars($statement["sql"]).'</pre>';
					}
					?>
					<textarea class="confirm-sql" style="display: none;"><?=htmlspecialchars($query)?></textarea>
					<div class="right">
					<?php
					$adminElements->Button("Run it", "ConfirmQuery(this);", ["btn-danger"]);
					?>
					</div>
				</div>
			</div>
			<?php
		} else {
			$adminElements->QueryResults($run);
		}
		?>
	</div>
</div>
