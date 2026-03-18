<div class="section" id="x2mail-wizard">
	<h2><?php echo($l->t('Setup Wizard')); ?></h2>

	<div class="domain-selector" id="wizard-domain-tabs"></div>

	<h3><?php echo($l->t('Mail Server')); ?></h3>
	<div class="wizard-grid">
		<label for="wiz-imap-host"><?php echo($l->t('IMAP Host')); ?></label>
		<input type="text" id="wiz-imap-host" placeholder="mail.example.com">

		<label for="wiz-imap-port"><?php echo($l->t('IMAP Port')); ?></label>
		<input type="number" id="wiz-imap-port" value="143" min="1" max="65535">

		<label for="wiz-imap-ssl"><?php echo($l->t('IMAP Security')); ?></label>
		<select id="wiz-imap-ssl">
			<option value="none">None</option>
			<option value="ssl">SSL/TLS</option>
			<option value="starttls">STARTTLS</option>
		</select>

		<label for="wiz-smtp-host"><?php echo($l->t('SMTP Host')); ?></label>
		<input type="text" id="wiz-smtp-host" placeholder="(same as IMAP)">

		<label for="wiz-smtp-port"><?php echo($l->t('SMTP Port')); ?></label>
		<input type="number" id="wiz-smtp-port" value="25" min="1" max="65535">

		<label for="wiz-smtp-ssl"><?php echo($l->t('SMTP Security')); ?></label>
		<select id="wiz-smtp-ssl">
			<option value="none">None</option>
			<option value="ssl">SSL/TLS</option>
			<option value="starttls">STARTTLS</option>
		</select>

		<div class="checkbox-row">
			<input type="checkbox" id="wiz-smtp-auth" class="checkbox">
			<label for="wiz-smtp-auth"><?php echo($l->t('SMTP requires authentication')); ?></label>
		</div>
	</div>

	<h3><?php echo($l->t('Domain & Authentication')); ?></h3>
	<div class="wizard-grid">
		<label for="wiz-domain"><?php echo($l->t('Domain')); ?></label>
		<input type="text" id="wiz-domain" placeholder="example.com">

		<label for="wiz-auth-type"><?php echo($l->t('Auth Type')); ?></label>
		<select id="wiz-auth-type">
			<option value="plain">Plain (password)</option>
			<option value="oauthbearer">OAUTHBEARER (SSO)</option>
			<option value="xoauth2">XOAUTH2 (SSO)</option>
		</select>

		<label for="wiz-oidc-provider" id="wiz-oidc-label" style="display:none"><?php echo($l->t('OIDC Provider')); ?></label>
		<select id="wiz-oidc-provider" style="display:none">
			<option value="user_oidc">user_oidc</option>
			<option value="oidc_login">oidc_login</option>
		</select>

		<div class="checkbox-row">
			<input type="checkbox" id="wiz-sieve" class="checkbox">
			<label for="wiz-sieve"><?php echo($l->t('Enable Sieve filtering')); ?></label>
		</div>
	</div>

	<h3><?php echo($l->t('Preflight Checks')); ?></h3>
	<button id="wiz-preflight-btn" class="button"><?php echo($l->t('Run Checks')); ?></button>
	<div class="preflight-results" id="wiz-preflight-results" style="display:none"></div>

	<div class="wizard-actions">
		<button id="wiz-save-btn" class="button primary"><?php echo($l->t('Save Configuration')); ?></button>
		<button id="wiz-delete-btn" class="button" style="display:none; color:var(--color-error,#e9322d)"><?php echo($l->t('Delete Domain')); ?></button>
		<span class="status-msg" id="wiz-status-msg"></span>
	</div>
</div>
