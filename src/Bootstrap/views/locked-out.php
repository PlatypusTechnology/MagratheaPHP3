<?php
// $page, $h: see index.php
?>
<div class="panel">
	<h2>Too many wrong tokens</h2>
	<p>
		The Setup Wizard locked itself after <?=(int)\Magrathea\Bootstrap\SetupToken::MAX_FAILURES?> wrong tokens:
		no token is accepted, not even the right one.
	</p>
	<p>
		To unlock it, delete this file on the server:
	</p>
	<pre><?=$h($page["file"])?></pre>
	<p>The next visit then writes a new token to <code>configs/.setup-token</code>.</p>
</div>
