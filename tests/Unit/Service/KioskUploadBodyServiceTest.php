<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\KioskUploadBodyService;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class KioskUploadBodyServiceTest extends TestCase {
	public function testJpegIsStagedExactlyWithChecksum(): void {
		$jpeg = file_get_contents(__DIR__ . '/../../e2e/fixtures/kiosk.jpg');
		self::assertIsString($jpeg);
		$stream = $this->stream($jpeg);
		$body = $this->service()->receive($stream);
		try {
			self::assertSame($jpeg, file_get_contents($body['path']));
			self::assertSame(hash('sha256', $jpeg), $body['checksum']);
		} finally { unlink($body['path']); fclose($stream); }
	}

	public function testDeclaredJpegMustActuallyBeJpeg(): void {
		$stream = $this->stream('not an image');
		try {
			$this->expectException(\InvalidArgumentException::class);
			$this->service()->receive($stream);
		} finally { fclose($stream); }
	}

	public function testEmptyBodyIsRejected(): void {
		$stream = $this->stream('');
		try {
			$this->expectException(\InvalidArgumentException::class);
			$this->service()->receive($stream);
		} finally { fclose($stream); }
	}

	public function testSizeLimitIsEnforcedBeforeImageProcessing(): void {
		$stream = $this->stream(str_repeat('x', 1048577));
		try {
			$this->expectException(\InvalidArgumentException::class);
			$this->service()->receive($stream);
		} finally { fclose($stream); }
	}

	private function service(): KioskUploadBodyService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default): string => $key === 'maxUploadBytes' ? '1048576' : $default);
		return new KioskUploadBodyService(new PolicyService($config));
	}

	/** @return resource */
	private function stream(string $bytes): mixed {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $bytes); rewind($stream);
		return $stream;
	}
}
