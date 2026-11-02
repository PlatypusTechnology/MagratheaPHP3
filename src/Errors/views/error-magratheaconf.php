<?php

use Magrathea\Config;

// GetFilePath() throws when the file is missing: this page is shown exactly then
$fileLocation = Config::Instance()->GetPath()."/".Config::Instance()->GetConfigFile();
$sample = @file_get_contents(__DIR__."/../../Bootstrap/docs/magrathea.conf.sample");
?>

<div class="row col-mt4">
	<div class="col-md-12">
		<?php if(file_exists($fileLocation)) { ?>
		Magrathea Configuration could not be read!<br/>
		We tried to parse it at <pre class="inline code"><?=htmlspecialchars($fileLocation)?></pre>
		<?php } else { ?>
		Magrathea Configuration does not exists!<br/>
		We were looking for it at <pre class="inline code"><?=htmlspecialchars($fileLocation)?></pre>
		<?php } ?>
	</div>
	<?php if(!file_exists($fileLocation)) { ?>
	<div class="col-md-12 mt-2">
		Create it with the <b>Setup Wizard</b>: add <pre class="inline code">public/setup.php</pre> to the web root
		(the skeleton has it), with:
		<pre class="code"><?=htmlspecialchars("<?php\nrequire __DIR__.\"/../vendor/autoload.php\";\nMagrathea\\Bootstrap\\Start::Instance()->AppPath(__DIR__.\"/../app\")->Load();")?></pre>
		Open it in the browser. It asks for a token it writes to <pre class="inline code">configs/.setup-token</pre>,
		next to the config: read it on the server (<pre class="inline code">cat configs/.setup-token</pre>).
		Or write the config by hand, from the sample below.
	</div>
	<?php } ?>
	<?php if($sample !== false) { ?>
	<div class="col-md-12 mt-2">
		Magrathea conf sample:
		<pre class="code"><?=htmlspecialchars($sample)?></pre>
	</div>
	<?php } ?>
</div>
