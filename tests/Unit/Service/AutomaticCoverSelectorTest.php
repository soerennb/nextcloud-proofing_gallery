<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\AutomaticCoverSelector;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IPreview;
use PHPUnit\Framework\TestCase;

final class AutomaticCoverSelectorTest extends TestCase {
	public function testRootImagePrecedesNestedImagesAndNaturalNameOrderIsStable(): void {
		$first = $this->file(2, 'image2.jpg');
		$root = $this->folder(1, 'root', [$this->folder(4, 'album', [$this->file(5, 'a.jpg')]), $this->file(3, 'image10.jpg'), $first]);
		self::assertSame($first, $this->selector()->find($root));
	}

	public function testNearestImageWinsEvenWhenAnEarlierFolderHasDeepImages(): void {
		$nearest = $this->file(6, 'near.jpg');
		$deep = $this->folder(3, 'deep', [$this->file(4, 'deep.jpg')]);
		$root = $this->folder(1, 'root', [$this->folder(2, 'a', [$deep]), $this->folder(5, 'b', [$nearest])]);
		self::assertSame($nearest, $this->selector()->find($root));
	}

	public function testUnreadableHiddenAndUnsupportedPreviewsDoNotHideUsableImages(): void {
		$usable = $this->file(9, 'usable.jpg');
		$root = $this->folder(1, 'root', [
			$this->file(2, '.hidden.jpg'), $this->file(3, 'private.jpg', readable: false),
			$this->file(4, 'unsupported.pdf', 'application/pdf'), $this->file(5, 'unavailable.jpg'),
			$this->folder(6, '.private', [$this->file(7, 'hidden.jpg')]),
			$this->folder(8, 'album', [$usable]),
		]);
		self::assertSame($usable, $this->selector([5])->find($root));
	}

	public function testVideoFallbackWaitsForNestedImageAndEmptyOrCyclicTreesTerminate(): void {
		$video = $this->file(2, 'video.mp4', 'video/mp4');
		$image = $this->file(4, 'nested.jpg');
		self::assertSame($image, $this->selector()->find($this->folder(1, 'root', [$video, $this->folder(3, 'album', [$image])])));
		self::assertSame($video, $this->selector()->find($this->folder(1, 'root', [$video])));
		$root = $this->createMock(Folder::class);
		$root->method('getId')->willReturn(1);
		$root->method('getName')->willReturn('root');
		$root->method('isReadable')->willReturn(true);
		$root->method('getDirectoryListing')->willReturn([$root]);
		self::assertNull($this->selector()->find($root));
	}

	private function selector(array $unavailable = []): AutomaticCoverSelector {
		$previews = $this->createMock(IPreview::class);
		$previews->method('isAvailable')->willReturnCallback(static fn (File $file): bool => !in_array($file->getId(), $unavailable, true));
		return new AutomaticCoverSelector(new MediaTypePolicy(), $previews);
	}

	private function file(int $id, string $name, string $mime = 'image/jpeg', bool $readable = true): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('isReadable')->willReturn($readable);
		return $file;
	}

	private function folder(int $id, string $name, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getName')->willReturn($name);
		$folder->method('isReadable')->willReturn(true);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}
}
