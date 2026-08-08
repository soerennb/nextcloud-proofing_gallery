<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\QueryResult;

use OCA\ProofingGallery\Db\PublicLink;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class ShareAuditService {
	private const EVENTS = ['login', 'view', 'download', 'export', 'upload', 'feedback', 'revoke'];

	public function __construct(private IDBConnection $db, private ITimeFactory $clock, private PolicyService $policies) {
	}

	public function record(PublicLink $link, string $event, ?int $guestId = null, ?string $actorUid = null, ?int $fileId = null, string $outcome = 'success', ?string $reasonCode = null): void {
		if (!in_array($event, self::EVENTS, true) || !in_array($outcome, ['success', 'denied', 'failed'], true)) {
			throw new \InvalidArgumentException('Invalid public link audit event');
		}
		if ($reasonCode !== null && preg_match('/^[a-z0-9_]{1,48}$/', $reasonCode) !== 1) throw new \InvalidArgumentException('Invalid audit reason code');
		$qb = $this->db->getQueryBuilder();
		$qb->insert('proofing_share_audit')->values([
			'gallery_id' => $qb->createNamedParameter($link->getGalleryId(), IQueryBuilder::PARAM_INT),
			'public_link_id' => $qb->createNamedParameter($link->getId(), IQueryBuilder::PARAM_INT),
			'guest_id' => $qb->createNamedParameter($guestId, IQueryBuilder::PARAM_INT),
			'actor_uid' => $qb->createNamedParameter($actorUid),
			'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'event_type' => $qb->createNamedParameter($event),
			'outcome' => $qb->createNamedParameter($outcome),
			'reason_code' => $qb->createNamedParameter($reasonCode),
			'created_at' => $qb->createNamedParameter($this->clock->getTime(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	public function purgeExpired(): int {
		$cutoff = $this->clock->getTime() - ($this->policies->get('shareAuditRetentionDays') * 86400);
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_share_audit')
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/** @return list<array<string, mixed>> */
	public function forGallery(int $galleryId, int $limit = 100, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('public_link_id', 'guest_id', 'actor_uid', 'file_id', 'event_type', 'outcome', 'reason_code', 'created_at')
			->from('proofing_share_audit')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setFirstResult(max(0, $offset))
			->setMaxResults(max(1, min(250, $limit)));
		return array_map(static fn (array $row): array => [
			'publicLinkId' => (int)$row['public_link_id'],
			'guestId' => $row['guest_id'] === null ? null : (int)$row['guest_id'],
			'actorUid' => $row['actor_uid'],
			'fileId' => $row['file_id'] === null ? null : (int)$row['file_id'],
			'event' => $row['event_type'],
			'outcome' => $row['outcome'],
			'reasonCode' => $row['reason_code'],
			'createdAt' => (int)$row['created_at'],
		], QueryResult::rows($qb->executeQuery()));
	}

	/** @return array{items:list<array<string,mixed>>,total:int,nextCursor:?string} */
	public function pageForGallery(int $galleryId, int $limit, ?string $cursor, ScopedCursorCodec $cursors): array {
		$limit = max(1, min(100, $limit));
		$scope = 'share-audit:' . $galleryId;
		$beforeId = $cursors->decode($cursor, $scope);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'public_link_id', 'guest_id', 'actor_uid', 'file_id', 'event_type', 'outcome', 'reason_code', 'created_at')
			->from('proofing_share_audit')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')->setMaxResults($limit + 1);
		if ($beforeId !== null) $qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
		$rows = QueryResult::rows($qb->executeQuery());
		$hasMore = count($rows) > $limit;
		if ($hasMore) array_pop($rows);
		$items = array_map(static fn (array $row): array => [
			'publicLinkId' => (int)$row['public_link_id'], 'guestId' => $row['guest_id'] === null ? null : (int)$row['guest_id'],
			'actorUid' => $row['actor_uid'], 'fileId' => $row['file_id'] === null ? null : (int)$row['file_id'],
			'event' => $row['event_type'], 'outcome' => $row['outcome'], 'reasonCode' => $row['reason_code'], 'createdAt' => (int)$row['created_at'],
		], $rows);
		$count = $this->db->getQueryBuilder();
		$count->select($count->func()->count())->from('proofing_share_audit')
			->where($count->expr()->eq('gallery_id', $count->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)));
		$last = $rows === [] ? null : $rows[array_key_last($rows)];
		return ['items' => $items, 'total' => (int)$count->executeQuery()->fetchOne(), 'nextCursor' => $hasMore && $last !== null ? $cursors->encode($scope, (int)$last['id']) : null];
	}
}
