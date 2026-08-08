<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\CustomDomainRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

final class CustomDomainService {
	public function __construct(
		private CustomDomainRepository $repository,
		private PublicLinkMapper $links,
		private PolicyService $policies,
		private CustomDomainVerifier $verifier,
		private ISecureRandom $random,
		private ITimeFactory $clock,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function gallery(Gallery $gallery): array {
		return array_map($this->present(...), $this->repository->gallery((int)$gallery->getId()));
	}

	/** @return array{items:list<array<string,mixed>>,total:int,nextCursor:?string} */
	public function adminPage(int $limit, ?string $cursor, string $status, string $search, ScopedCursorCodec $cursors): array {
		$limit = max(1, min(100, $limit));
		$status = mb_strtolower(trim($status));
		if (!in_array($status, ['active', 'pending', 'verified', 'revoked', 'all'], true)) {
			throw new \InvalidArgumentException('Invalid domain status');
		}
		$search = mb_substr(trim($search), 0, 253);
		$scope = 'admin-domains:' . hash('sha256', $status . "\0" . mb_strtolower($search));
		$rows = $this->repository->adminPage($cursors->decode($cursor, $scope), $limit + 1, $status, $search);
		$hasMore = count($rows) > $limit;
		if ($hasMore) array_pop($rows);
		$last = end($rows);
		return [
			'items' => array_map($this->present(...), $rows),
			'total' => $this->repository->adminCount($status, $search),
			'nextCursor' => $hasMore && is_array($last) ? $cursors->encode($scope, (int)$last['id']) : null,
		];
	}

	/** @return array<string, mixed> */
	public function request(Gallery $gallery, int $linkId, string $domain, string $userId): array {
		if (!$this->policies->customDomainsEnabled()) throw new \InvalidArgumentException('Custom domains are disabled by the administrator');
		$domain = $this->normalize($domain);
		try { $link = $this->links->find($linkId); } catch (DoesNotExistException) { throw new \InvalidArgumentException('Public link not found'); }
		if ($link->getGalleryId() !== $gallery->getId() || $link->getStatus() !== 'active') throw new \InvalidArgumentException('Public link not found');
		$active = array_filter($this->repository->gallery((int)$gallery->getId()), static fn (array $row): bool => $row['status'] !== 'revoked');
		if (count($active) >= $this->policies->get('maxCustomDomainsPerGallery')) throw new \InvalidArgumentException('Custom domain limit reached');
		if ($this->repository->activeLink($linkId) !== null) throw new \InvalidArgumentException('This public link already has a custom domain');
		$token = 'proofing-gallery-verification=' . $this->random->generate(40, ISecureRandom::CHAR_ALPHANUMERIC);
		$id = $this->repository->create((int)$gallery->getId(), $linkId, $domain, $token, $userId, $this->clock->getTime());
		return $this->present($this->repository->find($id) ?? throw new \RuntimeException('Custom domain request could not be read'));
	}

	/** @return array<string, mixed> */
	public function verify(int $id): array {
		$row = $this->repository->find($id) ?? throw new \InvalidArgumentException('Custom domain not found');
		if ($row['status'] === 'revoked') throw new \InvalidArgumentException('Custom domain is revoked');
		$result = $this->verifier->verify((string)$row['domain'], (string)$row['verification_token']);
		$now = $this->clock->getTime();
		if (!$this->repository->verificationResult($id, $result['verified'], $result['error'], $now)) {
			throw new \InvalidArgumentException('Custom domain is revoked');
		}
		if (!$result['verified']) throw new \InvalidArgumentException($result['error'] === 'dns_token_missing' ? 'DNS verification record was not found' : 'A valid HTTPS endpoint was not reachable');
		$row['status'] = 'verified';
		$row['last_error'] = null;
		$row['checked_at'] = $now;
		$row['verified_at'] = $now;
		return $this->present($row);
	}

	public function revoke(int $id, ?Gallery $gallery = null): void {
		if (!$this->repository->revoke($id, $this->clock->getTime(), $gallery?->getId())) throw new \InvalidArgumentException('Custom domain not found');
	}

	/** @return array<string, mixed>|null */
	public function resolve(string $host): ?array {
		$host = mb_strtolower(explode(':', trim($host))[0]);
		$row = $this->repository->verifiedDomain($host, $this->clock->getTime() - 86400);
		return $row === null ? null : $this->present($row);
	}

	public function revalidateDue(int $limit = 100): int {
		$checked = 0;
		foreach ($this->repository->dueForVerification($this->clock->getTime() - 21600, $limit) as $row) {
			$result = $this->verifier->verify((string)$row['domain'], (string)$row['verification_token']);
			$this->repository->verificationResult((int)$row['id'], $result['verified'], $result['error'], $this->clock->getTime());
			$checked++;
		}
		return $checked;
	}

	/** @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function present(array $row): array {
		return [
			'id' => (int)$row['id'], 'galleryId' => (int)$row['gallery_id'], 'publicLinkId' => (int)$row['public_link_id'],
			'domain' => (string)$row['domain'], 'status' => (string)$row['status'],
			'verificationName' => '_proofing-gallery.' . $row['domain'], 'verificationValue' => (string)$row['verification_token'],
			'lastError' => $row['last_error'] ?? null, 'createdAt' => (int)$row['created_at'],
			'checkedAt' => ($row['checked_at'] ?? null) === null ? null : (int)$row['checked_at'],
			'verifiedAt' => ($row['verified_at'] ?? null) === null ? null : (int)$row['verified_at'],
			'url' => $row['status'] === 'verified' ? 'https://' . $row['domain'] . '/' : null,
			'galleryTitle' => $row['title'] ?? null, 'linkName' => $row['name'] ?? null, 'token' => $row['token'] ?? null,
		];
	}

	private function normalize(string $domain): string {
		$domain = mb_strtolower(rtrim(trim($domain), '.'));
		$reserved = preg_match('/\.(?:local|localhost|internal|invalid|test)$/', $domain) === 1 || $domain === 'localhost';
		if ($reserved || !str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_IP) !== false
			|| filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || mb_strlen($domain) > 253) {
			throw new \InvalidArgumentException('Enter a valid public domain name');
		}
		return $domain;
	}
}
