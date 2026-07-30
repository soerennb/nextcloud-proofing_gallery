<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase {
	public function testAppIdIsStable(): void {
		$info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');

		self::assertNotFalse($info);
		self::assertSame('proofing_gallery', (string)$info->id);
		self::assertSame('Proofing Gallery', (string)$info->name);
	}
}
