<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Listener;

use OCA\ProofingGallery\Listener\GalleryFaviconListener;
use OCA\ProofingGallery\Service\GalleryFaviconService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class GalleryFaviconListenerTest extends TestCase {
	public function testDoesNotChangeOtherPagesSettingsOrPreviewFrames(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects(self::never())->method('linkTo');
		$state = $this->createMock(IInitialState::class);
		$state->expects(self::never())->method('provideInitialState');
		$listener = new GalleryFaviconListener(new GalleryFaviconService($urls), $state);
		foreach ([['core', 'login'], ['files', 'index'], ['proofing_gallery', 'preview-frame'], ['proofing_gallery', 'admin'], ['proofing_gallery', 'personal']] as [$app, $template]) {
			$listener->handle(new BeforeTemplateRenderedEvent(true, new TemplateResponse($app, $template)));
		}
		$listener->handle(new Event());
	}
}
