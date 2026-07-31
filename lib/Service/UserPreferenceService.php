<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\IConfig;

final class UserPreferenceService {
	private const KEY = 'preferencesV1';

	public function __construct(private IConfig $config) {
	}

	/** @return array<string, mixed> */
	public function get(string $userId): array {
		$defaults = [
			'schemaVersion' => 1,
			'defaultPurpose' => null,
			'parentFolder' => null,
			'designPresetId' => null,
			'publicLocale' => 'auto',
			'notifications' => ['email' => false, 'events' => ['upload.received', 'comment.created', 'selection.created']],
			'lifecycle' => ['enabled' => false, 'trigger' => 'after_completion', 'revokeAfterDays' => 30, 'archiveAfterDays' => 30],
		];
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '');
		if ($raw === '') return $defaults;
		try {
			$stored = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
			if (!is_array($stored)) return $defaults;
			$merged = array_replace($defaults, $stored);
			foreach (['notifications', 'lifecycle'] as $section) {
				$merged[$section] = array_replace($defaults[$section], is_array($stored[$section] ?? null) ? $stored[$section] : []);
			}
			return $merged;
		} catch (\Throwable) {
			return $defaults;
		}
	}

	/** @param array<string, mixed> $patch */
	public function save(string $userId, array $patch): array {
		$allowed = ['defaultPurpose', 'parentFolder', 'designPresetId', 'publicLocale', 'notifications', 'lifecycle'];
		$unknown = array_diff(array_keys($patch), $allowed);
		if ($unknown !== []) throw new \InvalidArgumentException('Unknown preference: ' . reset($unknown));
		$defaults = $this->get($userId);
		foreach (['notifications', 'lifecycle'] as $section) {
			if (array_key_exists($section, $patch) && !is_array($patch[$section])) throw new \InvalidArgumentException($section . ' must be an object');
			$unknownKeys = array_diff(array_keys($patch[$section] ?? []), array_keys($defaults[$section]));
			if ($unknownKeys !== []) throw new \InvalidArgumentException('Unknown ' . $section . ' preference: ' . reset($unknownKeys));
		}
		$current = array_replace($defaults, $patch);
		foreach (['notifications', 'lifecycle'] as $section) {
			if (is_array($patch[$section] ?? null)) {
				$current[$section] = array_replace($defaults[$section], $patch[$section]);
			}
		}
		if ($current['defaultPurpose'] !== null && !in_array($current['defaultPurpose'], ['showcase', 'delivery', 'selection', 'proofing', 'uploads', 'custom'], true)) {
			throw new \InvalidArgumentException('Invalid default gallery purpose');
		}
		if (!in_array($current['publicLocale'], ['auto', 'en', 'de'], true)) throw new \InvalidArgumentException('Invalid public locale');
		if ($current['parentFolder'] !== null) {
			if (!is_array($current['parentFolder']) || (int)($current['parentFolder']['id'] ?? 0) <= 0) throw new \InvalidArgumentException('Invalid parent folder');
			$current['parentFolder'] = ['id' => (int)$current['parentFolder']['id'], 'name' => mb_substr(trim((string)($current['parentFolder']['name'] ?? '')), 0, 255)];
		}
		$current['designPresetId'] = $current['designPresetId'] === null ? null : max(1, (int)$current['designPresetId']);
		if (!is_bool($current['notifications']['email']) || !is_array($current['notifications']['events'])) throw new \InvalidArgumentException('Invalid notification preferences');
		$allowedEvents = ['upload.received', 'comment.created', 'selection.created'];
		$current['notifications']['events'] = array_values(array_intersect($allowedEvents, array_map('strval', $current['notifications']['events'])));
		foreach (['enabled'] as $key) if (!is_bool($current['lifecycle'][$key])) throw new \InvalidArgumentException('Invalid lifecycle preference');
		foreach (['revokeAfterDays', 'archiveAfterDays'] as $key) {
			$current['lifecycle'][$key] = (int)$current['lifecycle'][$key];
			if ($current['lifecycle'][$key] < 1 || $current['lifecycle'][$key] > 3650) throw new \InvalidArgumentException('Invalid lifecycle duration');
		}
		if (!in_array($current['lifecycle']['trigger'], ['fixed_date', 'after_completion'], true)) throw new \InvalidArgumentException('Invalid lifecycle trigger');
		$current['schemaVersion'] = 1;
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, json_encode($current, JSON_THROW_ON_ERROR));
		return $current;
	}
}
