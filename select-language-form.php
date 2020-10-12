<?php ob_start(); ?>

	<div class="subtitle-language-block"><label for="caption_language[<?=$file_id?>]">Set the language</label>: <select name="caption_language[<?=$file_id?>]" class="custom-select form-control subtitle-language">
	    <?php foreach ((array) $languages as $key => $value): ?>
		    <option value="<?=$key?>"<?=(isset ($language) && $language == $key) ? ' selected' : ''?>><?=$value?></option>
	    <?php endforeach; ?>
	</select>
			</div>

<?php 

$language_selector = ob_get_clean(); 

return $language_selector;

?>

