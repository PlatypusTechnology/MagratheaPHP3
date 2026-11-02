<?php
// $data: Steps::Checks() (CheckReport::Group()). Helpers: see index.php
?>
<div class="panel">
	<h2>Checks</h2>
	<p>
		Every check of <code>ProjectCheck::Run()</code>: the config, the folders, the database, the timezone, and the
		models against their tables. The fixes are SQL to run yourself: nothing here runs them.
		The same report is the admin's Database &rarr; Checks, and <code>vendor/bin/magrathea check</code>.
	</p>
	<p>
		<?php foreach($data["counts"] as $level => $count) echo '<span class="badge level-'.$h($level).'">'.$h($level).': '.(int)$count.'</span>'; ?>
	</p>
	<p class="muted">A <code>bootstrap_unlocked</code> warning outside <code>dev</code> is expected here: <b>Finish</b> removes the flag.</p>
</div>
<?php foreach($data["sections"] as $section) { ?>
	<div class="panel">
		<h3>
			<?=$h($section["title"])?>
			<?php if($section["status"] === null) { ?>
				<span class="badge muted">not checked</span>
			<?php } else { ?>
				<span class="badge level-<?=$h($section["status"])?>"><?=$h($section["status"])?></span>
			<?php } ?>
		</h3>
		<?php
		if(empty($section["findings"])) {
			echo '<p class="muted">Nothing to report: skipped (after an error above, or without a database), or nothing found.</p>';
		} else {
			$findings($section["findings"]);
		}
		if($section["fixes"] !== "") {
			echo '<p>Every fix of this section:</p><pre>'.$h($section["fixes"]).'</pre>';
		}
		?>
	</div>
<?php } ?>
