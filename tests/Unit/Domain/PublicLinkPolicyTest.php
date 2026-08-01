<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Domain;

use OCA\ProofingGallery\Domain\DownloadScope;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use PHPUnit\Framework\TestCase;

final class PublicLinkPolicyTest extends TestCase {
	public function testDefaultsAreViewOnly(): void {
		$policy = PublicLinkPolicy::fromArray([]);
		self::assertTrue($policy->allows('view'));
		self::assertFalse($policy->allows('comments'));
		self::assertSame(DownloadScope::None, $policy->downloadScope);
	}

	public function testRestrictionCannotWidenEitherCapabilityKind(): void {
		$left = PublicLinkPolicy::fromArray(['comments' => true, 'export' => true, 'downloadScope' => 'all']);
		$right = PublicLinkPolicy::fromArray(['comments' => true, 'downloadScope' => 'selection']);
		$result = $left->restrict($right);
		self::assertTrue($result->allows('comments'));
		self::assertFalse($result->allows('export'));
		self::assertSame(DownloadScope::Selection, $result->downloadScope);
	}
}
