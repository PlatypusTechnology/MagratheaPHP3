<?php
// $page, $h, $md, $csrfField: see index.php. Not verified yet: no absolute path is shown
?>
<div class="panel">
	<h2>Setup token</h2>
	<?php if(!empty($page["error"])) echo '<div class="alert level-error">'.$md($page["error"]).'</div>'; ?>
	<p>
		<?php if($page["mode"] === \Magrathea\Bootstrap\Start::SETUP) { ?>
			There's no config yet.
		<?php } else { ?>
			The config unlocks the wizard (<code>bootstrap_unlocked</code>).
		<?php } ?>
		To prove you can reach the server's files, type the token the wizard wrote to
		<code>configs/.setup-token</code>, in the project's root (next to <code>configs/magrathea.conf</code>).
	</p>
	<p class="muted">On the server: <code>cat configs/.setup-token</code>. The first line is the token.</p>
	<form method="post" action="<?=$h(\Magrathea\Bootstrap\Start::GetBootstrapURL())?>">
		<?=$csrfField()?>
		<input type="hidden" name="action" value="verify">
		<div class="field">
			<label for="token">Token</label>
			<input type="text" class="token" id="token" name="token" placeholder="0000-0000" autocomplete="off" autofocus required>
		</div>
		<button type="submit">Continue</button>
	</form>
	<p class="muted">After <?=(int)\Magrathea\Bootstrap\SetupToken::MAX_FAILURES?> wrong tokens the wizard locks itself, until <code>configs/.setup-locked</code> is deleted.</p>
</div>
