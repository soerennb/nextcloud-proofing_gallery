<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\AgentRequestRepository;
use OCA\ProofingGallery\Exception\AgentRequestConflictException;
use OCA\ProofingGallery\Service\AgentIdempotencyService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

$db = \OC::$server->get(IDBConnection::class);
$mode = $argv[1] ?? 'verify';
$user = 'upgrade-agent-sentinel';
$operation = 'upgrade.test';
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };

if ($mode === 'seed') {
	if (!$db->tableExists('proofing_agent_requests')) {
		echo "Baseline predates agent receipts; the upgrade will install their schema.\n";
		exit(0);
	}
	// Old releases already have receipts; seed their original schema.
	foreach (['completed' => '{"id":7}', 'pending' => null] as $key => $response) {
		$qb = $db->getQueryBuilder();
		$qb->insert('proofing_agent_requests')->values([
			'user_uid' => $qb->createNamedParameter($user), 'operation' => $qb->createNamedParameter($operation),
			'request_key' => $qb->createNamedParameter($key), 'payload_hash' => $qb->createNamedParameter(hash('sha256', '[]')),
			'response_json' => $qb->createNamedParameter($response), 'status_code' => $qb->createNamedParameter($response === null ? null : 200, IQueryBuilder::PARAM_INT),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'expires_at' => $qb->createNamedParameter(time() + 86400, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}
	echo "Seeded legacy completed and pending agent receipts.\n";
	exit(0);
}

$repository = \OC::$server->get(AgentRequestRepository::class);
$service = \OC::$server->get(AgentIdempotencyService::class);
$never = static function (): never { throw new RuntimeException('An existing mutation must not run again'); };
try {
	if ($repository->find($user, $operation, 'completed') === null) {
		// Also exercise the new schema when upgrading a release before the agent API.
		$repository->reserve($user, $operation, 'completed', hash('sha256', '[]'), time());
		$repository->complete($user, $operation, 'completed', ['id' => 7], 200);
		$repository->reserve($user, $operation, 'pending', hash('sha256', '[]'), time());
	}
	$replay = $service->run($user, $operation, 'completed', [], $never);
	$assert($replay === ['data' => ['id' => 7], 'replayed' => true], 'Legacy receipt failed to replay after migration');
	try { $service->run($user, $operation, 'completed', ['changed' => true], $never); throw new RuntimeException('Different payload reused receipt'); }
	catch (AgentRequestConflictException) {}
	$repository->mark($user, $operation, 'pending', 'uncertain');
	$repository->reserve($user, $operation, 'applied', hash('sha256', '[]'), time());
	$repository->mark($user, $operation, 'applied', 'uncertain', ['id' => 8]);
	$qb = $db->getQueryBuilder();
	$qb->update('proofing_agent_requests')->set('expires_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
		->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($user)))->executeStatement();
	$repository->purgeExpired(time());
	$assert($repository->find($user, $operation, 'pending') !== null, 'Expiry erased an uncertain mutation');
	$assert($repository->find($user, $operation, 'applied') !== null, 'Expiry erased an applied response awaiting reconciliation');
	$assert($service->run($user, $operation, 'applied', [], $never)['data'] === ['id' => 8], 'Applied response did not reconcile by replay');
	$assert($repository->find($user, $operation, 'applied')['execution_state'] === 'completed', 'Replay did not complete the applied receipt');
	try { $service->run($user, $operation, 'pending', [], $never); throw new RuntimeException('Uncertain request was repeated'); }
	catch (AgentRequestConflictException) {}
	try {
		$service->run($user, $operation, 'rollback', [], static function () use ($db): never {
			$qb = $db->getQueryBuilder();
			$qb->insert('proofing_int_outbox')->values([
				'gallery_id' => $qb->createNamedParameter(null), 'event_type' => $qb->createNamedParameter('upgrade.agent'),
				'payload_json' => $qb->createNamedParameter('{}'), 'attempts' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'status' => $qb->createNamedParameter('pending'), 'available_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT), 'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			])->executeStatement();
			throw new RuntimeException('Injected mutation failure');
		});
		throw new RuntimeException('Failure did not propagate');
	} catch (RuntimeException $error) { $assert($error->getMessage() === 'Injected mutation failure', 'Unexpected rollback error: ' . $error->getMessage()); }
	$assert($repository->find($user, $operation, 'rollback') === null, 'A confirmed rollback did not release its key');
	$qb = $db->getQueryBuilder();
	$count = $qb->select($qb->func()->count())->from('proofing_int_outbox')->where($qb->expr()->eq('event_type', $qb->createNamedParameter('upgrade.agent')))->executeQuery()->fetchOne();
	$assert((int)$count === 0, 'Mutation rollback left an integration event');
	echo "Agent receipts survive migration, reject duplicates and preserve uncertain outcomes; mutations and outbox roll back together.\n";
} finally {
	$qb = $db->getQueryBuilder();
	$qb->delete('proofing_agent_requests')->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($user)))->executeStatement();
}
