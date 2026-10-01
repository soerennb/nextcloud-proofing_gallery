<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use OCP\AppFramework\Db\TTransactional;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class KioskRepository {
	use TTransactional;

	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function event(string $ownerUid, string $eventId): ?array {
		return $this->find('proofing_kiosk_events', ['owner_uid' => $ownerUid, 'event_key' => hash('sha256', $eventId)]);
	}

	/** @return array<string, mixed>|null */
	public function forGallery(int $galleryId): ?array {
		return $this->find('proofing_kiosk_events', ['gallery_id' => $galleryId]);
	}

	/** @param array<string, mixed> $config */
	public function reserveEvent(string $ownerUid, string $eventId, string $requestHash, array $config, int $now): void {
		$this->insert('proofing_kiosk_events', [
			'owner_uid' => $ownerUid, 'event_key' => hash('sha256', $eventId), 'event_id' => $eventId,
			'request_hash' => $requestHash, 'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
			'state' => 'preparing', 'created_at' => $now,
		]);
	}

	public function bindFolder(int $id, int $folderId): void {
		$this->update('proofing_kiosk_events', ['id' => $id], ['folder_id' => $folderId]);
	}

	public function bindGallery(int $id, int $galleryId): void {
		$this->update('proofing_kiosk_events', ['id' => $id], ['gallery_id' => $galleryId]);
	}

	public function ready(int $id, int $linkId): void {
		$this->update('proofing_kiosk_events', ['id' => $id], ['state' => 'ready', 'public_link_id' => $linkId]);
	}

	/** @return array<string, mixed>|null */
	public function photo(int $galleryId, string $photoId): ?array {
		return $this->find('proofing_kiosk_photos', ['gallery_id' => $galleryId, 'photo_id' => $photoId]);
	}

	public function reservePhoto(int $galleryId, string $photoId, string $checksum, int $now): void {
		$this->insert('proofing_kiosk_photos', [
			'gallery_id' => $galleryId, 'photo_id' => $photoId, 'checksum' => $checksum, 'created_at' => $now,
		]);
	}

	public function storedPhoto(int $galleryId, string $photoId, int $fileId, int $now): void {
		$this->update('proofing_kiosk_photos', ['gallery_id' => $galleryId, 'photo_id' => $photoId], ['file_id' => $fileId, 'stored_at' => $now]);
	}

	public function recentUploads(int $galleryId, int $since): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select($qb->func()->count())->from('proofing_kiosk_photos')
			->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('stored_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchOne();
	}

	/** @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed {
		return $this->atomic($callback, $this->db);
	}

	/** @param array<string, int|string> $keys
	 * @return array<string, mixed>|null
	 */
	private function find(string $table, array $keys): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table);
		$this->where($qb, $keys);
		$row = QueryResult::row($qb->executeQuery());
		return $row === false ? null : $row;
	}

	/** @param array<string, int|string> $values */
	private function insert(string $table, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($values as $key => $value) $qb->setValue($key, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		$qb->executeStatement();
	}

	/** @param array<string, int|string> $keys
	 * @param array<string, int|string> $values
	 */
	private function update(string $table, array $keys, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table);
		foreach ($values as $key => $value) $qb->set($key, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
		$this->where($qb, $keys);
		$qb->executeStatement();
	}

	/** @param array<string, int|string> $keys */
	private function where(IQueryBuilder $qb, array $keys): void {
		foreach ($keys as $key => $value) $qb->andWhere($qb->expr()->eq($key, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
	}
}
