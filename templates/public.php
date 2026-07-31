<?php

declare(strict_types=1);
?>
<?php if (is_string($_['preloadImage'] ?? null)): ?>
	<link rel="preload" as="image" href="<?= htmlspecialchars($_['preloadImage'], ENT_QUOTES) ?>" fetchpriority="high">
<?php endif; ?>
<div id="proofing_gallery_public"></div>
