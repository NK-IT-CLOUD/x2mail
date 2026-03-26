<div class="section">
	<h2><?php echo \htmlspecialchars($_['brandName']) . ' ' . $l->t('Settings'); ?></h2>
	<p style="margin-bottom: 12px">
		<a href="<?php echo \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('x2mail.page.index'); ?>#/settings/accounts"
		   style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 100px; background: var(--color-primary-element, #0077C7); color: #fff; text-decoration: none; font-weight: 500;">
			✉ <?php echo $l->t('Identities & Signatures'); ?>
		</a>
	</p>
	<p style="color: var(--color-text-maxcontrast, #888); font-size: 13px;">
		<?php echo $l->t('Manage sender addresses, display names, reply-to and signatures.'); ?>
	</p>
</div>
