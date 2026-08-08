<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\AgentRequestRepository;
use OCA\ProofingGallery\Exception\AgentRequestConflictException;
use OCP\AppFramework\Utility\ITimeFactory;

final class AgentIdempotencyService {
	public function __construct(
		private AgentRequestRepository $requests,
		private ITimeFactory $clock,
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param callable(): array<string, mixed> $operationCallback
	 * @return array{data: array<string, mixed>, replayed: bool}
	 */
	public function run(string $userUid, string $operation, string $requestId, array $payload, callable $operationCallback): array {
		$requestId = trim($requestId);
		if ($requestId === '' || strlen($requestId) > 64 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $requestId) !== 1) {
			throw new \InvalidArgumentException('requestId must contain 1 to 64 URL-safe characters');
		}
		$payloadHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
		$now = $this->clock->getTime();
		$this->requests->purgeExpired($now);

		if (!$this->requests->reserve($userUid, $operation, $requestId, $payloadHash, $now)) {
			$existing = $this->requests->find($userUid, $operation, $requestId);
			if ($existing === null || !hash_equals((string)$existing['payload_hash'], $payloadHash)) {
				throw new AgentRequestConflictException('requestId was already used with a different request');
			}
			if ($existing['response_json'] === null) {
				throw new AgentRequestConflictException('The request is still being processed');
			}
			$data = json_decode((string)$existing['response_json'], true, flags: JSON_THROW_ON_ERROR);
			return ['data' => is_array($data) ? $data : [], 'replayed' => true];
		}

		try {
			$data = $operationCallback();
			$this->requests->complete($userUid, $operation, $requestId, $data, 200);
			return ['data' => $data, 'replayed' => false];
		} catch (\Throwable $exception) {
			$this->requests->release($userUid, $operation, $requestId);
			throw $exception;
		}
	}

	private function canonicalize(mixed $value): mixed {
		if (!is_array($value)) return $value;
		if (!array_is_list($value)) ksort($value);
		foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
		return $value;
	}
}
