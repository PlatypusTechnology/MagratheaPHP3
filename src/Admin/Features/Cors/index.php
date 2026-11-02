<?php

use Magrathea\Admin\AdminElements;

/** @var string $featureId */
/** @var string $filePath */
/** @var bool $fileExists */
/** @var string $content */
/** @var bool $readError */

$elements = AdminElements::Instance();
$elements->Header("CORS Origins");

?>

<div class="container">
	<div class="card">
		<div class="card-header">
			Allowed origins
		</div>
		<div class="card-body">
			<div class="row">
				<div class="col-12">
					<p>
						File: <code><?=htmlspecialchars($filePath, ENT_QUOTES)?></code>
						<?php if(!$fileExists) { ?>
							<span class="badge bg-secondary">not created yet</span>
						<?php } ?>
					</p>
					<p>
						Read by <code>MagratheaApi::AllowFromFile()</code>. One origin per line, exactly as browsers send it:
						<code>scheme://host[:port]</code>, lowercase, no path or trailing slash
						(<code>https://app.example.com</code>, <code>http://localhost:3000</code>).
						Lines starting with <code>#</code> are comments.
					</p>
					<?php
					if($readError) {
						$elements->Alert("Could not read the file: saving replaces its content.", "warning", false);
					}
					$elements->Textarea("cors-origins", "Origins", $content, "font-monospace", "", "https://app.example.com", ["rows" => 10]);
					?>
				</div>
			</div>
			<div class="row mt-2">
				<div class="col-12 right">
					<?php
					$elements->Button("Save", "saveCorsOrigins(".htmlspecialchars(json_encode($featureId), ENT_QUOTES).");", ["btn-success"]);
					?>
				</div>
			</div>
		</div>
	</div>

	<div class="row mt-2">
		<div class="col-sm-12" id="cors-result"></div>
	</div>
</div>
