<?php /** @var \OCP\IL10N $l */ ?>

<?php require __DIR__ . '/setup-wizard.php'; ?>

<div class="section">
	<form class="snappymail" action="setAdmin" method="post">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']) ?>" id="requesttoken">
		<fieldset class="personalblock">
			<h2><?php echo($l->t('X2Mail Settings')); ?></h2>
			<br />
			<?php if ($_['snappymail-admin-panel-link']) { ?>
			<p>
				<a href="<?php p($_['snappymail-admin-panel-link']) ?>" style="text-decoration: underline">
					<?php echo($l->t('Go to X2Mail admin panel')); ?>
				</a>
			<?php if ($_['snappymail-admin-password']) { ?>
				<br/>
				Username: admin<br/>
				Temporary password: <?php p($_['snappymail-admin-password']); ?>
			<?php } ?>
			</p>
			<br />
			<?php } ?>

			<h3><?php echo($l->t('OIDC / SSO')); ?></h3>
			<p>
				<input id="snappymail-autologin-oidc" name="snappymail-autologin-oidc" type="checkbox" class="checkbox" <?php if ($_['snappymail-autologin-oidc']) echo 'checked="checked"'; ?>>
				<label for="snappymail-autologin-oidc">
					<?php echo($l->t('Auto-login with OIDC token (requires user_oidc or oidc_login)')); ?>
				</label>
			</p>
			<br />

			<h3><?php echo($l->t('Display')); ?></h3>
			<p>
				<input id="snappymail-no-embed" name="snappymail-no-embed" type="checkbox" class="checkbox" <?php if ($_['snappymail-no-embed']) echo 'checked="checked"'; ?>>
				<label for="snappymail-no-embed">
					<?php echo($l->t('Use iframe instead of embedded mode')); ?>
				</label>
			</p>
			<br />
			<p>
				<input id="snappymail-nc-lang" name="snappymail-nc-lang" type="checkbox" class="checkbox" <?php if ($_['snappymail-nc-lang']) echo 'checked="checked"'; ?>>
				<label for="snappymail-nc-lang">
					<?php echo($l->t('Force Nextcloud language')); ?>
				</label>
			</p>
			<br />

			<h3><?php echo($l->t('Debug')); ?></h3>
			<p>
				<input id="snappymail-debug" name="snappymail-debug" type="checkbox" class="checkbox" <?php if ($_['snappymail-debug']) echo 'checked="checked"'; ?>>
				<label for="snappymail-debug">
					<?php echo($l->t('Enable engine debug logging')); ?>
				</label>
			</p>
			<br />
			<p>
				<input id="x2mail-debug-log" name="x2mail-debug-log" type="checkbox" class="checkbox" <?php if ($_['x2mail-debug-log']) echo 'checked="checked"'; ?>>
				<label for="x2mail-debug-log">
					<?php echo($l->t('Enable X2Mail debug logging (OIDC token events, refresh)')); ?>
				</label>
			</p>
			<br />

			<h3><?php echo($l->t('Advanced')); ?></h3>
			<p>
				<label for="snappymail-app_path">
					<?php echo($l->t('app_path')); ?>
				</label>
				<input id="snappymail-app_path" name="snappymail-app_path" type="text" value="<?php p($_['snappymail-app_path']); ?>" style="width:20em">
			</p>
			<br />

			<p>
				<button id="snappymail-save-button" name="snappymail-save-button"><?php echo($l->t('Save')); ?></button>
				<div class="snappymail-result-desc" style="white-space: pre"></div>
			</p>
		</fieldset>
	</form>
</div>
