<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\BackgroundJob\RebuildGalleryMediaCountsJob;
use OCA\ProofingGallery\Db\CollectionRepository;
use OCA\ProofingGallery\Db\GalleryMediaCountRepository;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;

final class GalleryMediaCountInvalidator {
	public function __construct(private GalleryMediaCountRepository $counts, private CollectionRepository $members, private IJobList $jobs, private ITimeFactory $clock) {
	}

	public function invalidate(int $id, bool $clear = false): void {
		$this->invalidateMany([$id], $clear);
	}

	/** @param list<int> $ids */
	public function invalidateMany(array $ids, bool $clear = false): void {
		$affected = $ids;
		foreach (array_chunk($ids, 500) as $batch) $affected = [...$affected, ...$this->members->dependentsMany($batch)];
		foreach (array_chunk(array_values(array_unique($affected)), 500) as $batch) {
			$this->counts->invalidateMany($batch, $this->clock->getTime(), $clear);
			$this->jobs->add(RebuildGalleryMediaCountsJob::class, count($batch) === 1 ? ['galleryId' => $batch[0]] : ['galleryIds' => $batch]);
		}
	}
}
