<?php

use Magrathea\Admin\AdminElements;
use Magrathea\MagratheaPHP;

AdminElements::Instance()->Header("Version");

$magPhp = MagratheaPHP::Instance();

?>
<div class="container">
	<div class="card">
		<div class="card-header">
			Server Version Information
			<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
		</div>
		<div class="card-body">
			<b>App Version: <?=$magPhp->AppVersion();?></b> <br/><br/>

			Magrathea PHP Version: <?=$magPhp->version()?> <br/>
			Minimum Magrathea PHP Version Required: <?=@$magPhp->versionRequired ?? "00"?> <br/><br/>
			PHP Version: <?=phpversion()?> <br/>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			Server Time
			<div class="card-close" aria-label="Close" onclick="closeCard(this);">&times;</div>
		</div>
		<div class="card-body">
			Now: <?=now()?> / Timezone: <?=date_default_timezone_get()?>
		</div>
	</div>

</div>
