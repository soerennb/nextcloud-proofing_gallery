<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\OpenMetrics;

use OCA\ProofingGallery\Service\HealthService;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\MetricType;

abstract class AbstractOperationalMetric implements IMetricFamily {
	public function __construct(protected HealthService $health) {
	}

	final public function type(): MetricType {
		return MetricType::gauge;
	}

	public function unit(): string {
		return '';
	}
}
