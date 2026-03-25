<style id="app-boot-css"><?php print_unescaped($_['BaseAppBootCss']); ?></style>
<style id="app-theme-style"><?php print_unescaped($_['BaseAppThemeCss']); ?></style>
<div id="rl-app" data-admin="<?php p($_['Admin']); ?>" spellcheck="false">
	<div id="rl-loading">
		<div id="rl-loading-desc"><?php p($_['LoadingDescriptionEsc']); ?></div>
		<i class="icon-spinner"></i>
	</div>
	<div id="rl-loading-error" hidden="">An error occurred.<br>Please refresh the page and try again.</div>
	<div id="rl-content" hidden="">
		<div id="rl-left"></div>
		<div id="rl-right"></div>
	</div>
	<div id="rl-popups"></div>
	<?php print_unescaped($_['BaseTemplates']); ?>
</div>
<?php
print_unescaped('
	<script nonce="'.$_['BaseAppBootScriptNonce'].'" type="text/javascript">'.$_['BaseAppBootScript'].$_['BaseLanguage'].'</script>
');
