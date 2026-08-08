<?php

declare(strict_types=1);

$state = json_encode([
	'instanceSettings' => $_['instanceSettings'],
	'policies' => $_['policies'],
	'galleryDefaults' => $_['galleryDefaults'],
	'coreSharing' => $_['coreSharing'],
	'health' => $_['health'],
	'retentionConfiguration' => $_['retentionConfiguration'],
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section id="proofing-gallery-admin" data-state="<?= htmlspecialchars($state, ENT_QUOTES) ?>"></section>
