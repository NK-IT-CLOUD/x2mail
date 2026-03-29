<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
\OCP\Util::addStyle('x2mail', 'embed');
?>
<div id="app-content"
     style="display:flex;align-items:center;justify-content:center;
            height:100%;text-align:center;padding:2em;">
    <div>
        <h2 style="margin-bottom:0.5em;">X2Mail</h2>
        <?php if ($_['isAdmin']) { ?>
            <p style="margin-bottom:1em;color:var(--color-text-maxcontrast);">
                <?php echo($l->t('X2Mail is not configured yet.')); ?>
            </p>
            <a href="<?php echo \OCP\Util::linkToAbsolute('settings', 'admin/x2mail'); ?>" class="button primary">
                <?php echo($l->t('Setup Wizard')); ?>
            </a>
        <?php } else { ?>
            <p style="color:var(--color-text-maxcontrast);">
                <?php echo($l->t('X2Mail is not configured yet. Please contact your administrator.')); ?>
            </p>
        <?php } ?>
    </div>
</div>
