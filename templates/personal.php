<?php

declare(strict_types=1);

$presets = array_map(static fn ($preset): array => ['id' => $preset->getId(), 'name' => $preset->getName()], $_['presets'] ?? []);
$state = json_encode([
	'preferences' => $_['preferences'],
	'capabilities' => $_['capabilities'],
	'instanceSettings' => $_['instanceSettings'],
	'presets' => $presets,
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section id="proofing-gallery-personal" data-state="<?= htmlspecialchars($state, ENT_QUOTES) ?>"></section>
