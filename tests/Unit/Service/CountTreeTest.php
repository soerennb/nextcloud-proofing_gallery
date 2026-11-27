<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\CountTree;
use PHPUnit\Framework\TestCase;

final class CountTreeTest extends TestCase {
	/** @return array{parent_id: int, hit: bool, direct_images: int, direct_videos: int, direct_witness: ?int, sub_images: int, sub_videos: int, sub_witness: ?int} */
	private function row(int $parent, int $images = 0, int $videos = 0, ?int $witness = null, bool $hit = false, int $subImages = 0, int $subVideos = 0, ?int $subWitness = null): array {
		return ['parent_id' => $parent, 'hit' => $hit, 'direct_images' => $images, 'direct_videos' => $videos, 'direct_witness' => $witness,
			'sub_images' => $subImages, 'sub_videos' => $subVideos, 'sub_witness' => $subWitness];
	}

	public function testSumsDirectFilesOfEveryVisitedFolderBelowTheRoot(): void {
		$rows = [
			1 => $this->row(0, 3, 0, 10),
			2 => $this->row(1, 5, 1, 20),
			3 => $this->row(2, 7, 0, 30),
		];
		$result = CountTree::fold($rows, 1);
		self::assertSame(15, $result['images']);
		self::assertSame(1, $result['videos']);
		self::assertSame(10, $result['witness']);
		self::assertSame(['images' => 7, 'videos' => 0, 'witness' => 30], $result['visited'][3]);
		self::assertSame(['images' => 12, 'videos' => 1, 'witness' => 20], $result['visited'][2]);
	}

	public function testCacheHitContributesItsStoredSubtreeWithoutDescending(): void {
		// Folder 2 is unchanged: its stored subtree (9 images) is used and folder 3 is not visited.
		$rows = [
			1 => $this->row(0, 1, 0, 5),
			2 => $this->row(1, 0, 0, null, true, 9, 2, 40),
			3 => $this->row(2, 100, 0, 99),
		];
		$result = CountTree::fold($rows, 1);
		self::assertSame(10, $result['images']);
		self::assertSame(2, $result['videos']);
		self::assertSame(5, $result['witness']);
		self::assertArrayNotHasKey(2, $result['visited']);
		self::assertArrayNotHasKey(3, $result['visited']);
	}

	public function testWitnessFallsBackToTheFirstDescendantWithAFile(): void {
		$rows = [1 => $this->row(0), 2 => $this->row(1, 0, 0, null), 3 => $this->row(2, 1, 0, 77)];
		self::assertSame(77, CountTree::fold($rows, 1)['witness']);
	}

	public function testRowsOutsideTheRootChainAreIgnored(): void {
		// Folder 9 belongs to an earlier generation and is not reachable from the root.
		$rows = [1 => $this->row(0, 2, 0, 1), 9 => $this->row(0, 50, 0, 2)];
		self::assertSame(2, CountTree::fold($rows, 1)['images']);
	}

	public function testMissingRootYieldsZeroTotals(): void {
		$result = CountTree::fold([2 => $this->row(1, 4, 0, 3)], 1);
		self::assertSame(['images' => 0, 'videos' => 0, 'witness' => null, 'visited' => []], $result);
	}

	public function testCycleInCorruptParentLinksDoesNotLoop(): void {
		$rows = [1 => $this->row(0, 1, 0, 1), 2 => $this->row(3, 1, 0, 2), 3 => $this->row(2, 1, 0, 3)];
		$result = CountTree::fold($rows, 1);
		self::assertSame(1, $result['images']);
	}

	/** @return array{parent_id: int, generation: string, hit: bool} */
	private function stored(int $parent, string $generation, bool $hit = false): array {
		return ['parent_id' => $parent, 'generation' => $generation, 'hit' => $hit];
	}

	public function testRowsBelowACacheHitAreKeptForLaterWalks(): void {
		// Folder 2 was taken from the cache; its older descendants 3 and 4 are reused once 2 changes.
		$rows = [1 => $this->stored(0, 'new'), 2 => $this->stored(1, 'new', true), 3 => $this->stored(2, 'old'), 4 => $this->stored(3, 'old')];
		self::assertSame([], CountTree::orphans($rows, 'new'));
	}

	public function testOlderRowsBelowAWalkedFolderAreOrphans(): void {
		// Folder 2 was walked in this generation, so folder 3 no longer exists there; 4 sat below 3.
		$rows = [1 => $this->stored(0, 'new'), 2 => $this->stored(1, 'new'), 3 => $this->stored(2, 'old'), 4 => $this->stored(3, 'old'), 5 => $this->stored(2, 'new')];
		self::assertSame([3, 4], CountTree::orphans($rows, 'new'));
	}

	public function testRowsOfAnOldRootAreOrphansAfterARebind(): void {
		$rows = [1 => $this->stored(0, 'old'), 2 => $this->stored(1, 'old', true), 3 => $this->stored(2, 'old'), 9 => $this->stored(0, 'new')];
		self::assertSame([1, 2, 3], CountTree::orphans($rows, 'new'));
	}

	public function testOrphanDetectionSurvivesCyclesInCorruptParentLinks(): void {
		$rows = [1 => $this->stored(0, 'new'), 2 => $this->stored(3, 'old'), 3 => $this->stored(2, 'old')];
		self::assertSame([2, 3], CountTree::orphans($rows, 'new'));
	}
}
