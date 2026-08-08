<?php

declare(strict_types=1);

namespace OCP\OpenMetrics;

if (!enum_exists(MetricType::class)) {
	enum MetricType { case gauge; }
}

if (!class_exists(Metric::class)) {
	final readonly class Metric {
		/** @param array<string, string> $labels */
		public function __construct(public int|float|bool $value = false, public array $labels = [], public int|float|null $timestamp = null) {
		}
	}
}

if (!interface_exists(IMetricFamily::class)) {
	interface IMetricFamily {
		public function name(): string;
		public function type(): MetricType;
		public function unit(): string;
		public function help(): string;
		public function metrics(): \Generator;
	}
}
