<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\PublicShareContext;
use OCP\Files\File;
use OCP\Files\NotFoundException;

final class PublicMediaResolver {
	public function __construct(
		private CollectionService $collections,
		private MediaTypePolicy $mediaTypes,
	) {
	}

	public function resolve(PublicShareContext $context, int $fileId): File {
		if ($context->gallery->getSourceType() === 'collection') {
			if ($context->link->getStartPath() !== '') throw new NotFoundException('Media file not found');
			try {
				$file = $this->collections->resolveMedia($context->gallery, $fileId);
			} catch (\Throwable) {
				throw new NotFoundException('Media file not found');
			}
			if (!$this->mediaTypes->supports($file)) throw new NotFoundException('Media file not found');
			return $file;
		}
		foreach ($context->root->getById($fileId) as $node) {
			if ($node instanceof File && $context->root->isSubNode($node) && $this->mediaTypes->supports($node)) return $node;
		}
		throw new NotFoundException('Media file not found');
	}

	public function allows(PublicShareContext $context, int $fileId): bool {
		try {
			$this->resolve($context, $fileId);
			return true;
		} catch (NotFoundException) {
			return false;
		}
	}
}
