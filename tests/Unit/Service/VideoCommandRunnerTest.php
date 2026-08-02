<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\VideoCommandRunner;
use PHPUnit\Framework\TestCase;

final class VideoCommandRunnerTest extends TestCase {
	public function testRunsArgumentVectorWithoutACommandShell(): void {
		$result = (new VideoCommandRunner())->run([PHP_BINARY, '-r', 'fwrite(STDOUT, "ready");'], 2);

		self::assertSame(0, $result['exitCode']);
		self::assertSame('ready', $result['stdout']);
		self::assertFalse($result['timedOut']);
	}

	public function testTerminatesWorkAfterTheResourceLimit(): void {
		$result = (new VideoCommandRunner())->run([PHP_BINARY, '-r', 'sleep(3);'], 1);

		self::assertSame(124, $result['exitCode']);
		self::assertTrue($result['timedOut']);
	}
}
