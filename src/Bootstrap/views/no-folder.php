<?php
// $page, $h: see index.php
?>
<div class="panel">
	<h2>The wizard can't write its token</h2>
	<p>
		The Setup Wizard keeps its token in the configs folder, where only someone with access to the server's files
		can read it, and it couldn't write there:
	</p>
	<pre><?=$h($page["error"])?></pre>
	<p>Create the folder, writable by the web server's user, and reload this page. For example:</p>
	<pre>mkdir -p <?=$h(escapeshellarg($page["folder"]))?>

chmod 750 <?=$h(escapeshellarg($page["folder"]))?>

chown &lt;web server user&gt; <?=$h(escapeshellarg($page["folder"]))?></pre>
	<p class="muted">The folder must not be reachable from the web: it will hold <code>magrathea.conf</code>, with the database password.</p>
</div>
