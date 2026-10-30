<?php
use Magrathea\Admin\QueryRunner;

// the query, its results and the errors come from the database: escaped
$magratheaStatements = $run["statements"];
if($run["status"] === QueryRunner::ERROR && $run["error"] !== null && count($magratheaStatements) === 0) {
	// the SQL couldn't even be split
	$this->Alert(htmlspecialchars($run["error"]), "danger", false);
}
$magratheaHasRows = !empty(array_filter(array_column($magratheaStatements, "rows"), fn($rows) => $rows !== null));
if($magratheaHasRows) {
	echo '<div class="raw-switch">';
	$this->Checkbox(null, "Raw Response", true, true, [], true, ["onchange" => "switchRs(this);"]);
	echo '</div>';
}
foreach($magratheaStatements as $magratheaStatement) {
	?>
	<div class="row border-bottom pb-1 mb-2">
		<div class="col-12"><pre class="code-light"><?=htmlspecialchars($magratheaStatement["sql"])?></pre></div>
		<div class="col-12">
			<?php
			if($magratheaStatement["error"] !== null) {
				$this->Alert(htmlspecialchars($magratheaStatement["error"]), "danger", false);
			} else {
				if($magratheaStatement["affected"] !== null) {
					echo "<p>".(int)$magratheaStatement["affected"]." row(s) affected</p>";
				}
				if($magratheaStatement["truncated"]) {
					$this->Alert("Showing the first ".QueryRunner::MAX_ROWS." rows: there are more.", "warning", false);
				}
			}
			?>
		</div>
		<?php if($magratheaStatement["rows"] !== null) { ?>
		<div class="col-12 rs-raw">
			<pre class="pre-raw"><?=htmlspecialchars(print_r($magratheaStatement["rows"], true))?></pre>
		</div>
		<div class="col-12 rs-table table-scroll" style="display: none;">
			<?php $this->Table($magratheaStatement["rows"]); ?>
		</div>
		<?php } ?>
	</div>
	<?php
}
if($run["notRun"] > 0) {
	$this->Alert($run["notRun"]." statement(s) after the error didn't run. The ones before it did.", "warning", false);
}
