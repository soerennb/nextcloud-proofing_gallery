<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\IConfig;

final class PolicyService {
	private const SETTINGS_KEY = 'instanceSettingsV2';
	private const FEATURE_DEFAULTS = [
		'galleryCreation' => true,
		'publicPublishing' => true,
		'guestUploads' => true,
		'downloads' => true,
		'emailInvitations' => true,
		'nextcloudNotifications' => true,
		'likes' => true,
		'colors' => true,
		'comments' => true,
		'annotations' => true,
		'selections' => true,
		'lifecycleAutomation' => true,
		'ownerCulling' => true,
		'guestRatings' => false,
		'recursiveGalleries' => true,
		'multiplePublicLinks' => true,
	];
	/** @var array<string, array{default: int, min: int, max: int}> */
	private const DEFINITIONS = [
		'maxUploadBytes' => ['default' => 2147483648, 'min' => 1048576, 'max' => 21474836480],
		'maxSelectionFiles' => ['default' => 100, 'min' => 1, 'max' => 1000],
		'maxSelectionBytes' => ['default' => 1073741824, 'min' => 1048576, 'max' => 21474836480],
		'eventRetentionDays' => ['default' => 180, 'min' => 7, 'max' => 3650],
		'previewRetentionDays' => ['default' => 30, 'min' => 1, 'max' => 365],
		'pendingUploadRetentionHours' => ['default' => 24, 'min' => 1, 'max' => 168],
		'completedUploadRetentionDays' => ['default' => 365, 'min' => 7, 'max' => 3650],
		'maxVersionsPerFile' => ['default' => 10, 'min' => 1, 'max' => 100],
		'versionRetentionDays' => ['default' => 365, 'min' => 1, 'max' => 3650],
		'metadataMaxBytes' => ['default' => 67108864, 'min' => 1048576, 'max' => 536870912],
		'metadataBatchSize' => ['default' => 100, 'min' => 1, 'max' => 200],
		'xmpWritingEnabled' => ['default' => 1, 'min' => 0, 'max' => 1],
		'maxIndexedMedia' => ['default' => 25000, 'min' => 100, 'max' => 100000],
		'maxPublicLinks' => ['default' => 10, 'min' => 1, 'max' => 100],
		'shareAuditRetentionDays' => ['default' => 90, 'min' => 7, 'max' => 3650],
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

	/** @return array<string, mixed> */
	public function galleryDefaults(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, 'galleryDefaults', '');
		try {
			$values = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
			return GallerySettings::fromArray(is_array($values) ? $values : [])->jsonSerialize();
		} catch (\Throwable) {
			return GallerySettings::defaults()->jsonSerialize();
		}
	}

	/** @param array<string, mixed> $values */
	public function saveGalleryDefaults(array $values): void {
		$settings = GallerySettings::merge(GallerySettings::defaults(), $values);
		$this->config->setAppValue(Application::APP_ID, 'galleryDefaults', json_encode($settings, JSON_THROW_ON_ERROR));
	}

	/** @return array<string, mixed> */
	public function instanceSettings(): array {
		$defaults = [
			'schemaVersion' => 2,
			'access' => ['creatorGroups' => [], 'publisherGroups' => []],
			'features' => self::FEATURE_DEFAULTS,
			'workflow' => ['defaultPurpose' => 'delivery'],
			'branding' => ['studioName' => '', 'accentColor' => '#1f6f8b', 'logoAssetId' => null],
		];
		$raw = $this->config->getAppValue(Application::APP_ID, self::SETTINGS_KEY, '');
		if ($raw === '') return $defaults;
		try {
			$stored = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
			if (!is_array($stored)) return $defaults;
			return [
				...$defaults,
				'access' => array_replace($defaults['access'], is_array($stored['access'] ?? null) ? $stored['access'] : []),
				'features' => array_replace($defaults['features'], is_array($stored['features'] ?? null) ? $stored['features'] : []),
				'workflow' => array_replace($defaults['workflow'], is_array($stored['workflow'] ?? null) ? $stored['workflow'] : []),
				'branding' => array_replace($defaults['branding'], is_array($stored['branding'] ?? null) ? $stored['branding'] : []),
			];
		} catch (\Throwable) {
			return $defaults;
		}
	}

	/** @param array<string, mixed> $patch */
	public function saveInstanceSettings(array $patch): array {
		$allowedSections = ['access', 'features', 'workflow', 'branding'];
		$unknownSections = array_diff(array_keys($patch), $allowedSections);
		if ($unknownSections !== []) throw new \InvalidArgumentException('Unknown instance setting: ' . reset($unknownSections));
		$current = $this->instanceSettings();
		foreach ($allowedSections as $section) {
			if (array_key_exists($section, $patch)) {
				if (!is_array($patch[$section])) throw new \InvalidArgumentException($section . ' must be an object');
				$unknownKeys = array_diff(array_keys($patch[$section]), array_keys($current[$section]));
				if ($unknownKeys !== []) throw new \InvalidArgumentException('Unknown ' . $section . ' setting: ' . reset($unknownKeys));
				$current[$section] = array_replace($current[$section], $patch[$section]);
			}
		}
		foreach (array_keys($current['features']) as $key) {
			if (!is_bool($current['features'][$key])) throw new \InvalidArgumentException($key . ' must be a boolean');
		}
		$unknownFeatures = array_diff(array_keys($current['features']), array_keys(self::FEATURE_DEFAULTS));
		if ($unknownFeatures !== []) throw new \InvalidArgumentException('Unknown feature policy: ' . reset($unknownFeatures));
		foreach (['creatorGroups', 'publisherGroups'] as $key) {
			if (!is_array($current['access'][$key])) throw new \InvalidArgumentException($key . ' must be a list');
			$current['access'][$key] = array_values(array_unique(array_map(static function (mixed $value): string {
				$value = trim((string)$value);
				if ($value === '' || mb_strlen($value) > 64) throw new \InvalidArgumentException('Invalid group identifier');
				return $value;
			}, $current['access'][$key])));
		}
		if (!in_array($current['workflow']['defaultPurpose'], ['showcase', 'delivery', 'selection', 'proofing', 'uploads', 'custom'], true)) {
			throw new \InvalidArgumentException('Invalid default gallery purpose');
		}
		$current['branding']['studioName'] = trim((string)$current['branding']['studioName']);
		if (mb_strlen($current['branding']['studioName']) > 120) throw new \InvalidArgumentException('Studio name is too long');
		if (preg_match('/^#[0-9a-fA-F]{6}$/', (string)$current['branding']['accentColor']) !== 1) {
			throw new \InvalidArgumentException('Accent color must be a six-digit hex color');
		}
		if ($current['branding']['logoAssetId'] !== null
			&& (!is_string($current['branding']['logoAssetId']) || preg_match('/^[A-Za-z0-9]{32}\.(png|jpg|webp|svg)$/', $current['branding']['logoAssetId']) !== 1)) {
			throw new \InvalidArgumentException('Invalid branding logo asset');
		}
		$current['schemaVersion'] = 2;
		$this->config->setAppValue(Application::APP_ID, self::SETTINGS_KEY, json_encode($current, JSON_THROW_ON_ERROR));
		return $current;
	}

	public function feature(string $key): bool {
		if (!array_key_exists($key, self::FEATURE_DEFAULTS)) throw new \InvalidArgumentException('Unknown feature policy');
		return $this->instanceSettings()['features'][$key];
	}
}
