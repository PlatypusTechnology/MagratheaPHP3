<?php
// $page, $h: see index.php. Only the environment's name is shown: nothing else of the config.
?>
<div class="panel">
	<h2>The Setup Wizard is locked</h2>
	<?php if(!empty($page["environment"])) { ?>
		<p>Environment: <b><?=$h($page["environment"])?></b></p>
	<?php } ?>
	<p>
		The config exists, and its active environment doesn't have <code>bootstrap_unlocked = true</code>.
		To use the wizard, add it to that environment's section in <code>configs/magrathea.conf</code> on the server.
		The wizard then asks for the token in <code>configs/.setup-token</code>, and its last step removes the flag
		again (outside <code>dev</code>).
	</p>
	<p class="muted">To reset an admin's password, the server's shell is enough: <code>vendor/bin/magrathea admin:reset-password &lt;email&gt;</code>.</p>
</div>
