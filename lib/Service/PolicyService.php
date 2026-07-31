<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\IConfig;

final class PolicyService {
	/** @var array<string, array{default: int, min: int, max: int}> */
	private const DEFINITIONS = [
		'maxUploadBytes' => ['default' => 2147483648, 'min' => 1048576, 'max' => 21474836480],
		'maxSelectionFiles' => ['default' => 100, 'min' => 1, 'max' => 1000],
		'maxSelectionBytes' => ['default' => 1073741824, 'min' => 1048576, 'max' => 21474836480],
		'eventRetentionDays' => ['default' => 180, 'min' => 7, 'max' => 3650],
		'previewRetentionDays' => ['default' => 30, 'min' => 1, 'max' => 365],
		'pendingUploadRetentionHours' => ['default' => 24, 'min' => 1, 'max' => 168],
		'completedUploadRetentionDays' => ['default' => 365, 'min' => 7, 'max' => 3650],
	];

	public function __construct(private IConfig $config) {
	}

	public function get(string $key): int {
		$definition = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException('Unknown policy');
		$value = (int)$this->config->getAppValue(
			Application::APP_ID,
			$key,
			(string)$definition['default'],
		);
		return $value < $definition['min'] || $value > $definition['max']
			? $definition['default']
			: $value;
	}

	/** @param array<string, int> $values */
	public function save(array $values): void {
		foreach ($values as $key => $value) {
			$definition = self::DEFINITIONS[$key] ?? throw new \InvalidArgumentException('Unknown policy');
			if ($value < $definition['min'] || $value > $definition['max']) {
				throw new \InvalidArgumentException($key . ' is outside the allowed range');
			}
			$this->config->setAppValue(Application::APP_ID, $key, (string)$value);
		}
	}

	/** @return array<string, int> */
	public function all(): array {
		$values = [];
		foreach (array_keys(self::DEFINITIONS) as $key) {
			$values[$key] = $this->get($key);
		}
		return $values;
	}
}
