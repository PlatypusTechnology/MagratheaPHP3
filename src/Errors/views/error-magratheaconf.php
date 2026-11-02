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
	<?php if($sample !== false) { ?>
	<div class="col-md-12 mt-2">
		Magrathea conf sample:
		<pre class="code"><?=htmlspecialchars($sample)?></pre>
	</div>
	<?php } ?>
</div>
