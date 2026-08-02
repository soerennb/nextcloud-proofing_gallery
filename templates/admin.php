<?php

declare(strict_types=1);

$policies = $_['policies'];
$galleryDefaults = $_['galleryDefaults'];
$settings = $_['instanceSettings'];
$features = $settings['features'];
$core = $_['coreSharing'];
$health = $_['health'];
$customDomains = $_['customDomains'];
$cleanup = $health['cleanup'];
$cleanupState = match ($cleanup['state']) {
	'healthy' => $l->t('Healthy'),
	'stale' => $l->t('Overdue'),
	'failed' => $l->t('Failed'),
	default => $l->t('Not run yet'),
};
$checked = static fn (bool $value): string => $value ? ' checked' : '';
$groups = static fn (array $values): string => htmlspecialchars(implode(', ', $values), ENT_QUOTES);
?>
<section id="proofing-gallery-admin" class="settings-section">
	<header class="proofing-settings__header">
		<div>
			<h2><?= $l->t('Proofing Gallery') ?></h2>
			<p><?= $l->t('Control who can publish client galleries and define reliable defaults for every photographer.') ?></p>
		</div>
		<span class="proofing-settings__edition"><?= $l->t('Instance settings') ?></span>
	</header>

	<nav class="proofing-settings__nav" aria-label="<?= $l->t('Proofing Gallery settings') ?>">
		<a href="#proofing-access"><?= $l->t('Access') ?></a>
		<a href="#proofing-defaults"><?= $l->t('Defaults') ?></a>
		<a href="#proofing-video"><?= $l->t('Video') ?></a>
		<a href="#proofing-semantic"><?= $l->t('Semantic search') ?></a>
		<a href="#proofing-live-push"><?= $l->t('Live Push') ?></a>
		<a href="#proofing-domains"><?= $l->t('Domains') ?></a>
		<a href="#proofing-limits"><?= $l->t('Limits') ?></a>
		<a href="#proofing-health"><?= $l->t('System status') ?></a>
		<a href="#proofing-documentation"><?= $l->t('Documentation') ?></a>
	</nav>

	<form class="proofing-gallery-admin__form">
		<section id="proofing-access" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('Access and features') ?></h3><p><?= $l->t('Restrictions are enforced by the server, including for existing galleries.') ?></p></div>
			<div class="proofing-settings__rows">
				<label class="proofing-switch"><span><strong><?= $l->t('Create galleries') ?></strong><small><?= $l->t('Allow permitted users to start new projects.') ?></small></span><input name="feature.galleryCreation" type="checkbox"<?= $checked($features['galleryCreation']) ?>><i aria-hidden="true"></i></label>
				<label class="proofing-switch"><span><strong><?= $l->t('Publish public galleries') ?></strong><small><?= $l->t('Existing public links remain available when this is turned off.') ?></small></span><input name="feature.publicPublishing" type="checkbox"<?= $checked($features['publicPublishing']) ?>><i aria-hidden="true"></i></label>
				<label class="proofing-switch"><span><strong><?= $l->t('Guest uploads') ?></strong><small><?= $l->t('Immediately disables new client uploads when turned off.') ?></small></span><input name="feature.guestUploads" type="checkbox"<?= $checked($features['guestUploads']) ?>><i aria-hidden="true"></i></label>
				<label class="proofing-switch"><span><strong><?= $l->t('Downloads') ?></strong><small><?= $l->t('Immediately blocks all public file and selection downloads.') ?></small></span><input name="feature.downloads" type="checkbox"<?= $checked($features['downloads']) ?>><i aria-hidden="true"></i></label>
				<label class="proofing-switch"><span><strong><?= $l->t('Email invitations') ?></strong><small><?= $l->t('Allow photographers to send gallery links by email.') ?></small></span><input name="feature.emailInvitations" type="checkbox"<?= $checked($features['emailInvitations']) ?>><i aria-hidden="true"></i></label>
				<label class="proofing-switch"><span><strong><?= $l->t('Nextcloud notifications') ?></strong><small><?= $l->t('Show important gallery updates in the Nextcloud notification center.') ?></small></span><input name="feature.nextcloudNotifications" type="checkbox"<?= $checked($features['nextcloudNotifications']) ?>><i aria-hidden="true"></i></label>
				<?php foreach (['likes' => $l->t('Likes'), 'colors' => $l->t('Color workflow'), 'comments' => $l->t('Comments'), 'annotations' => $l->t('Image annotations'), 'selections' => $l->t('Client selections'), 'lifecycleAutomation' => $l->t('Lifecycle automation'), 'ownerCulling' => $l->t('Photographer culling'), 'guestRatings' => $l->t('Client ratings'), 'recursiveGalleries' => $l->t('Recursive galleries'), 'multiplePublicLinks' => $l->t('Multiple public links')] as $key => $label): ?>
					<label class="proofing-switch proofing-switch--compact"><span><strong><?= $label ?></strong></span><input name="feature.<?= $key ?>" type="checkbox"<?= $checked($features[$key]) ?>><i aria-hidden="true"></i></label>
				<?php endforeach; ?>
			</div>
			<div class="proofing-settings__field-grid">
				<label><span><?= $l->t('Groups allowed to create') ?></span><input name="creatorGroups" value="<?= $groups($settings['access']['creatorGroups']) ?>" placeholder="photographers, marketing"><small><?= $l->t('Leave empty to allow all app users. Separate groups with commas.') ?></small></label>
				<label><span><?= $l->t('Groups allowed to publish') ?></span><input name="publisherGroups" value="<?= $groups($settings['access']['publisherGroups']) ?>" placeholder="photographers"><small><?= $l->t('Administrators always retain access.') ?></small></label>
			</div>
			<div class="proofing-settings__inherited">
				<h4><?= $l->t('Inherited Nextcloud sharing rules') ?></h4>
				<dl>
					<div><dt><?= $l->t('Public links') ?></dt><dd><?= $core['publicLinksAllowed'] ? $l->t('Allowed') : $l->t('Disabled') ?></dd></div>
					<div><dt><?= $l->t('Link passwords') ?></dt><dd><?= $core['passwordEnforced'] ? $l->t('Required') : $l->t('Optional') ?></dd></div>
					<div><dt><?= $l->t('Expiration') ?></dt><dd><?= $core['expirationEnforced'] ? $l->t('Required') : ($core['expirationEnabled'] ? $l->t('Enabled') : $l->t('Optional')) ?></dd></div>
					<div><dt><?= $l->t('Public uploads') ?></dt><dd><?= $core['publicUploadsAllowed'] ? $l->t('Allowed') : $l->t('Disabled') ?></dd></div>
				</dl>
				<p><?= $l->t('Proofing Gallery can make these rules stricter, but never weaker.') ?></p>
			</div>
		</section>

		<section id="proofing-video" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('Video delivery') ?></h3><p><?= $l->t('Create browser-ready MP4 derivatives in bounded background jobs. Original files are never changed.') ?></p></div>
			<div class="proofing-settings__rows">
				<label class="proofing-switch"><span><strong><?= $l->t('FFmpeg transcoding') ?></strong><small><?= $l->t('Unsupported camera formats are queued for private server-side conversion.') ?></small></span><input name="videoTranscoding" type="checkbox"<?= $checked($settings['media']['videoTranscoding']) ?>><i aria-hidden="true"></i></label>
			</div>
			<div class="proofing-settings__field-grid proofing-settings__field-grid--numbers">
				<label><span><?= $l->t('FFmpeg executable') ?></span><input name="ffmpegPath" value="<?= htmlspecialchars($settings['media']['ffmpegPath'], ENT_QUOTES) ?>" maxlength="255" spellcheck="false"></label>
				<label><span><?= $l->t('Parallel transcodes') ?></span><input name="transcodeConcurrency" type="number" min="1" max="4" value="<?= (int)$settings['media']['transcodeConcurrency'] ?>"></label>
				<label><span><?= $l->t('Encoding preset') ?></span><select name="transcodePreset"><?php foreach (['veryfast' => $l->t('Fast'), 'medium' => $l->t('Balanced'), 'slow' => $l->t('Quality')] as $value => $label): ?><option value="<?= $value ?>"<?= $settings['media']['transcodePreset'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Maximum source size (MiB)') ?></span><input name="maxVideoInputMiB" type="number" min="1" max="51200" value="<?= (int)($policies['maxVideoInputBytes'] / 1048576) ?>"></label>
				<label><span><?= $l->t('Maximum duration (seconds)') ?></span><input name="maxVideoDurationSeconds" type="number" min="10" max="43200" value="<?= (int)$policies['maxVideoDurationSeconds'] ?>"></label>
				<label><span><?= $l->t('Maximum output height') ?></span><select name="videoMaxHeight"><?php foreach ([720, 1080, 1440, 2160] as $height): ?><option value="<?= $height ?>"<?= $policies['videoMaxHeight'] === $height ? ' selected' : '' ?>><?= $height ?>p</option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Job timeout (seconds)') ?></span><input name="videoTranscodeTimeoutSeconds" type="number" min="30" max="14400" value="<?= (int)$policies['videoTranscodeTimeoutSeconds'] ?>"></label>
				<label><span><?= $l->t('Derivative retention (days)') ?></span><input name="videoDerivativeRetentionDays" type="number" min="1" max="365" value="<?= (int)$policies['videoDerivativeRetentionDays'] ?>"></label>
			</div>
		</section>

		<section id="proofing-semantic" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('Media search') ?></h3><p><?= $l->t('Choose local filename and metadata search, or explicitly opt in to scene search through an HTTPS vision provider.') ?></p></div>
			<div class="proofing-settings__field-grid proofing-settings__field-grid--numbers">
				<label><span><?= $l->t('Provider') ?></span><select name="semanticProvider" aria-label="<?= $l->t('Provider') ?>"><?php foreach (['disabled' => $l->t('Disabled'), 'local' => $l->t('Local metadata'), 'https' => $l->t('HTTPS vision provider')] as $value => $label): ?><option value="<?= $value ?>"<?= $settings['semantic']['provider'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Provider endpoint') ?></span><input name="semanticEndpoint" type="url" maxlength="500" value="<?= htmlspecialchars($settings['semantic']['endpoint'], ENT_QUOTES) ?>" placeholder="https://vision.internal/v1/embed"></label>
				<label><span><?= $l->t('Model') ?></span><input name="semanticModel" maxlength="80" value="<?= htmlspecialchars($settings['semantic']['model'], ENT_QUOTES) ?>"></label>
				<label><span><?= $l->t('Indexing scope') ?></span><select name="semanticScope"><option value="images"<?= $settings['semantic']['scope'] === 'images' ? ' selected' : '' ?>><?= $l->t('Images only') ?></option><option value="images_and_video"<?= $settings['semantic']['scope'] === 'images_and_video' ? ' selected' : '' ?>><?= $l->t('Images and videos') ?></option></select></label>
				<label><span><?= $l->t('Maximum indexed media') ?></span><input name="maxSemanticMedia" type="number" min="100" max="100000" value="<?= (int)$policies['maxSemanticMedia'] ?>"></label>
				<label><span><?= $l->t('Files per semantic job') ?></span><input name="semanticBatchSize" type="number" min="1" max="200" value="<?= (int)$policies['semanticBatchSize'] ?>"></label>
				<label><span><?= $l->t('Maximum provider preview (MiB)') ?></span><input name="semanticPreviewMaxMiB" type="number" min="1" max="8" value="<?= max(1, (int)($policies['semanticPreviewMaxBytes'] / 1048576)) ?>"></label>
			</div>
			<div class="proofing-settings__rows">
				<label class="proofing-switch"><span><strong><?= $l->t('Allow external preview transfer') ?></strong><small><?= $l->t('Required for the HTTPS provider. Reduced previews leave this Nextcloud instance.') ?></small></span><input name="semanticExternalTransfer" type="checkbox"<?= $checked($settings['semantic']['externalTransfer']) ?>><i aria-hidden="true"></i></label>
			</div>
			<div class="proofing-settings__inherited"><p><?= $l->t('Local search matches only filenames and extracted metadata; it does not recognize visual scenes and transfers nothing. HTTPS provider responses are bounded and never include GPS, ratings, private keywords, or originals.') ?></p><button type="button" class="proofing-brand-logo__remove" data-action="delete-semantic-index"><?= $l->t('Delete all media search index data') ?></button></div>
		</section>

		<section id="proofing-live-push" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('HTTPS Live Push') ?></h3><p><?= $l->t('Enable the upload-only HTTPS ingress for cameras or an operator-managed protocol gateway.') ?></p></div>
			<label class="proofing-switch"><span><strong><?= $l->t('Enable Live Push credentials') ?></strong><small><?= $l->t('Existing credentials fail closed immediately when disabled.') ?></small></span><input name="livePushEnabled" type="checkbox"<?= $checked($settings['livePush']['enabled']) ?>><i aria-hidden="true"></i></label>
			<p class="proofing-settings__hint"><?= $l->t('Send file bodies with HTTPS PUT to /apps/proofing_gallery/live-push/upload?filename=… using the generated HTTP Basic credentials. The app does not provide FTP or FTPS; an external gateway may translate a camera protocol. Credentials never grant listing or download access.') ?></p>
		</section>

		<section id="proofing-domains" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('Custom gallery domains') ?></h3><p><?= $l->t('Approve only domains whose DNS challenge and HTTPS endpoint both validate.') ?></p></div>
			<label class="proofing-switch"><span><strong><?= $l->t('Allow custom domain requests') ?></strong><small><?= $l->t('Disabling requests does not bypass revocation or public-link access checks.') ?></small></span><input name="customDomainsEnabled" type="checkbox"<?= $checked($settings['customDomains']['enabled']) ?>><i aria-hidden="true"></i></label>
			<div class="proofing-domains">
				<?php if ($customDomains === []): ?><p><?= $l->t('No custom domains requested.') ?></p><?php endif; ?>
				<?php foreach ($customDomains as $domain): ?><article data-domain-row="<?= (int)$domain['id'] ?>">
					<div><strong><?= htmlspecialchars((string)$domain['domain'], ENT_QUOTES) ?></strong><span><?= htmlspecialchars((string)($domain['galleryTitle'] ?? ''), ENT_QUOTES) ?> · <?= htmlspecialchars((string)($domain['linkName'] ?? ''), ENT_QUOTES) ?></span></div>
					<span class="proofing-domains__status"><?= $domain['status'] === 'verified' ? $l->t('Verified') : ($domain['status'] === 'revoked' ? $l->t('Revoked') : $l->t('Pending')) ?></span>
					<code><?= htmlspecialchars((string)$domain['verificationName'], ENT_QUOTES) ?> TXT <?= htmlspecialchars((string)$domain['verificationValue'], ENT_QUOTES) ?></code>
					<div class="proofing-domains__actions"><?php if ($domain['status'] !== 'revoked'): ?><button type="button" data-action="verify-domain" data-domain-id="<?= (int)$domain['id'] ?>"><?= $l->t('Verify DNS and TLS') ?></button><button type="button" data-action="revoke-domain" data-domain-id="<?= (int)$domain['id'] ?>"><?= $l->t('Revoke') ?></button><?php endif; ?></div>
				</article><?php endforeach; ?>
			</div>
		</section>

		<section id="proofing-defaults" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('Defaults for new galleries') ?></h3><p><?= $l->t('Existing galleries keep their current configuration.') ?></p></div>
			<div class="proofing-settings__field-grid">
				<label><span><?= $l->t('Default purpose') ?></span><select name="defaultPurpose"><?php foreach (['delivery' => $l->t('Deliver photos'), 'showcase' => $l->t('Show photos'), 'selection' => $l->t('Collect a selection'), 'proofing' => $l->t('Review together'), 'uploads' => $l->t('Receive files'), 'custom' => $l->t('Custom')] as $value => $label): ?><option value="<?= $value ?>"<?= $settings['workflow']['defaultPurpose'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Public language') ?></span><select name="defaultPublicLocale"><?php foreach (['auto' => $l->t('Automatic'), 'de' => 'Deutsch', 'en' => 'English'] as $value => $label): ?><option value="<?= $value ?>"<?= $galleryDefaults['publicLocale'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Theme') ?></span><select name="defaultTheme"><?php foreach (['auto' => $l->t('Automatic'), 'light' => $l->t('Light'), 'dark' => $l->t('Dark')] as $value => $label): ?><option value="<?= $value ?>"<?= $galleryDefaults['presentation']['theme'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Layout') ?></span><select name="defaultLayout"><?php foreach (['grid' => $l->t('Grid'), 'masonry' => $l->t('Masonry'), 'list' => $l->t('List')] as $value => $label): ?><option value="<?= $value ?>"<?= $galleryDefaults['presentation']['layout'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Downloads') ?></span><select name="defaultDownloadScope"><?php foreach (['none' => $l->t('Disabled'), 'individual' => $l->t('Individual files'), 'selection' => $l->t('Saved selections'), 'all' => $l->t('Files and selections')] as $value => $label): ?><option value="<?= $value ?>"<?= $galleryDefaults['delivery']['downloadScope'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
				<label><span><?= $l->t('Studio name') ?></span><input name="studioName" maxlength="120" value="<?= htmlspecialchars($settings['branding']['studioName'], ENT_QUOTES) ?>"></label>
				<label><span><?= $l->t('Accent color') ?></span><span class="proofing-color"><input name="accentColor" type="color" value="<?= htmlspecialchars($settings['branding']['accentColor'], ENT_QUOTES) ?>"><code><?= htmlspecialchars($settings['branding']['accentColor'], ENT_QUOTES) ?></code></span></label>
				<div class="proofing-brand-logo">
					<label><span><?= $l->t('Default studio logo') ?></span><input name="brandLogo" type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml"><small data-brand-logo-status><?= $settings['branding']['logoAssetId'] === null ? $l->t('No instance logo uploaded') : $l->t('An instance logo is active. Upload a file to replace it.') ?></small></label>
					<?php if ($settings['branding']['logoAssetId'] !== null): ?><button type="button" class="proofing-brand-logo__remove" data-action="remove-logo"><?= $l->t('Remove studio logo') ?></button><?php endif; ?>
				</div>
			</div>
		</section>

		<section id="proofing-limits" class="proofing-settings__section">
			<div class="proofing-settings__section-title"><h3><?= $l->t('Limits and retention') ?></h3><p><?= $l->t('Bound uploads, generated archives and temporary server data.') ?></p></div>
			<div class="proofing-settings__field-grid proofing-settings__field-grid--numbers">
				<?php foreach ([
					'maxUploadMiB' => [$l->t('Maximum guest upload (MiB)'), (int)($policies['maxUploadBytes'] / 1048576), 1, 20480],
					'maxSelectionFiles' => [$l->t('Maximum files per delivery'), $policies['maxSelectionFiles'], 1, 1000],
					'maxSelectionMiB' => [$l->t('Maximum delivery size (MiB)'), (int)($policies['maxSelectionBytes'] / 1048576), 1, 20480],
					'eventRetentionDays' => [$l->t('Activity history (days)'), $policies['eventRetentionDays'], 7, 3650],
					'previewRetentionDays' => [$l->t('Watermarked previews (days)'), $policies['previewRetentionDays'], 1, 365],
					'pendingUploadRetentionHours' => [$l->t('Incomplete uploads (hours)'), $policies['pendingUploadRetentionHours'], 1, 168],
					'completedUploadRetentionDays' => [$l->t('Moderated upload records (days)'), $policies['completedUploadRetentionDays'], 7, 3650],
					'maxVersionsPerFile' => [$l->t('Maximum archived versions'), $policies['maxVersionsPerFile'], 1, 100],
					'versionRetentionDays' => [$l->t('Archived versions (days)'), $policies['versionRetentionDays'], 1, 3650],
					'metadataMaxMiB' => [$l->t('Metadata file size (MiB)'), (int)($policies['metadataMaxBytes'] / 1048576), 1, 512],
					'metadataBatchSize' => [$l->t('Files per metadata run'), $policies['metadataBatchSize'], 1, 200],
					'maxIndexedMedia' => [$l->t('Maximum indexed media per gallery'), $policies['maxIndexedMedia'], 100, 100000],
					'maxPublicLinks' => [$l->t('Maximum public links per gallery'), $policies['maxPublicLinks'], 1, 100],
					'shareAuditRetentionDays' => [$l->t('Public link audit history (days)'), $policies['shareAuditRetentionDays'], 7, 3650],
					'maxLivePushCredentials' => [$l->t('Live Push credentials per gallery'), $policies['maxLivePushCredentials'], 1, 20],
					'maxCustomDomainsPerGallery' => [$l->t('Custom domains per gallery'), $policies['maxCustomDomainsPerGallery'], 1, 20],
				] as $name => [$label, $value, $min, $max]): ?><label><span><?= $label ?></span><input name="<?= $name ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" value="<?= (int)$value ?>"></label><?php endforeach; ?>
				<label><span><?= $l->t('XMP sidecar writing') ?></span><select name="xmpWritingEnabled"><option value="1"<?= $policies['xmpWritingEnabled'] === 1 ? ' selected' : '' ?>><?= $l->t('Enabled') ?></option><option value="0"<?= $policies['xmpWritingEnabled'] === 0 ? ' selected' : '' ?>><?= $l->t('Disabled') ?></option></select></label>
			</div>
		</section>

		<div class="proofing-gallery-admin__save-bar"><span class="proofing-gallery-admin__dirty"><?= $l->t('No unsaved changes') ?></span><button type="submit" class="primary"><?= $l->t('Save changes') ?></button><span class="proofing-gallery-admin__status" role="status"></span></div>
	</form>

	<section id="proofing-health" class="proofing-settings__section proofing-settings__health">
		<div class="proofing-settings__section-title"><h3><?= $l->t('System status') ?></h3><p><?= $l->t('Operational signals from background cleanup and client uploads.') ?></p></div>
		<dl><div><dt><?= $l->t('Cleanup status') ?></dt><dd><?= $cleanupState ?></dd></div><div><dt><?= $l->t('FFmpeg') ?></dt><dd><?= $health['video']['available'] ? $l->t('Available') : $l->t('Unavailable') ?></dd></div><div><dt><?= $l->t('Videos waiting') ?></dt><dd><?= (int)$health['video']['pending'] ?></dd></div><div><dt><?= $l->t('Failed video jobs') ?></dt><dd><?= (int)$health['video']['failed'] ?></dd></div><div><dt><?= $l->t('Video derivatives') ?></dt><dd><?= \OCP\Util::humanFileSize((int)$health['video']['bytes']) ?></dd></div><div><dt><?= $l->t('Semantic media') ?></dt><dd><?= (int)$health['semantic']['items'] ?></dd></div><div><dt><?= $l->t('Semantic galleries') ?></dt><dd><?= (int)$health['semantic']['galleries'] ?></dd></div><div><dt><?= $l->t('Active Live Push credentials') ?></dt><dd><?= (int)$health['livePush']['active'] ?></dd></div><div><dt><?= $l->t('Live Push uploads') ?></dt><dd><?= (int)$health['livePush']['uploads'] ?></dd></div><div><dt><?= $l->t('Notification center') ?></dt><dd><?= $health['notifications']['available'] ? $l->t('Available') : $l->t('Unavailable') ?></dd></div><div><dt><?= $l->t('Pending notifications') ?></dt><dd><?= (int)$health['notifications']['pending'] ?></dd></div><div><dt><?= $l->t('Failed notifications') ?></dt><dd><?= (int)$health['notifications']['failed'] ?></dd></div><div><dt><?= $l->t('Incomplete uploads') ?></dt><dd><?= (int)$health['pendingUploads'] ?></dd></div><div><dt><?= $l->t('Uploads awaiting review') ?></dt><dd><?= (int)$health['awaitingReview'] ?></dd></div><div><dt><?= $l->t('Preview cache') ?></dt><dd><?= \OCP\Util::humanFileSize((int)$health['previewCacheBytes']) ?></dd></div></dl>
	</section>

	<section id="proofing-documentation" class="proofing-settings__section proofing-settings__documentation">
		<div class="proofing-settings__section-title">
			<div><h3><?= $l->t('Administrator documentation') ?></h3><p><?= $l->t('This guide is included with the app and remains available without internet access.') ?></p></div>
			<div class="proofing-documentation__languages" aria-label="<?= $l->t('Documentation language') ?>">
				<button type="button" data-documentation-language="en" aria-pressed="false">English</button>
				<button type="button" data-documentation-language="de" aria-pressed="false">Deutsch</button>
			</div>
		</div>
		<article class="proofing-documentation__content" data-admin-documentation></article>
	</section>
</section>
