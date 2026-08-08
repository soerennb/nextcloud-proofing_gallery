<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\SetupCheck;

use OCA\ProofingGallery\Service\PolicyService;
use OCA\ProofingGallery\Service\VideoTranscodeService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

final class RuntimeDependenciesCheck implements ISetupCheck {
	private const DOCS = 'https://soerennb.github.io/nextcloud-proofing_gallery/en/admin-guide/';

	public function __construct(
		private PolicyService $policies,
		private VideoTranscodeService $video,
		private IL10N $l10n,
	) {
	}

	public function getCategory(): string {
		return 'system';
	}

	public function getName(): string {
		return $this->l10n->t('Proofing Gallery media runtime');
	}

	public function run(): SetupResult {
		if (!extension_loaded('gd')) {
			return SetupResult::error($this->l10n->t('The PHP GD extension is required for image processing.'), self::DOCS);
		}
		if ($this->policies->videoSettings()['enabled'] && !$this->video->availability()['available']) {
			return SetupResult::warning($this->l10n->t('Video transcoding is enabled, but FFmpeg is unavailable.'), self::DOCS);
		}
		return SetupResult::success($this->l10n->t('Proofing Gallery media dependencies are available.'));
	}
}
