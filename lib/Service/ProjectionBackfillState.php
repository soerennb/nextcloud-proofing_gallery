<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

final class ProjectionBackfillState {
	public const LIFECYCLE = 'lifecycleProjectionV1';
	public const GALLERY_LIST = 'galleryListProjectionV1';

	public function __construct(private IConfig $config, private ITimeFactory $time) {
	}

	public function isComplete(string $projection): bool {
		return $this->status($projection) === 'complete';
	}

	public function status(string $projection): string {
		$value = $this->config->getAppValue(Application::APP_ID, $this->stateKey($projection), 'pending');
		if ($projection === self::LIFECYCLE && $value === '1') return 'complete';
		return in_array($value, ['pending', 'running', 'error', 'complete'], true) ? $value : 'pending';
	}

	public function cursor(string $projection): int {
		return max(0, (int)$this->config->getAppValue(Application::APP_ID, $projection . 'Cursor', '0'));
	}

	public function markRunning(string $projection): void {
		$this->setStatus($projection, 'running');
	}

	public function markPending(string $projection): void {
		$this->setStatus($projection, 'pending');
	}

	public function advance(string $projection, int $cursor): void {
		$this->config->setAppValue(Application::APP_ID, $projection . 'Cursor', (string)max(0, $cursor));
		$this->touch($projection);
	}

	public function complete(string $projection): void {
		$this->setStatus($projection, $projection === self::LIFECYCLE ? '1' : 'complete');
		$this->config->deleteAppValue(Application::APP_ID, $projection . 'LastError');
	}

	public function fail(string $projection, \Throwable $error): void {
		$this->setStatus($projection, 'error');
		$attempts = (int)$this->config->getAppValue(Application::APP_ID, $projection . 'Attempts', '0');
		$this->config->setAppValue(Application::APP_ID, $projection . 'Attempts', (string)($attempts + 1));
		$this->config->setAppValue(Application::APP_ID, $projection . 'LastError', mb_substr($error->getMessage(), 0, 500));
	}

	/** @return array{status:string,cursor:int,updatedAt:?int,attempts:int,lastError:?string} */
	public function health(string $projection): array {
		$updatedAt = (int)$this->config->getAppValue(Application::APP_ID, $projection . 'UpdatedAt', '0');
		$error = $this->config->getAppValue(Application::APP_ID, $projection . 'LastError', '');
		return [
			'status' => $this->status($projection),
			'cursor' => $this->cursor($projection),
			'updatedAt' => $updatedAt > 0 ? $updatedAt : null,
			'attempts' => max(0, (int)$this->config->getAppValue(Application::APP_ID, $projection . 'Attempts', '0')),
			'lastError' => $error !== '' ? $error : null,
		];
	}

	private function setStatus(string $projection, string $status): void {
		$this->config->setAppValue(Application::APP_ID, $this->stateKey($projection), $status);
		$this->touch($projection);
	}

	private function touch(string $projection): void {
		$this->config->setAppValue(Application::APP_ID, $projection . 'UpdatedAt', (string)$this->time->getTime());
	}

	private function stateKey(string $projection): string {
		return $projection === self::LIFECYCLE ? $projection . 'Complete' : $projection . 'State';
	}
}
