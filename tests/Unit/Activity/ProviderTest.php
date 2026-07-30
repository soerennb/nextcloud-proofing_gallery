<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Activity;

use OCA\ProofingGallery\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {
	public function testParsesOnlyMinimalGalleryMessage(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('proofing_gallery');
		$event->method('getSubject')->willReturn('gallery_event');
		$event->method('getSubjectParameters')->willReturn(['message' => 'A guest uploaded image.jpg']);
		$event->expects(self::once())
			->method('setParsedSubject')
			->with('A guest uploaded image.jpg')
			->willReturnSelf();

		self::assertSame($event, (new Provider())->parse('en', $event));
	}

	public function testRejectsForeignActivity(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('files');
		$this->expectException(UnknownActivityException::class);
		(new Provider())->parse('en', $event);
	}
}
