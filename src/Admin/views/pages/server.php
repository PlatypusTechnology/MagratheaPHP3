<?php

use function Magrathea\now;

$pageTitle = "Server & Info";
\Magrathea\Admin\AdminElements::Instance()->Header($pageTitle);

?>

<div class="container">
	<div class="card">
		<div class="card-header">
			Server Info
		</div>
		<div class="card-body">
			Now: <?=now()?> / Timezone: <?=date_default_timezone_get()?><br/>
			<a href="https://www.php.net/manual/en/timezones.php" target="_blank">Timezones</a>

		</div>
	</div>

	<div class="card">
		<div class="card-header">
			Timezone check
		</div>
		<div class="card-body">
			<?php
			\Magrathea\Admin\AdminElements::Instance()->Findings(\Magrathea\TimezoneCheck::Run());
			?>
		</div>
	</div>

	<div class="card">
		<div class="card-header">
			PHP Info
		</div>
		<div class="card-body">
			<?php phpinfo(); ?>
		</div>
	</div>

</div>
