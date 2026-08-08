<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\LifecycleScheduleService;
use PHPUnit\Framework\TestCase;

final class LifecycleScheduleServiceTest extends TestCase {
	public function testPublishedGalleryProjectsReminderAndRevokeTimestamps(): void {
		$gallery = $this->gallery([
			'enabled' => true,
			'trigger' => 'fixed_date',
			'revokeAt' => '2026-08-10',
			'reminderDays' => [7, 1],
		]);
		$gallery->setShareToken('token');
		$now = strtotime('2026-08-01 12:00:00 UTC');

		(new LifecycleScheduleService())->project($gallery, $now);

		self::assertSame(strtotime('2026-08-10 23:59:59 UTC'), $gallery->getLifecycleRevokeAt());
		self::assertSame(strtotime('2026-08-03 23:59:59 UTC'), $gallery->getLifecycleNextAt());
	}

	public function testProjectionAdvancesPastProcessedReminder(): void {
		$gallery = $this->gallery([
			'enabled' => true,
			'trigger' => 'fixed_date',
			'revokeAt' => '2026-08-10',
			'reminderDays' => [7, 1],
		]);
		$gallery->setShareToken('token');

		(new LifecycleScheduleService())->project($gallery, strtotime('2026-08-04 00:00:00 UTC'), false);

		self::assertSame(strtotime('2026-08-09 23:59:59 UTC'), $gallery->getLifecycleNextAt());
	}

	public function testRevokedGalleryProjectsArchiveTimestamp(): void {
		$gallery = $this->gallery([
			'enabled' => true,
			'archiveAfterDays' => 2,
		]);
		$gallery->setRevokedAt(1000);

		(new LifecycleScheduleService())->project($gallery, 1000);

		self::assertSame(1000 + 2 * 86400, $gallery->getLifecycleArchiveAt());
		self::assertSame($gallery->getLifecycleArchiveAt(), $gallery->getLifecycleNextAt());
	}

	public function testDisabledLifecycleClearsStaleProjection(): void {
		$gallery = $this->gallery(['enabled' => false]);
		$gallery->setLifecycleRevokeAt(1);
		$gallery->setLifecycleArchiveAt(2);
		$gallery->setLifecycleNextAt(3);

		(new LifecycleScheduleService())->project($gallery, 1000);

		self::assertNull($gallery->getLifecycleRevokeAt());
		self::assertNull($gallery->getLifecycleArchiveAt());
		self::assertNull($gallery->getLifecycleNextAt());
	}

	/** @param array<string, mixed> $lifecycle */
	private function gallery(array $lifecycle): Gallery {
		$gallery = new Gallery();
		$gallery->setStatus('published');
		$gallery->setSettings(json_encode(GallerySettings::merge(
			GallerySettings::defaults(),
			['lifecycle' => $lifecycle],
		), JSON_THROW_ON_ERROR));
		return $gallery;
	}
}
