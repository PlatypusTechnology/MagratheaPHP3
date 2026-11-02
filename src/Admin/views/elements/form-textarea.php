<div class="form-group">
	<?php if(!$hideLabel) { ?>
	<label for="<?=$id?>"><?=$name?></label>
	<?php } ?>
	<textarea name="<?=$id?>" id="<?=$id?>" placeholder="<?=$placeholder?>" class="form-control <?=$class?>" <?=$atts?>><?=htmlspecialchars((string)$value, ENT_QUOTES)?></textarea>
</div>
