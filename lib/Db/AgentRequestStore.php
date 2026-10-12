<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

/** Durable mutation receipts; implementations must share the mutation connection. */
interface AgentRequestStore {
	/** @return array<string, mixed>|null */
	public function find(string $userUid, string $operation, string $requestKey): ?array;
	public function reserve(string $userUid, string $operation, string $requestKey, string $payloadHash, int $now): bool;
	/** @param array<string, mixed> $response */
	public function complete(string $userUid, string $operation, string $requestKey, array $response, int $statusCode): void;
	/** @param array<string, mixed>|null $response */
	public function mark(string $userUid, string $operation, string $requestKey, string $state, ?array $response = null): void;
	public function release(string $userUid, string $operation, string $requestKey): void;
	public function purgeExpired(int $now): int;
}
