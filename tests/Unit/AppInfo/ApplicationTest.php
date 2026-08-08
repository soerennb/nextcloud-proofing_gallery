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
		self::assertCount(5, $info->openmetrics->exporter);
		$exporters = [];
		foreach ($info->openmetrics->exporter as $exporter) $exporters[] = (string)$exporter;
		self::assertContains('OCA\\ProofingGallery\\OpenMetrics\\GalleryTotalMetric', $exporters);
		self::assertCount(6, $info->{'background-jobs'}->job);
		$jobs = [];
		foreach ($info->{'background-jobs'}->job as $job) $jobs[] = (string)$job;
		self::assertContains('OCA\\ProofingGallery\\BackgroundJob\\CleanupGalleryDataJob', $jobs);
		self::assertContains('OCA\\ProofingGallery\\RepairStep\\ScheduleProjectionBackfills', array_map(
			static fn (\SimpleXMLElement $step): string => (string)$step,
			iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step),
		));
	}
}
