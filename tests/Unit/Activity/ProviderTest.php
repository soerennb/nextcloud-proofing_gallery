<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Activity;

use OCA\ProofingGallery\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase {
	public function testParsesLocalizedGalleryEvent(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('proofing_gallery');
		$event->method('getSubject')->willReturn('gallery_event');
		$event->method('getSubjectParameters')->willReturn(['eventType' => 'upload.received', 'galleryTitle' => 'Wedding']);
		$event->method('getObjectId')->willReturn(42);
		$event->method('getObjectName')->willReturn('Wedding');
		$event->expects(self::once())
			->method('setRichSubject')
			->willReturnSelf();
		$event->method('setLink')->willReturnSelf();
		$event->method('setIcon')->willReturnSelf();

		self::assertSame($event, $this->provider()->parse('en', $event));
	}

	public function testRejectsForeignActivity(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('files');
		$this->expectException(UnknownActivityException::class);
		$this->provider()->parse('en', $event);
	}

	private function provider(): Provider {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/proofing_gallery/');
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => $path);
		$url->method('imagePath')->willReturn('/apps/proofing_gallery/img/app-dark.svg');
		return new Provider($factory, $url);
	}
}
