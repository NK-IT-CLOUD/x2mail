<style id="app-boot-css"><?php print_unescaped(
	preg_replace('/(?:^|(?<=[\};]))(?:body|html)[\w#.\-]*(?:\s*,\s*(?:body|html)[\w#.\-]*)*\s*\{[^}]*\}/s', '', $_['BaseAppBootCss'])
); ?></style>
<style id="app-theme-style"><?php print_unescaped($_['BaseAppThemeCss']); ?></style>
<div id="x2m-app" data-admin="<?php p($_['Admin']); ?>" spellcheck="false">
	<div id="x2m-loading">
		<div id="x2m-loading-desc"><?php p($_['LoadingDescriptionEsc']); ?></div>
		<i class="icon-spinner"></i>
	</div>
	<div id="x2m-loading-error" hidden="">An error occurred.<br>Please refresh the page and try again.</div>
	<div id="x2m-content" hidden="">
		<div id="x2m-left"></div>
		<div id="x2m-right"></div>
	</div>
	<div id="x2m-popups"></div>
	<?php print_unescaped($_['BaseTemplates']); ?>
</div>
<?php
print_unescaped('
	<script nonce="'.$_['BaseAppBootScriptNonce'].'" type="text/javascript">'.$_['BaseAppBootScript'].$_['BaseLanguage'].'</script>
');
