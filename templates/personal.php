<?php

declare(strict_types=1);

$preferences = $_['preferences'];
$capabilities = $_['capabilities'];
$instance = $_['instanceSettings'];
$presets = $_['presets'] ?? [];
$nativeEvents = $preferences['notifications']['nextcloud']['events'] ?? [];
$emailEvents = $preferences['notifications']['email']['events'] ?? [];
$checked = static fn (bool $value): string => $value ? ' checked' : '';
?>
<section id="proofing-gallery-personal" class="settings-section">
	<h2><?= $l->t('Proofing Gallery') ?></h2>
	<p class="settings-hint"><?= $l->t('Save your usual project choices once and use them on every device.') ?></p>
	<form>
		<div class="proofing-personal__section">
			<h3><?= $l->t('New projects') ?></h3>
			<label><span><?= $l->t('Preferred purpose') ?></span><select name="defaultPurpose"><option value=""><?= $l->t('Use instance default') ?> (<?= htmlspecialchars((string)$instance['workflow']['defaultPurpose'], ENT_QUOTES) ?>)</option><?php foreach (['delivery' => $l->t('Deliver photos'), 'showcase' => $l->t('Show photos'), 'selection' => $l->t('Collect a selection'), 'proofing' => $l->t('Review together'), 'uploads' => $l->t('Receive files'), 'custom' => $l->t('Custom')] as $value => $label): ?><option value="<?= $value ?>"<?= $preferences['defaultPurpose'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
			<label><span><?= $l->t('Public language') ?></span><select name="publicLocale"><?php foreach (['auto' => $l->t('Automatic'), 'de' => 'Deutsch', 'en' => 'English'] as $value => $label): ?><option value="<?= $value ?>"<?= $preferences['publicLocale'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
			<label><span><?= $l->t('Preferred design preset') ?></span><select name="designPresetId"><option value=""><?= $l->t('Use instance design') ?></option><?php foreach ($presets as $preset): ?><option value="<?= (int)$preset->getId() ?>"<?= $preferences['designPresetId'] === $preset->getId() ? ' selected' : '' ?>><?= htmlspecialchars($preset->getName(), ENT_QUOTES) ?></option><?php endforeach; ?></select></label>
			<label><span><?= $l->t('Default parent folder') ?></span><span class="proofing-personal__folder"><input name="parentFolderName" readonly value="<?= htmlspecialchars((string)($preferences['parentFolder']['name'] ?? ''), ENT_QUOTES) ?>" placeholder="<?= $l->t('No folder selected') ?>"><input name="parentFolderId" type="hidden" value="<?= (int)($preferences['parentFolder']['id'] ?? 0) ?>"><button type="button" data-action="folder"><?= $l->t('Choose folder') ?></button><button type="button" data-action="clear-folder"><?= $l->t('Clear') ?></button></span></label>
		</div>
		<div class="proofing-personal__section">
			<h3><?= $l->t('Notifications') ?></h3>
			<p class="settings-hint"><?= $l->t('These defaults are applied to newly created galleries.') ?></p>
			<label class="proofing-personal__check"><input name="nextcloudEnabled" type="checkbox"<?= $checked((bool)$preferences['notifications']['nextcloud']['enabled']) ?>><span><?= $l->t('Show important updates in Nextcloud') ?></span></label>
			<?php foreach (['upload.received' => $l->t('New client uploads'), 'comment.created' => $l->t('New comments'), 'selection.created' => $l->t('Completed selections')] as $event => $label): ?><label class="proofing-personal__check"><input name="nativeEvent.<?= $event ?>" type="checkbox"<?= $checked(in_array($event, $nativeEvents, true)) ?>><span><?= $label ?></span></label><?php endforeach; ?>
			<label class="proofing-personal__check"><input name="emailEnabled" type="checkbox"<?= $checked((bool)$preferences['notifications']['email']['enabled']) ?>><span><?= $l->t('Also send gallery updates by email') ?></span></label>
			<label><span><?= $l->t('Email delivery') ?></span><select name="emailFrequency"><option value="immediate"<?= $preferences['notifications']['email']['frequency'] === 'immediate' ? ' selected' : '' ?>><?= $l->t('As soon as possible') ?></option><option value="daily"<?= $preferences['notifications']['email']['frequency'] === 'daily' ? ' selected' : '' ?>><?= $l->t('Daily digest') ?></option></select></label>
			<?php foreach (['upload.received' => $l->t('New client uploads'), 'comment.created' => $l->t('New comments'), 'selection.created' => $l->t('Completed selections')] as $event => $label): ?><label class="proofing-personal__check"><input name="emailEvent.<?= $event ?>" type="checkbox"<?= $checked(in_array($event, $emailEvents, true)) ?>><span><?= $label ?></span></label><?php endforeach; ?>
		</div>
		<div class="proofing-personal__section"<?= !$capabilities['lifecycleAutomation']['allowed'] ? ' aria-disabled="true"' : '' ?>>
			<h3><?= $l->t('Lifecycle suggestion') ?></h3>
			<?php if (!$capabilities['lifecycleAutomation']['allowed']): ?><p class="settings-hint"><?= $l->t('Lifecycle automation was disabled by the administrator.') ?></p><?php endif; ?>
			<label class="proofing-personal__check"><input name="lifecycleEnabled" type="checkbox"<?= $checked((bool)$preferences['lifecycle']['enabled']) ?><?= !$capabilities['lifecycleAutomation']['allowed'] ? ' disabled' : '' ?>><span><?= $l->t('Suggest lifecycle automation for new galleries') ?></span></label>
			<div class="proofing-personal__columns"><label><span><?= $l->t('Revoke after completion (days)') ?></span><input name="revokeAfterDays" type="number" min="1" max="3650" value="<?= (int)$preferences['lifecycle']['revokeAfterDays'] ?>"></label><label><span><?= $l->t('Archive after revocation (days)') ?></span><input name="archiveAfterDays" type="number" min="1" max="3650" value="<?= (int)$preferences['lifecycle']['archiveAfterDays'] ?>"></label></div>
		</div>
		<div class="proofing-personal__actions"><button type="submit" class="primary"><?= $l->t('Save changes') ?></button><span role="status"></span></div>
	</form>
</section>
