<?php
use Magrathea\Admin\AdminUrls;
use Magrathea\TimezoneCheck;

// messages, paths and fixes come from the config, the filesystem and the database: escaped, here and in Findings()
$magratheaBadgeClass = [
	TimezoneCheck::OK => "success",
	TimezoneCheck::INFO => "secondary",
	TimezoneCheck::WARNING => "warning",
	TimezoneCheck::ERROR => "danger",
];
$magratheaLinks = [
	"config" => ["Configuration file" => AdminUrls::Instance()->GetPageUrl("config")],
	"folders" => ["Logs" => AdminUrls::Instance()->GetPageUrl("logs"), "Dumps" => AdminUrls::Instance()->GetPageUrl("dumps")],
];
?>
<div class="card">
	<div class="card-header">
		Summary
	</div>
	<div class="card-body">
		<?php
		foreach($report["counts"] as $magratheaLevel => $magratheaCount) {
			echo '<span class="badge bg-'.($magratheaBadgeClass[$magratheaLevel] ?? "secondary").' me-1">'
				.htmlspecialchars((string)$magratheaLevel).': '.(int)$magratheaCount.'</span>';
		}
		?>
	</div>
</div>
<?php
foreach($report["sections"] as $magratheaSection) {
	$magratheaKey = (string)$magratheaSection["key"];
	$magratheaStatus = $magratheaSection["status"];
	?>
	<div class="card" id="checks-<?=htmlspecialchars($magratheaKey)?>">
		<div class="card-header">
			<?=htmlspecialchars((string)$magratheaSection["title"])?>
			<?php if($magratheaStatus === null) { ?>
				<span class="badge bg-light text-muted">no findings</span>
			<?php } else { ?>
				<span class="badge bg-<?=$magratheaBadgeClass[$magratheaStatus] ?? "secondary"?>"><?=htmlspecialchars((string)$magratheaStatus)?></span>
			<?php } ?>
		</div>
		<div class="card-body">
			<?php
			if(empty($magratheaSection["findings"])) {
				echo '<p class="text-muted mb-0">Nothing to report: this check was skipped (after an error above, or without a database), or it found nothing.</p>';
			} else {
				$this->Findings($magratheaSection["findings"]);
			}
			if($magratheaSection["fixes"] !== "") {
				$magratheaFixesId = "checks-fixes-".$magratheaKey;
				?>
				<p class="mt-3 mb-1">Every fix of this section (shown, never run):</p>
				<pre class="code-light" id="<?=htmlspecialchars($magratheaFixesId)?>"><?=htmlspecialchars($magratheaSection["fixes"])?></pre>
				<div class="right">
				<?php
				$this->Button("Copy", "navigator.clipboard.writeText(document.getElementById('".htmlspecialchars($magratheaFixesId)."').innerText);", ["btn-secondary"]);
				?>
				</div>
				<?php
			}
			if(!empty($magratheaLinks[$magratheaKey])) {
				$magratheaAnchors = [];
				foreach($magratheaLinks[$magratheaKey] as $magratheaTitle => $magratheaUrl) {
					$magratheaAnchors[] = '<a href="'.htmlspecialchars($magratheaUrl).'">'.$magratheaTitle.'</a>';
				}
				echo '<p class="mt-2 mb-0">'.implode(" &middot; ", $magratheaAnchors).'</p>';
			}
			?>
		</div>
	</div>
	<?php
}
