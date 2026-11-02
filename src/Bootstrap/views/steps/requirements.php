<?php
// $data: Steps::Requirements(). Helpers: see index.php
?>
<div class="panel">
	<h2>Requirements</h2>
	<p>What the project needs before there's a config: PHP, its extensions, and a configs folder the wizard can write, outside the web root.</p>
	<?php $findings($data["findings"]); ?>
</div>
