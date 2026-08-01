<?php

declare(strict_types=1);
?>
<?php if (is_string($_['lcpPreviewUrl'] ?? null)): ?>
	<div id="proofing-gallery-server-preview" aria-hidden="true">
		<img src="<?= htmlspecialchars($_['lcpPreviewUrl'], ENT_QUOTES) ?>" alt="" width="1280" height="900" fetchpriority="high">
	</div>
<?php endif; ?>
<div id="proofing_gallery_public"></div>
