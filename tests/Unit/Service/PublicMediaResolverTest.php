<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Service\CollectionService;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCA\ProofingGallery\Service\PublicLinkScopeService;
use OCA\ProofingGallery\Service\PublicMediaResolver;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicMediaResolverTest extends TestCase {
	public static function mediaCases(): array {
		return [
			'visible' => ['photo.jpg', true, true, true],
			'explicit nested story media without folder navigation' => ['album/photo.jpg', true, true, true],
			'unreadable' => ['photo.jpg', false, true, false],
			'inbox' => ['.proofing-gallery-inbox/unapproved.jpg', true, true, false],
			'hidden file' => ['album/.backup.jpg', true, true, false],
			'foreign' => ['photo.jpg', true, false, false],
		];
	}

	#[DataProvider('mediaCases')]
	public function testUsesTheSameVisibilityBoundaryForDirectMedia(string $path, bool $readable, bool $contained, bool $allowed): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/root/' . $path);
		$file->method('getMimeType')->willReturn('image/jpeg');
		$file->method('isReadable')->willReturn($readable);
		$root = $this->createMock(Folder::class);
		$root->method('getPath')->willReturn('/root');
		$root->method('getById')->with(91)->willReturn([$file]);
		$root->method('isSubNode')->willReturn($contained);
		$gallery = new Gallery();
		$gallery->setSourceType('folder');
		$context = new PublicShareContext($this->createMock(IShare::class), $gallery, GallerySettings::fromArray(['navigation' => ['folders' => false]]), new PublicLink(), PublicLinkPolicy::fromArray([]), $root);
		$collections = (new \ReflectionClass(CollectionService::class))->newInstanceWithoutConstructor();
		$resolver = new PublicMediaResolver($collections, new MediaTypePolicy());
		self::assertSame($allowed, $resolver->allows($context, 91));
	}
}
