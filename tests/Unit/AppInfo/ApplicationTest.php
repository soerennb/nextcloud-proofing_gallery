<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\AppInfo;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\BackgroundJob\CleanupGalleryDataJob;
use OCA\ProofingGallery\BackgroundJob\ContinueCleanupGalleryDataJob;
use OCA\ProofingGallery\Service\CleanupTelemetryService;
use OCA\ProofingGallery\Service\LifecycleService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class ApplicationTest extends TestCase {

	public function testCleanupJobsAreRegisteredWithAllConstructorDependencies(): void {
		/** @var array<class-string, callable(ContainerInterface): object> $factories */
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::exactly(3))
			->method('registerService')
			->willReturnCallback(static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			});

		$application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$application->register($context);

		$time = $this->createMock(ITimeFactory::class);
		$jobs = $this->createMock(IJobList::class);
		$lifecycle = (new \ReflectionClass(LifecycleService::class))->newInstanceWithoutConstructor();
		$telemetry = (new \ReflectionClass(CleanupTelemetryService::class))->newInstanceWithoutConstructor();
		$container = new class($time, $jobs, $lifecycle, $telemetry) implements ContainerInterface {
			/** @var array<string, object> */
			private array $services;

			public function __construct(object ...$services) {
				$this->services = [];
				foreach ($services as $service) $this->services[$service::class] = $service;
				$this->services[\OCP\AppFramework\Utility\ITimeFactory::class] = $services[0];
				$this->services[\OCP\BackgroundJob\IJobList::class] = $services[1];
				$this->services[\OCA\ProofingGallery\Service\LifecycleService::class] = $services[2];
				$this->services[\OCA\ProofingGallery\Service\CleanupTelemetryService::class] = $services[3];
			}

			public function get(string $id): mixed {
				return $this->services[$id];
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};

		self::assertArrayHasKey(CleanupGalleryDataJob::class, $factories);
		self::assertArrayHasKey(ContinueCleanupGalleryDataJob::class, $factories);
		self::assertInstanceOf(CleanupGalleryDataJob::class, $factories[CleanupGalleryDataJob::class]($container));
		self::assertInstanceOf(ContinueCleanupGalleryDataJob::class, $factories[ContinueCleanupGalleryDataJob::class]($container));
	}
}
