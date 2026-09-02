<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Dto\PublicLinkConfiguration;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;

final class EventDeliveryService {
	public function __construct(
		private IDBConnection $db,
		private FolderService $folders,
		private MediaTypePolicy $mediaTypes,
		private PublicLinkManagerService $links,
		private PublicLinkPolicyService $policies,
		private InvitationService $invitations,
		private ITimeFactory $clock,
		private ICrypto $crypto,
	) {
	}

	/** @return array{folders: list<array<string, mixed>>, suggested: bool} */
	public function preview(Gallery $gallery): array {
		$this->assertEventGallery($gallery);
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$items = [];
		$this->appendFolders($root, '', null, 0, $items);
		usort($items, static fn (array $left, array $right): int => strnatcasecmp($left['path'], $right['path']));
		$medialFolders = count(array_filter($items, static fn (array $item): bool => $item['mediaCount'] > 0));
		return ['folders' => $items, 'suggested' => $medialFolders >= 4];
	}

	/** @param list<array<string, mixed>> $items */
	private function appendFolders(Folder $folder, string $parentPath, ?int $parentId, int $depth, array &$items): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof Folder || str_starts_with($node->getName(), '.')) continue;
			$path = $parentPath === '' ? $node->getName() : $parentPath . '/' . $node->getName();
			$direct = $this->directMediaCount($node);
			$total = $this->mediaCount($node, 100000);
			$items[] = [
				'id' => (int)$node->getId(), 'parentId' => $parentId, 'parentPath' => $parentPath, 'depth' => $depth,
				'path' => $path, 'name' => $node->getName(), 'directMediaCount' => $direct,
				'totalMediaCount' => $total, 'mediaCount' => $total,
				'suggestion' => $this->suggestedRole($node->getName(), $direct),
			];
			$this->appendFolders($node, $path, (int)$node->getId(), $depth + 1, $items);
		}
	}

	private function directMediaCount(Folder $folder): int {
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) if ($node instanceof File && $this->mediaTypes->supports($node)) $count++;
		return $count;
	}

	private function suggestedRole(string $name, int $directMediaCount): string {
		if (preg_match('/^(allgemein|common|shared|event)$/iu', $name) === 1) return 'shared';
		return $directMediaCount > 0 ? 'private' : 'ignored';
	}

	/** @return array{items: list<array<string, mixed>>, summary: array<string, int>} */
	public function list(Gallery $gallery): array {
		$this->assertEventGallery($gallery);
		$qb = $this->db->getQueryBuilder();
		$rows = QueryResult::rows($qb->select('*')->from('proofing_event_recipients')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))
			->orderBy('display_name', 'ASC')->executeQuery());
		$links = [];
		foreach ($this->links->list($gallery)['items'] as $link) $links[(int)$link['id']] = $link;
		$items = [];
		$summary = ['total' => 0, 'draft' => 0, 'published' => 0, 'invited' => 0, 'failed' => 0];
		foreach ($rows as $row) {
			$status = (string)$row['delivery_status'];
			$summary['total']++;
			$summary[$status] = ($summary[$status] ?? 0) + 1;
			$linkId = $row['public_link_id'] === null ? null : (int)$row['public_link_id'];
			$resolvedFolder = $this->recipientFolder($gallery, $row);
			$items[] = [
				'id' => (int)$row['id'], 'folderPath' => $resolvedFolder['path'], 'folderState' => $resolvedFolder['state'], 'name' => (string)$row['display_name'],
				'email' => $row['email_cipher'] === null ? null : $this->crypto->decrypt((string)$row['email_cipher']),
				'locale' => $row['locale'], 'status' => $status, 'invitedAt' => $row['invited_at'] === null ? null : (int)$row['invited_at'],
				'link' => $linkId === null ? null : ($links[$linkId] ?? null),
				'waveId' => $row['wave_id'] === null ? null : (int)$row['wave_id'],
				'publicationStatus' => (string)$row['publication_status'], 'invitationStatus' => (string)$row['invitation_status'],
				'errorCode' => $row['error_code'] === null ? null : (string)$row['error_code'], 'attempts' => (int)$row['attempts'],
				'groupRoots' => is_string($row['group_roots'] ?? null) ? json_decode($row['group_roots'], true, flags: JSON_THROW_ON_ERROR) : [],
			];
		}
		return compact('items', 'summary');
	}

	/**
	 * @param list<string> $sharedRoots
	 * @param list<array{folderPath?: mixed, name?: mixed, email?: mixed, locale?: mixed, pin?: mixed}> $recipients
	 * @param array<string, mixed> $policy
	 * @return array<string, mixed>
	 */
	public function create(Gallery $gallery, array $sharedRoots, array $recipients, array $policy = [], ?string $expiresAt = null): array {
		$this->assertEventGallery($gallery);
		if ($recipients === [] || count($recipients) > 500) throw new \InvalidArgumentException('Select between 1 and 500 event recipients');
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$sharedRoots = $this->folderPaths($root, $sharedRoots);
		$this->links->assertEventCapacity($gallery, count($recipients));
		$this->links->configureEventPrimary($gallery, $sharedRoots);
		$policy = $policy === [] ? $this->policies->presets()['delivery'] : $this->policies->validate($policy);
		$created = 0;
		$skipped = 0;
		foreach ($recipients as $recipient) {
			$folderPath = is_string($recipient['folderPath'] ?? null) ? trim($recipient['folderPath'], '/') : '';
			$name = is_string($recipient['name'] ?? null) ? trim($recipient['name']) : '';
			$email = is_string($recipient['email'] ?? null) ? mb_strtolower(trim($recipient['email'])) : '';
			$locale = is_string($recipient['locale'] ?? null) && in_array($recipient['locale'], ['de', 'en'], true) ? $recipient['locale'] : null;
			$pin = is_string($recipient['pin'] ?? null) ? trim($recipient['pin']) : '';
			if ($folderPath === '' || $name === '' || mb_strlen($name) > 120) throw new \InvalidArgumentException('Every recipient needs a folder and a name');
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Recipient email address is invalid');
			if ($pin !== '' && (mb_strlen($pin) < 10 || mb_strlen($pin) > 64)) throw new \InvalidArgumentException('Recipient PIN must contain 10 to 64 characters');
			$privateRoot = $this->folderPaths($root, [$folderPath])[0];
			if (in_array($privateRoot, $sharedRoots, true)) throw new \InvalidArgumentException('A private recipient folder cannot also be shared');
			$privateFolder = $root->get($privateRoot);
			if (!$privateFolder instanceof Folder) throw new \InvalidArgumentException('Event folder not found');
			if ($this->recipientExists((int)$gallery->getId(), $privateFolder->getId(), $privateRoot)) { $skipped++; continue; }
			$link = $this->links->create($gallery, PublicLinkConfiguration::fromArray([
				'name' => $name, 'policy' => $policy, 'startPath' => '', 'allowedRoots' => [...$sharedRoots, $privateRoot],
				'viewMode' => 'folder', 'groupDepth' => 1, 'publicLocale' => $locale, 'password' => $pin,
				'expiresAt' => $expiresAt, 'reviewEnabled' => in_array($gallery->getPurpose(), ['selection', 'proofing'], true),
			]), true, $privateRoot);
			try {
				$this->insertRecipient($gallery, (int)$link['id'], $privateFolder->getId(), $privateRoot, $name, $email, $locale);
			} catch (\Throwable $exception) {
				try { $this->links->revoke($gallery, (int)$link['id'], $gallery->getOwnerUid()); } catch (\Throwable) {}
				throw $exception;
			}
			$created++;
		}
		return ['created' => $created, 'skipped' => $skipped, ...$this->list($gallery)];
	}

	/** @return array<string, mixed> */
	public function invite(Gallery $gallery, int $recipientId, string $message = ''): array {
		$row = $this->recipient($gallery, $recipientId);
		if ($row['email_cipher'] === null || $row['public_link_id'] === null) throw new \InvalidArgumentException('Recipient has no email delivery');
		$email = $this->crypto->decrypt((string)$row['email_cipher']);
		$link = null;
		foreach ($this->links->list($gallery)['items'] as $candidate) if ((int)$candidate['id'] === (int)$row['public_link_id']) $link = $candidate;
		if ($link === null || $link['status'] !== 'active') throw new \InvalidArgumentException('Recipient link is unavailable');
		try {
			$this->invitations->sendPublicLink($gallery, $email, (string)$link['url'], is_string($row['locale']) ? $row['locale'] : null, $message);
			$this->updateStatus($recipientId, 'invited', $this->clock->getTime());
		} catch (\Throwable $exception) {
			$this->updateStatus($recipientId, 'failed', null);
			throw $exception;
		}
		return $this->list($gallery);
	}

	/**
	 * @param list<string> $paths
	 * @return list<string>
	 */
	private function folderPaths(Folder $root, array $paths): array {
		$result = [];
		foreach ($paths as $path) {
			if (!is_string($path)) throw new \InvalidArgumentException('Event folder paths must be strings');
			$path = trim($path, '/');
			if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) throw new \InvalidArgumentException('Invalid event folder path');
			$node = $root->get($path);
			if (!$node instanceof Folder || !$root->isSubNode($node)) throw new \InvalidArgumentException('Event folder not found');
			$result[] = $path;
		}
		return array_values(array_unique($result));
	}

	private function mediaCount(Folder $folder, int $remaining): int {
		$count = 0;
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof File && $this->mediaTypes->supports($node)) $count++;
			elseif ($node instanceof Folder && !str_starts_with($node->getName(), '.')) $count += $this->mediaCount($node, $remaining - $count);
			if ($count >= $remaining) return $remaining;
		}
		return $count;
	}

	private function recipientExists(int $galleryId, int $folderId, string $folderPath): bool {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count())->from('proofing_event_recipients')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX($qb->expr()->isNull('folder_id'), $qb->expr()->eq('folder_path', $qb->createNamedParameter($folderPath))),
			))->executeQuery()->fetchOne() > 0;
	}

	private function insertRecipient(Gallery $gallery, int $linkId, int $folderId, string $folderPath, string $name, string $email, ?string $locale): void {
		$now = $this->clock->getTime();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_event_recipients')->values([
			'gallery_id' => $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT), 'public_link_id' => $qb->createNamedParameter($linkId, IQueryBuilder::PARAM_INT),
			'folder_id' => $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT), 'folder_path' => $qb->createNamedParameter($folderPath), 'display_name' => $qb->createNamedParameter($name),
			'email_cipher' => $qb->createNamedParameter($email === '' ? null : $this->crypto->encrypt($email)), 'locale' => $qb->createNamedParameter($locale),
			'delivery_status' => $qb->createNamedParameter('published'), 'invited_at' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT), 'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	/** @return array<string, mixed> */
	private function recipient(Gallery $gallery, int $recipientId): array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_event_recipients')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($recipientId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('gallery_id', $qb->createNamedParameter($gallery->getId(), IQueryBuilder::PARAM_INT)))->executeQuery());
		if ($row === false) throw new \InvalidArgumentException('Event recipient not found');
		return $row;
	}

	private function updateStatus(int $id, string $status, ?int $invitedAt): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_event_recipients')->set('delivery_status', $qb->createNamedParameter($status))
			->set('invited_at', $qb->createNamedParameter($invitedAt, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
	}

	private function assertEventGallery(Gallery $gallery): void {
		if ($gallery->getDeliveryMode() !== 'event') throw new \InvalidArgumentException('Event delivery is only available for event projects');
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{path: string, state: 'readable'|'missing'}
	 */
	private function recipientFolder(Gallery $gallery, array $row): array {
		$snapshot = (string)$row['folder_path'];
		if ($row['folder_id'] === null) return ['path' => $snapshot, 'state' => 'readable'];
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$prefix = rtrim($root->getPath(), '/') . '/';
		foreach ($root->getById((int)$row['folder_id']) as $node) {
			if (!$node instanceof Folder || !$root->isSubNode($node)) continue;
			return ['path' => substr($node->getPath(), strlen($prefix)), 'state' => 'readable'];
		}
		return ['path' => $snapshot, 'state' => 'missing'];
	}
}
