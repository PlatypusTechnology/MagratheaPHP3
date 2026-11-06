<?php
// $data: Steps::FinishForm() (before); $result: Steps::Finish() (after: the session isn't verified anymore). Helpers: see index.php
use Magrathea\MagratheaPHP;

$docs = MagratheaPHP::GetDocumentationLink();
?>
<div class="panel">
	<h2>Finish</h2>
	<?php if($result !== null) { ?>
		<?php
		if($result["tokenDeleted"]) {
			echo '<div class="alert level-ok">Deleted the token: this session is over.</div>';
		} else {
			echo '<div class="alert level-error">The token couldn\'t be deleted: delete <code>'.$h($result["tokenFile"]).'</code> on the server.</div>';
		}
		foreach($result["removed"] as $section) {
			echo '<div class="alert level-ok">Removed <code>bootstrap_unlocked</code> from <code>['.$h($section).']</code>.</div>';
		}
		foreach($result["kept"] as $section) {
			echo '<div class="alert level-info">Kept <code>bootstrap_unlocked</code> in <code>['.$h($section).']</code>: there, the wizard asks for a new token.</div>';
		}
		if(empty($result["kept"])) {
			echo '<div class="alert level-ok">The wizard is locked. To open it again, add <code>bootstrap_unlocked = true</code> to the active environment\'s section.</div>';
		}
		?>
		<h3>Next</h3>
		<ul>
			<li>The admin: <a href="magrathea.php">magrathea.php</a> (the skeleton's <code>public/magrathea.php</code>).</li>
			<li>The docs: <a href="<?=$h($docs)?>"><?=$h($docs)?></a>.</li>
			<li>On the server: <code>vendor/bin/magrathea check</code>, and <code>models:verify</code> after writing a model.</li>
			<li>If this server doesn't need the wizard anymore, delete <code>public/setup.php</code>.</li>
		</ul>
	<?php } else { ?>
		<p>Ends the setup:</p>
		<ul>
			<li>removes <code>bootstrap_unlocked</code> from every section of the config but <code>dev</code>
				<?php if($data["unlocked"]) { ?>(now in: <?=implode(", ", array_map(fn($s) => "<code>[".$h($s)."]</code>", $data["unlocked"]))?>)<?php } ?>;</li>
			<li>deletes the token (<code><?=$h($data["tokenFile"])?></code>), which ends this session.</li>
		</ul>
		<form method="post" action="<?=$h($wizard->GetStepLink("finish"))?>">
			<?=$csrfField()?>
			<input type="hidden" name="action" value="finish">
			<?php if($data["canKeepDev"]) { ?>
				<div class="field checkbox">
					<input type="checkbox" id="keep_unlocked" name="keep_unlocked" value="1" checked>
					<label for="keep_unlocked">Keep the wizard unlocked in <code>[dev]</code> (it still asks for a new token)</label>
				</div>
			<?php } ?>
			<button type="submit">Finish</button>
		</form>
	<?php } ?>
</div>
