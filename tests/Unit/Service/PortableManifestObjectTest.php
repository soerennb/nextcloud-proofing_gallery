<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\UserMigration\ProofingGalleryMigrator;
use OCP\UserMigration\UserMigrationException;
use PHPUnit\Framework\TestCase;

final class PortableManifestObjectTest extends TestCase {
	public function testEmptyJsonObjectIsAccepted(): void {
		self::assertSame([], $this->parseObject(json_decode('{}', true, flags: JSON_THROW_ON_ERROR)));
		self::assertSame(['mode' => 'collaboration'], $this->parseObject(['mode' => 'collaboration']));
	}

	public function testNonEmptyListIsRejected(): void {
		$this->expectException(UserMigrationException::class);
		$this->parseObject(['invalid']);
	}

	public function testScalarIsRejected(): void {
		$this->expectException(UserMigrationException::class);
		$this->parseObject('invalid');
	}

	/** @return array<string, mixed> */
	private function parseObject(mixed $value): array {
		$class = new \ReflectionClass(ProofingGalleryMigrator::class);
		return $class->getMethod('object')->invoke($class->newInstanceWithoutConstructor(), $value);
	}
}
