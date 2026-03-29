<div class="section" id="x2mail-wizard">
    <h2><?php echo($l->t('Setup Wizard')); ?></h2>

    <div class="domain-selector" id="wiz-domain-tabs"></div>

    <h2><?php echo($l->t('Mail Server')); ?></h2>
    <div class="wizard-grid">
        <label for="wiz-imap-host"><?php echo($l->t('IMAP Host')); ?></label>
        <input type="text" id="wiz-imap-host" placeholder="mail.intern.domain.com">

        <label for="wiz-imap-port"><?php echo($l->t('IMAP Port')); ?></label>
        <input type="number" id="wiz-imap-port" value="143" min="1" max="65535">

        <label for="wiz-imap-ssl"><?php echo($l->t('IMAP Security')); ?></label>
        <select id="wiz-imap-ssl">
            <option value="none">None</option>
            <option value="ssl">SSL/TLS</option>
            <option value="starttls">STARTTLS</option>
        </select>

        <label for="wiz-smtp-host"><?php echo($l->t('SMTP Host')); ?></label>
        <input type="text" id="wiz-smtp-host" placeholder="<?php echo($l->t('same as IMAP')); ?>">

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

    <h2><?php echo($l->t('Domain & Authentication')); ?></h2>
    <div class="wizard-grid">
        <label for="wiz-domain"><?php echo($l->t('Domain')); ?></label>
        <input type="text" id="wiz-domain" placeholder="domain.com"
            title="<?php echo($l->t('Part after @ in user email addresses')); ?>">

        <label for="wiz-auth-type"><?php echo($l->t('Auth Type')); ?></label>
        <select id="wiz-auth-type">
            <option value="oauth" selected>SSO (OAUTHBEARER/XOAUTH2)</option>
            <option value="plain"><?php echo($l->t('Password')); ?></option>
        </select>

        <label for="wiz-oidc-provider" id="wiz-oidc-label"><?php echo($l->t('OIDC Provider')); ?></label>
        <select id="wiz-oidc-provider">
            <option value="user_oidc">user_oidc</option>
            <option value="oidc_login">oidc_login</option>
        </select>

        <div class="checkbox-row">
            <input type="checkbox" id="wiz-sieve" class="checkbox">
            <label for="wiz-sieve"><?php echo($l->t('Enable Sieve filtering')); ?></label>
        </div>
    </div>

    <h2 style="margin-top:1.5em"><?php echo($l->t('Preflight Checks')); ?></h2>
    <button id="wiz-preflight-btn" class="button"><?php echo($l->t('Run Checks')); ?></button>
    <div class="preflight-results" id="wiz-preflight-results" style="display:none"></div>

    <div class="wizard-actions">
        <button id="wiz-save-btn" class="button primary"><?php echo($l->t('Save Configuration')); ?></button>
        <button id="wiz-delete-btn" class="button" style="display:none"><?php echo($l->t('Delete Domain')); ?></button>
        <span class="status-msg" id="wiz-status-msg"></span>
    </div>
</div>
