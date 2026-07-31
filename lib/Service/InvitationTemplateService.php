<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\InvitationTemplate;
use OCA\ProofingGallery\Db\InvitationTemplateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;

final class InvitationTemplateService {
	private const ALLOWED_PLACEHOLDERS = ['gallery', 'owner', 'url'];

	public function __construct(
		private InvitationTemplateMapper $templates,
		private ITimeFactory $clock,
		private IURLGenerator $urlGenerator,
		private IUserManager $users,
	) {
	}

	/** @return list<InvitationTemplate> */
	public function list(string $ownerUid): array {
		return $this->templates->findAllOwned($ownerUid);
	}

	public function create(string $ownerUid, string $name, string $body): InvitationTemplate {
		$name = $this->validateName($ownerUid, $name);
		$body = $this->validateBody($body);
		$now = $this->clock->getTime();
		$template = new InvitationTemplate();
		$template->setOwnerUid($ownerUid);
		$template->setName($name);
		$template->setBody($body);
		$template->setCreatedAt($now);
		$template->setUpdatedAt($now);
		return $this->templates->insert($template);
	}

	public function update(string $ownerUid, int $id, ?string $name, ?string $body): InvitationTemplate {
		$template = $this->templates->findOwned($id, $ownerUid);
		if ($name !== null) {
			$template->setName($this->validateName($ownerUid, $name, $id));
		}
		if ($body !== null) {
			$template->setBody($this->validateBody($body));
		}
		$template->setUpdatedAt($this->clock->getTime());
		return $this->templates->update($template);
	}

	public function delete(string $ownerUid, int $id): void {
		$this->templates->delete($this->templates->findOwned($id, $ownerUid));
	}

	public function render(string $ownerUid, int $id, Gallery $gallery): string {
		$template = $this->templates->findOwned($id, $ownerUid);
		if ($gallery->getOwnerUid() !== $ownerUid || $gallery->getShareToken() === null) {
			throw new InvalidArgumentException('Publish the gallery before applying a template');
		}
		$url = $this->urlGenerator->linkToRouteAbsolute('files_sharing.sharecontroller.showShare', [
			'token' => $gallery->getShareToken(),
		]);
		$owner = $this->users->getDisplayName($ownerUid) ?? $ownerUid;
		return strtr($template->getBody(), [
			'{gallery}' => $gallery->getTitle(),
			'{owner}' => $owner,
			'{url}' => $url,
		]);
	}

	private function validateName(string $ownerUid, string $name, ?int $exceptId = null): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 120) {
			throw new InvalidArgumentException('Template name must contain 1 to 120 characters');
		}
		if ($this->templates->nameExists($ownerUid, $name, $exceptId)) {
			throw new InvalidArgumentException('A template with this name already exists');
		}
		return $name;
	}

	private function validateBody(string $body): string {
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > 2000) {
			throw new InvalidArgumentException('Template body must contain 1 to 2000 characters');
		}
		preg_match_all('/\{([^{}]+)\}/u', $body, $matches);
		foreach ($matches[1] as $placeholder) {
			if (!in_array($placeholder, self::ALLOWED_PLACEHOLDERS, true)) {
				throw new InvalidArgumentException(sprintf('Unknown invitation placeholder: {%s}', $placeholder));
			}
		}
		return $body;
	}
}
