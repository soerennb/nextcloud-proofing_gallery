<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Controller;

use OCA\ProofingGallery\Controller\GalleryMediaFeedbackController;
use OCA\ProofingGallery\Db\MediaFeedbackRepository;
use OCA\ProofingGallery\Service\GalleryAccessService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class GalleryMediaFeedbackControllerTest extends TestCase {
	public function testMalformedOrOversizedRequestsAreRejectedBeforeAnyQuery(): void {
		$feedback = $this->createMock(MediaFeedbackRepository::class);
		$feedback->expects(self::never())->method('counts');
		$controller = $this->controller($feedback);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->index(7, '')->getStatus());
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->index(7, '1,x')->getStatus());
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->index(7, '0')->getStatus());
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->index(7, implode(',', range(1, 201)))->getStatus());
	}

	public function testAnonymousRequestsAreRejected(): void {
		$feedback = $this->createMock(MediaFeedbackRepository::class);
		$feedback->expects(self::never())->method('counts');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($feedback)->index(7, '3,4')->getStatus());
	}

	private function controller(MediaFeedbackRepository $feedback): GalleryMediaFeedbackController {
		$access = (new \ReflectionClass(GalleryAccessService::class))->newInstanceWithoutConstructor();
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		return new GalleryMediaFeedbackController($this->createMock(IRequest::class), $access, $feedback, $session);
	}
}
