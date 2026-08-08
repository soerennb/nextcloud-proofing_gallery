<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Exception as OcpDbException;
use OCP\IDBConnection;

final class AgentRequestRepository {
	public function __construct(private IDBConnection $db) {
	}

	/** @return array<string, mixed>|null */
	public function find(string $userUid, string $operation, string $requestKey): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = QueryResult::row($qb->select('*')->from('proofing_agent_requests')
			->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter($operation)))
			->andWhere($qb->expr()->eq('request_key', $qb->createNamedParameter($requestKey)))
			->executeQuery());
		return $row === false ? null : $row;
	}

	public function reserve(string $userUid, string $operation, string $requestKey, string $payloadHash, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		try {
			$qb->insert('proofing_agent_requests')->values([
				'user_uid' => $qb->createNamedParameter($userUid),
				'operation' => $qb->createNamedParameter($operation),
				'request_key' => $qb->createNamedParameter($requestKey),
				'payload_hash' => $qb->createNamedParameter($payloadHash),
				'response_json' => $qb->createNamedParameter(null),
				'status_code' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'expires_at' => $qb->createNamedParameter($now + 86400, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			return true;
		} catch (OcpDbException $exception) {
			if ($exception->getReason() === OcpDbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) return false;
			throw $exception;
		} catch (UniqueConstraintViolationException) {
			// Older database adapters may still expose the native Doctrine exception.
			return false;
		}
	}

	/** @param array<string, mixed> $response */
	public function complete(string $userUid, string $operation, string $requestKey, array $response, int $statusCode): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('proofing_agent_requests')
			->set('response_json', $qb->createNamedParameter(json_encode($response, JSON_THROW_ON_ERROR)))
			->set('status_code', $qb->createNamedParameter($statusCode, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter($operation)))
			->andWhere($qb->expr()->eq('request_key', $qb->createNamedParameter($requestKey)))
			->executeStatement();
	}

	public function release(string $userUid, string $operation, string $requestKey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('proofing_agent_requests')
			->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($userUid)))
			->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter($operation)))
			->andWhere($qb->expr()->eq('request_key', $qb->createNamedParameter($requestKey)))
			->andWhere($qb->expr()->isNull('response_json'))
			->executeStatement();
	}

	public function purgeExpired(int $now): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('proofing_agent_requests')
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
