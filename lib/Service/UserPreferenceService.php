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
			'schemaVersion' => 3,
			'defaultPurpose' => null,
			'parentFolder' => null,
			'designPresetId' => null,
			'publicLocale' => 'auto',
			'notifications' => [
				'nextcloud' => ['enabled' => true, 'events' => ['upload.received', 'comment.created', 'selection.created']],
				'email' => ['enabled' => false, 'events' => ['upload.received', 'comment.created', 'selection.created'], 'frequency' => 'immediate'],
			],
			'lifecycle' => ['enabled' => false, 'trigger' => 'after_completion', 'revokeAfterDays' => 30, 'archiveAfterDays' => 30],
			'cullingFilmstripPlacement' => 'auto',
			'savedViews' => [],
		];
		$raw = $this->config->getUserValue($userId, Application::APP_ID, self::KEY, '');
		if ($raw === '') return $defaults;
		try {
			$stored = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
			if (!is_array($stored)) return $defaults;
			$merged = array_replace($defaults, $stored);
			$storedNotifications = is_array($stored['notifications'] ?? null) ? $stored['notifications'] : [];
			if (isset($storedNotifications['events']) || is_bool($storedNotifications['email'] ?? null)) {
				$legacyEvents = is_array($storedNotifications['events'] ?? null) ? $storedNotifications['events'] : $defaults['notifications']['email']['events'];
				$storedNotifications = [
					'nextcloud' => $defaults['notifications']['nextcloud'],
					'email' => ['enabled' => (bool)($storedNotifications['email'] ?? false), 'events' => $legacyEvents, 'frequency' => 'immediate'],
				];
			}
			$merged['notifications'] = [
				'nextcloud' => array_replace($defaults['notifications']['nextcloud'], is_array($storedNotifications['nextcloud'] ?? null) ? $storedNotifications['nextcloud'] : []),
				'email' => array_replace($defaults['notifications']['email'], is_array($storedNotifications['email'] ?? null) ? $storedNotifications['email'] : []),
			];
			$merged['lifecycle'] = array_replace($defaults['lifecycle'], is_array($stored['lifecycle'] ?? null) ? $stored['lifecycle'] : []);
			$merged['schemaVersion'] = 3;
			return $merged;
		} catch (\Throwable) {
			return $defaults;
		}
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array<string, mixed>
	 */
	public function save(string $userId, array $patch): array {
		$allowed = ['defaultPurpose', 'parentFolder', 'designPresetId', 'publicLocale', 'notifications', 'lifecycle', 'cullingFilmstripPlacement', 'savedViews'];
		$unknown = array_diff(array_keys($patch), $allowed);
		if ($unknown !== []) throw new \InvalidArgumentException('Unknown preference: ' . reset($unknown));
		$defaults = $this->get($userId);
		foreach (['notifications', 'lifecycle'] as $section) {
			if (array_key_exists($section, $patch) && !is_array($patch[$section])) throw new \InvalidArgumentException($section . ' must be an object');
			$unknownKeys = array_diff(array_keys($patch[$section] ?? []), array_keys($defaults[$section]));
			if ($unknownKeys !== []) throw new \InvalidArgumentException('Unknown ' . $section . ' preference: ' . reset($unknownKeys));
		}
		$current = array_replace($defaults, $patch);
		if (is_array($patch['notifications'] ?? null)) {
			$current['notifications'] = $defaults['notifications'];
			foreach (['nextcloud', 'email'] as $channel) {
				if (isset($patch['notifications'][$channel]) && !is_array($patch['notifications'][$channel])) {
					throw new \InvalidArgumentException($channel . ' notifications must be an object');
				}
				$unknownChannelKeys = array_diff(
					array_keys(is_array($patch['notifications'][$channel] ?? null) ? $patch['notifications'][$channel] : []),
					array_keys($defaults['notifications'][$channel]),
				);
				if ($unknownChannelKeys !== []) {
					throw new \InvalidArgumentException('Unknown ' . $channel . ' notification preference: ' . reset($unknownChannelKeys));
				}
				$current['notifications'][$channel] = array_replace(
					$defaults['notifications'][$channel],
					is_array($patch['notifications'][$channel] ?? null) ? $patch['notifications'][$channel] : [],
				);
			}
		}
		if (is_array($patch['lifecycle'] ?? null)) $current['lifecycle'] = array_replace($defaults['lifecycle'], $patch['lifecycle']);
		if (!is_array($current['savedViews']) || !array_is_list($current['savedViews']) || count($current['savedViews']) > 20) {
			throw new \InvalidArgumentException('savedViews must contain no more than 20 presets');
		}
		$viewIds = [];
		$current['savedViews'] = array_map(static function (mixed $view) use (&$viewIds): array {
			if (!is_array($view)) throw new \InvalidArgumentException('Invalid saved view');
			$id = (string)($view['id'] ?? '');
			$name = trim((string)($view['name'] ?? ''));
			$galleryId = (int)($view['galleryId'] ?? 0);
			$filters = is_array($view['filters'] ?? null) ? $view['filters'] : [];
			if (preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $id) !== 1 || isset($viewIds[$id]) || $name === '' || mb_strlen($name) > 80 || $galleryId < 1
				|| !in_array($filters['sortBy'] ?? null, ['name', 'modified', 'size'], true)
				|| !in_array($filters['sortDirection'] ?? null, ['asc', 'desc'], true)
				|| !in_array($filters['rating'] ?? null, [-1, 0, 1, 2, 3, 4, 5], true)
				|| !in_array($filters['pick'] ?? null, ['all', 'none', 'pick', 'reject'], true)
				|| !in_array($filters['color'] ?? null, ['all', 'none', 'red', 'yellow', 'green', 'blue', 'purple'], true)) {
				throw new \InvalidArgumentException('Invalid saved view');
			}
			$viewIds[$id] = true;
			return ['id' => $id, 'name' => $name, 'galleryId' => $galleryId, 'filters' => [
				'sortBy' => $filters['sortBy'], 'sortDirection' => $filters['sortDirection'],
				'rating' => $filters['rating'], 'pick' => $filters['pick'], 'color' => $filters['color'],
			], 'updatedAt' => max(0, (int)($view['updatedAt'] ?? time()))];
		}, $current['savedViews']);
		if ($current['defaultPurpose'] !== null && !in_array($current['defaultPurpose'], ['showcase', 'delivery', 'selection', 'proofing', 'uploads', 'custom'], true)) {
			throw new \InvalidArgumentException('Invalid default gallery purpose');
		}
		if (!in_array($current['publicLocale'], ['auto', 'en', 'de'], true)) throw new \InvalidArgumentException('Invalid public locale');
		if (!in_array($current['cullingFilmstripPlacement'], ['auto', 'side', 'bottom'], true)) throw new \InvalidArgumentException('Invalid culling filmstrip placement');
		if ($current['parentFolder'] !== null) {
			if (!is_array($current['parentFolder']) || (int)($current['parentFolder']['id'] ?? 0) <= 0) throw new \InvalidArgumentException('Invalid parent folder');
			$current['parentFolder'] = ['id' => (int)$current['parentFolder']['id'], 'name' => mb_substr(trim((string)($current['parentFolder']['name'] ?? '')), 0, 255)];
		}
		$current['designPresetId'] = $current['designPresetId'] === null ? null : max(1, (int)$current['designPresetId']);
		foreach (['nextcloud', 'email'] as $channel) {
			if (!is_bool($current['notifications'][$channel]['enabled']) || !is_array($current['notifications'][$channel]['events'])) {
				throw new \InvalidArgumentException('Invalid notification preferences');
			}
		}
		$allowedEvents = ['upload.received', 'comment.created', 'selection.created'];
		foreach (['nextcloud', 'email'] as $channel) {
			$current['notifications'][$channel]['events'] = array_values(array_intersect($allowedEvents, array_map('strval', $current['notifications'][$channel]['events'])));
		}
		if (!in_array($current['notifications']['email']['frequency'], ['immediate', 'daily'], true)) throw new \InvalidArgumentException('Invalid email notification frequency');
		foreach (['enabled'] as $key) if (!is_bool($current['lifecycle'][$key])) throw new \InvalidArgumentException('Invalid lifecycle preference');
		foreach (['revokeAfterDays', 'archiveAfterDays'] as $key) {
			$current['lifecycle'][$key] = (int)$current['lifecycle'][$key];
			if ($current['lifecycle'][$key] < 1 || $current['lifecycle'][$key] > 3650) throw new \InvalidArgumentException('Invalid lifecycle duration');
		}
		if (!in_array($current['lifecycle']['trigger'], ['fixed_date', 'after_completion'], true)) throw new \InvalidArgumentException('Invalid lifecycle trigger');
		$current['schemaVersion'] = 3;
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY, json_encode($current, JSON_THROW_ON_ERROR));
		return $current;
	}
}
