<?php /** @var \OCP\IL10N $l */ ?>

<?php require __DIR__ . '/setup-wizard.php'; ?>

<div class="section">
	<form class="x2mail" action="setAdmin" method="post">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>" id="requesttoken">
		<fieldset class="personalblock">
			<h2><?php echo($l->t('X2Mail Settings')); ?></h2>

			<?php if ($_['x2mail-admin-panel-link']) { ?>
			<p>
				<a href="<?php p($_['x2mail-admin-panel-link']) ?>" style="text-decoration: underline">
					<?php echo($l->t('Go to X2Mail admin panel')); ?>
				</a>
			</p>
			<br />
			<?php } ?>

			<h2><?php echo($l->t('OIDC / SSO')); ?></h2>
			<p>
				<input id="x2mail-autologin-oidc" name="x2mail-autologin-oidc" type="checkbox" class="checkbox" <?php if ($_['x2mail-autologin-oidc']) echo 'checked="checked"'; ?>>
				<label for="x2mail-autologin-oidc">
					<?php echo($l->t('Auto-login with OIDC token (requires user_oidc or oidc_login)')); ?>
				</label>
			</p>
			<br />

			<h2><?php echo($l->t('Display')); ?></h2>
			<p>
				<input id="x2mail-nc-lang" name="x2mail-nc-lang" type="checkbox" class="checkbox" <?php if ($_['x2mail-nc-lang']) echo 'checked="checked"'; ?>>
				<label for="x2mail-nc-lang">
					<?php echo($l->t('Force Nextcloud language')); ?>
				</label>
			</p>
			<br />

			<h2><?php echo($l->t('Advanced')); ?></h2>
			<p>
				<label for="x2mail-app-path">
					<?php echo($l->t('app_path')); ?>
				</label>
				<input id="x2mail-app-path" name="x2mail-app-path" type="text" value="<?php p($_['x2mail-app-path']); ?>" style="width:20em">
			</p>
			<br />

			<h2><?php echo($l->t('Debug')); ?></h2>
			<p>
				<input id="x2mail-debug" name="x2mail-debug" type="checkbox" class="checkbox" <?php if ($_['x2mail-debug']) echo 'checked="checked"'; ?>>
				<label for="x2mail-debug">
					<?php echo($l->t('Enable engine debug logging')); ?>
				</label>
			</p>
			<p>
				<input id="x2mail-debug-log" name="x2mail-debug-log" type="checkbox" class="checkbox" <?php if ($_['x2mail-debug-log']) echo 'checked="checked"'; ?>>
				<label for="x2mail-debug-log">
					<?php echo($l->t('Enable X2Mail debug logging (OIDC token events, refresh)')); ?>
				</label>
			</p>
			<br />

			<p>
				<button id="x2mail-save-button" name="x2mail-save-button"><?php echo($l->t('Save')); ?></button>
				<div class="x2mail-result-desc" style="white-space: pre"></div>
			</p>

			<hr style="margin-top: 2em;" />
			<p style="color: #888; font-size: 0.9em;">
				X2Mail <?php p($_['x2mail-version']); ?><br />
				2026 &copy; NK-IT Dev. <?php echo($l->t('All rights reserved.')); ?><br />
				<a href="https://github.com/NK-IT-CLOUD/x2mail" target="_blank" style="color: #888;">github.com/NK-IT-CLOUD/x2mail</a>
			</p>
		</fieldset>
	</form>
</div>
