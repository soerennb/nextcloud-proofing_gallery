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
		foreach ([$id, ...$this->members->dependents($id)] as $affected) {
			$this->counts->invalidate($affected, $this->clock->getTime(), $clear);
			$this->jobs->add(RebuildGalleryMediaCountsJob::class, ['galleryId' => $affected]);
		}
	}
}
