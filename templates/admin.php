<?php

declare(strict_types=1);

$policies = $_['policies'];
$galleryDefaults = $_['galleryDefaults'];
$health = $_['health'];
$cleanupHealth = $health['cleanup'];
$cleanup = json_decode((string)$cleanupHealth['lastResult'], true);
$cleanupSummary = is_array($cleanup)
	? sprintf(
		'%s: %d · %s: %d · %s: %d · %s: %d · %s: %d · %s: %d',
		$l->t('events'),
		(int)($cleanup['events'] ?? 0),
		$l->t('uploads'),
		(int)($cleanup['uploads'] ?? 0),
		$l->t('previews'),
		(int)($cleanup['previews'] ?? 0),
		$l->t('versions'),
		(int)($cleanup['versions'] ?? 0),
		$l->t('orphan records'),
		(int)($cleanup['orphans'] ?? 0),
		$l->t('collection anchors'),
		(int)($cleanup['collectionAnchors'] ?? 0),
	)
	: $l->t('Not run yet');
$cleanupState = match ($cleanupHealth['state']) {
	'healthy' => $l->t('Healthy'),
	'stale' => $l->t('Overdue'),
	'failed' => $l->t('Failed'),
	default => $l->t('Not run yet'),
};
?>
<section id="proofing-gallery-admin" class="settings-section">
	<h2><?= $l->t('Proofing Gallery') ?></h2>
	<p class="settings-hint"><?= $l->t('Set delivery limits and automatic retention for all galleries on this server.') ?></p>

	<form class="proofing-gallery-admin__form">
		<fieldset>
		<legend><?= $l->t('New gallery defaults') ?></legend>
		<p class="proofing-gallery-admin__hint"><?= $l->t('These choices apply to newly created galleries. Existing galleries keep their settings.') ?></p>
		<p><label for="proofing-default-locale"><?= $l->t('Public language') ?></label>
			<select id="proofing-default-locale" name="defaultPublicLocale">
				<?php foreach (['auto' => $l->t('Automatic'), 'en' => 'English', 'de' => 'Deutsch'] as $value => $label): ?>
					<option value="<?= $value ?>" <?= $galleryDefaults['publicLocale'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="proofing-default-theme"><?= $l->t('Theme') ?></label>
			<select id="proofing-default-theme" name="defaultTheme">
				<?php foreach (['auto' => $l->t('Automatic'), 'light' => $l->t('Light'), 'dark' => $l->t('Dark')] as $value => $label): ?>
					<option value="<?= $value ?>" <?= $galleryDefaults['presentation']['theme'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="proofing-default-layout"><?= $l->t('Layout') ?></label>
			<select id="proofing-default-layout" name="defaultLayout">
				<?php foreach (['grid' => $l->t('Grid'), 'masonry' => $l->t('Masonry'), 'list' => $l->t('List')] as $value => $label): ?>
					<option value="<?= $value ?>" <?= $galleryDefaults['presentation']['layout'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select></p>
		<p><label for="proofing-default-downloads"><?= $l->t('Downloads') ?></label>
			<select id="proofing-default-downloads" name="defaultDownloadScope">
				<?php foreach (['none' => $l->t('Disabled'), 'individual' => $l->t('Individual files'), 'selection' => $l->t('Saved selections'), 'all' => $l->t('Files and selections')] as $value => $label): ?>
					<option value="<?= $value ?>" <?= $galleryDefaults['delivery']['downloadScope'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select></p>
		</fieldset>

		<fieldset>
		<legend><?= $l->t('Delivery limits') ?></legend>
		<p class="proofing-gallery-admin__hint"><?= $l->t('Protect server capacity by limiting uploads and generated delivery archives.') ?></p>
		<p>
			<label for="proofing-max-upload"><?= $l->t('Maximum guest upload (MiB)') ?></label>
			<input id="proofing-max-upload" name="maxUploadMiB" type="number" min="1" max="20480"
				value="<?= (int)($policies['maxUploadBytes'] / 1048576) ?>">
		</p>
		<p>
			<label for="proofing-selection-files"><?= $l->t('Maximum files per delivery') ?></label>
			<input id="proofing-selection-files" name="maxSelectionFiles" type="number" min="1" max="1000"
				value="<?= (int)$policies['maxSelectionFiles'] ?>">
		</p>
		<p>
			<label for="proofing-selection-size"><?= $l->t('Maximum delivery size (MiB)') ?></label>
			<input id="proofing-selection-size" name="maxSelectionMiB" type="number" min="1" max="20480"
				value="<?= (int)($policies['maxSelectionBytes'] / 1048576) ?>">
		</p>
		</fieldset>

		<fieldset>
		<legend><?= $l->t('Retention') ?></legend>
		<p class="proofing-gallery-admin__hint"><?= $l->t('Expired temporary data is removed during the scheduled cleanup job.') ?></p>
		<p><label for="proofing-events"><?= $l->t('Activity history (days)') ?></label>
			<input id="proofing-events" name="eventRetentionDays" type="number" min="7" max="3650" value="<?= (int)$policies['eventRetentionDays'] ?>"></p>
		<p><label for="proofing-previews"><?= $l->t('Watermarked previews (days)') ?></label>
			<input id="proofing-previews" name="previewRetentionDays" type="number" min="1" max="365" value="<?= (int)$policies['previewRetentionDays'] ?>"></p>
		<p><label for="proofing-pending"><?= $l->t('Incomplete uploads (hours)') ?></label>
			<input id="proofing-pending" name="pendingUploadRetentionHours" type="number" min="1" max="168" value="<?= (int)$policies['pendingUploadRetentionHours'] ?>"></p>
		<p><label for="proofing-completed"><?= $l->t('Moderated upload records (days)') ?></label>
			<input id="proofing-completed" name="completedUploadRetentionDays" type="number" min="7" max="3650" value="<?= (int)$policies['completedUploadRetentionDays'] ?>"></p>
		<p><label for="proofing-version-count"><?= $l->t('Maximum archived versions per file') ?></label>
			<input id="proofing-version-count" name="maxVersionsPerFile" type="number" min="1" max="100" value="<?= (int)$policies['maxVersionsPerFile'] ?>"></p>
		<p><label for="proofing-version-days"><?= $l->t('Archived file versions (days)') ?></label>
			<input id="proofing-version-days" name="versionRetentionDays" type="number" min="1" max="3650" value="<?= (int)$policies['versionRetentionDays'] ?>"></p>
		</fieldset>

		<fieldset>
		<legend><?= $l->t('Photo metadata') ?></legend>
		<p class="proofing-gallery-admin__hint"><?= $l->t('Bound embedded metadata processing and control whether owners may create XMP sidecars next to originals.') ?></p>
		<p><label for="proofing-metadata-size"><?= $l->t('Maximum file size for metadata (MiB)') ?></label>
			<input id="proofing-metadata-size" name="metadataMaxMiB" type="number" min="1" max="512" value="<?= (int)($policies['metadataMaxBytes'] / 1048576) ?>"></p>
		<p><label for="proofing-metadata-batch"><?= $l->t('Maximum files per metadata run') ?></label>
			<input id="proofing-metadata-batch" name="metadataBatchSize" type="number" min="1" max="200" value="<?= (int)$policies['metadataBatchSize'] ?>"></p>
		<p><label for="proofing-xmp-writing"><?= $l->t('XMP sidecar writing') ?></label>
			<select id="proofing-xmp-writing" name="xmpWritingEnabled">
				<option value="1" <?= $policies['xmpWritingEnabled'] === 1 ? 'selected' : '' ?>><?= $l->t('Enabled') ?></option>
				<option value="0" <?= $policies['xmpWritingEnabled'] === 0 ? 'selected' : '' ?>><?= $l->t('Disabled') ?></option>
			</select></p>
		</fieldset>

		<div class="proofing-gallery-admin__save-bar">
			<span class="proofing-gallery-admin__dirty"><?= $l->t('No unsaved changes') ?></span>
			<button type="submit" class="primary"><?= $l->t('Save changes') ?></button>
			<span class="proofing-gallery-admin__status" role="status"></span>
		</div>
	</form>

	<section class="proofing-gallery-admin__health-section">
	<h3><?= $l->t('Health') ?></h3>
	<dl class="proofing-gallery-admin__health">
		<div><dt><?= $l->t('Cleanup status') ?></dt><dd class="proofing-gallery-admin__state proofing-gallery-admin__state--<?= htmlspecialchars($cleanupHealth['state'], ENT_QUOTES) ?>"><?= $cleanupState ?></dd></div>
		<div><dt><?= $l->t('Incomplete uploads') ?></dt><dd><?= (int)$health['pendingUploads'] ?></dd></div>
		<div><dt><?= $l->t('Uploads awaiting review') ?></dt><dd><?= (int)$health['awaitingReview'] ?></dd></div>
		<div><dt><?= $l->t('Preview cache') ?></dt><dd><?= \OCP\Util::humanFileSize((int)$health['previewCacheBytes']) ?></dd></div>
		<div><dt><?= $l->t('Last cleanup attempt') ?></dt><dd><?= $cleanupHealth['lastAttemptAt'] === null ? $l->t('Not run yet') : $l->l('datetime', (int)$cleanupHealth['lastAttemptAt']) ?></dd></div>
		<div><dt><?= $l->t('Last successful cleanup') ?></dt><dd><?= $cleanupHealth['lastSuccessAt'] === null ? $l->t('Not run yet') : $l->l('datetime', (int)$cleanupHealth['lastSuccessAt']) ?></dd></div>
		<div><dt><?= $l->t('Last cleanup result') ?></dt><dd><?= htmlspecialchars($cleanupSummary, ENT_QUOTES) ?></dd></div>
		<?php if ($cleanupHealth['errorCode'] !== null): ?>
			<div><dt><?= $l->t('Last cleanup error') ?></dt><dd><code><?= htmlspecialchars($cleanupHealth['errorCode'], ENT_QUOTES) ?></code></dd></div>
		<?php endif; ?>
	</dl>
	</section>
</section>
