<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\CapabilityPolicyService;
use OCA\ProofingGallery\Service\CoreSharingPolicyService;
use OCA\ProofingGallery\Service\FeedbackPolicyService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\IConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

final class FeedbackPolicyServiceTest extends TestCase {
	/** @param array<string, bool> $features */
	private function service(array $features = []): FeedbackPolicyService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string =>
			$key === 'instanceSettingsV2' ? json_encode(['features' => $features], JSON_THROW_ON_ERROR) : $default);
		return new FeedbackPolicyService(new CapabilityPolicyService(new PolicyService($config), new CoreSharingPolicyService($config), $this->createMock(IGroupManager::class)));
	}

	public function testEveryLayerCanRestrictEachFeature(): void {
		$review = array_fill_keys(array_keys(FeedbackPolicyService::FEATURES), true);
		$settings = GallerySettings::fromArray(['mode' => 'collaboration', 'review' => $review]);
		foreach (FeedbackPolicyService::FEATURES as $feature => $capability) {
			self::assertTrue($this->service()->effective($settings, PublicLinkPolicy::fromArray($review))[$feature]);
			self::assertFalse($this->service([$capability => false])->effective($settings, PublicLinkPolicy::fromArray($review))[$feature]);
			self::assertFalse($this->service()->effective(GallerySettings::merge($settings, ['review' => [$feature => false]]), PublicLinkPolicy::fromArray($review))[$feature]);
			self::assertFalse($this->service()->effective($settings, PublicLinkPolicy::fromArray(array_replace($review, [$feature => false])))[$feature]);
			self::assertFalse($this->service()->effective(GallerySettings::merge($settings, ['mode' => 'presentation']), PublicLinkPolicy::fromArray($review))[$feature]);
		}
	}

	public function testAnnotationsRequireEffectiveCommentsAndRatingsAreIndependentOfPicks(): void {
		$settings = GallerySettings::fromArray(['mode' => 'collaboration', 'review' => ['ratings' => true, 'pick' => true]]);
		$policy = PublicLinkPolicy::fromArray(['annotations' => true, 'comments' => false, 'ratings' => true, 'pick' => false]);
		$features = $this->service()->effective($settings, $policy);
		self::assertFalse($features['annotations']);
		self::assertTrue($features['ratings']);
		self::assertFalse($features['pick']);
	}

	public function testPublicSettingsBecomePresentationWhenAllEffectiveFeedbackIsBlocked(): void {
		$settings = GallerySettings::fromArray(['mode' => 'collaboration', 'review' => ['ratings' => true, 'pick' => true]]);
		$public = $this->service(['guestRatings' => false])->publicSettings($settings, PublicLinkPolicy::fromArray(['ratings' => true, 'pick' => true]));
		self::assertSame('presentation', $public->mode->value);
		self::assertFalse($public->review->ratings);
		self::assertFalse($public->review->pick);
		self::assertTrue($settings->review->ratings);
	}
}
