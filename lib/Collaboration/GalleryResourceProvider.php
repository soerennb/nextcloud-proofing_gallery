<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Collaboration;

use OCA\ProofingGallery\Exception\AuthorizationException;
use OCA\ProofingGallery\Service\IntegrationReadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Resources\IProvider;
use OCP\Collaboration\Resources\IResource;
use OCP\Collaboration\Resources\ResourceException;
use OCP\IUser;

final class GalleryResourceProvider implements IProvider {
	public const RESOURCE_TYPE = 'proofing-gallery';

	public function __construct(private IntegrationReadService $read, private ?string $userId) {
	}

	public function getType(): string {
		return self::RESOURCE_TYPE;
	}

	/** @return array<string, mixed> */
	public function getResourceRichObject(IResource $resource): array {
		$galleryId = filter_var($resource->getId(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($galleryId === false || $this->userId === null) throw new ResourceException('Invalid gallery resource');
		try {
			$gallery = $this->read->galleryById($this->userId, (int)$galleryId);
		} catch (DoesNotExistException|AuthorizationException) {
			throw new ResourceException('Gallery not found');
		}
		return [
			'type' => self::RESOURCE_TYPE,
			'id' => (string)$galleryId,
			'name' => $gallery['title'],
			'link' => $gallery['internalUrl'],
			'icon' => 'icon-picture',
		];
	}

	public function canAccessResource(IResource $resource, ?IUser $user): bool {
		if ($user === null || !ctype_digit($resource->getId())) return false;
		try {
			$this->read->galleryById($user->getUID(), (int)$resource->getId());
			return true;
		} catch (DoesNotExistException|AuthorizationException) {
			return false;
		}
	}
}
