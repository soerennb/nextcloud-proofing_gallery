<?php

declare(strict_types=1);

$policies = $_['policies'];
$health = $_['health'];
$cleanupHealth = $health['cleanup'];
$cleanup = json_decode((string)$cleanupHealth['lastResult'], true);
$cleanupSummary = is_array($cleanup)
	? sprintf(
		'%s: %d · %s: %d · %s: %d · %s: %d',
		$l->t('events'),
		(int)($cleanup['events'] ?? 0),
		$l->t('uploads'),
		(int)($cleanup['uploads'] ?? 0),
		$l->t('previews'),
		(int)($cleanup['previews'] ?? 0),
		$l->t('orphan records'),
		(int)($cleanup['orphans'] ?? 0),
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
		<h3><?= $l->t('Delivery limits') ?></h3>
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

		<h3><?= $l->t('Retention') ?></h3>
		<p><label for="proofing-events"><?= $l->t('Activity history (days)') ?></label>
			<input id="proofing-events" name="eventRetentionDays" type="number" min="7" max="3650" value="<?= (int)$policies['eventRetentionDays'] ?>"></p>
		<p><label for="proofing-previews"><?= $l->t('Watermarked previews (days)') ?></label>
			<input id="proofing-previews" name="previewRetentionDays" type="number" min="1" max="365" value="<?= (int)$policies['previewRetentionDays'] ?>"></p>
		<p><label for="proofing-pending"><?= $l->t('Incomplete uploads (hours)') ?></label>
			<input id="proofing-pending" name="pendingUploadRetentionHours" type="number" min="1" max="168" value="<?= (int)$policies['pendingUploadRetentionHours'] ?>"></p>
		<p><label for="proofing-completed"><?= $l->t('Moderated upload records (days)') ?></label>
			<input id="proofing-completed" name="completedUploadRetentionDays" type="number" min="7" max="3650" value="<?= (int)$policies['completedUploadRetentionDays'] ?>"></p>

		<button type="submit" class="primary"><?= $l->t('Save') ?></button>
		<span class="proofing-gallery-admin__status" role="status"></span>
	</form>

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
