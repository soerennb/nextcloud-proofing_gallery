<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Service\SemanticSearchService;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

final class IndexSemanticGalleryJob extends QueuedJob {
	public function __construct(private GalleryMapper $galleries, private SemanticSearchService $semantic, private LoggerInterface $logger) {
	}

	/** @param mixed $argument */
	protected function run($argument): void {
		try {
			$galleryId = (int)($argument['galleryId'] ?? 0);
			$generation = (string)($argument['generation'] ?? '');
			$provider = (string)($argument['provider'] ?? '');
			$model = (string)($argument['model'] ?? '');
			if ($galleryId > 0 && $generation !== '' && $provider !== '' && $model !== '') {
				$this->semantic->indexBatch($this->galleries->find($galleryId), $generation, $provider, $model, max(0, (int)($argument['offset'] ?? 0)), max(0, (int)($argument['attempt'] ?? 0)));
			}
		} catch (\Throwable $exception) {
			$this->logger->warning('Semantic gallery indexing failed', ['exception' => $exception]);
		}
	}
}
