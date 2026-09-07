<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
});

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$repository = \OCP\Server::get(\OCA\ProofingGallery\Db\CollaborationRepository::class);
$service = \OCP\Server::get(\OCA\ProofingGallery\Service\CollaborationService::class);
$gallery = new \OCA\ProofingGallery\Db\Gallery();
$gallery->setId(random_int(1000000000, 2000000000));
$gallery->setOwnerUid('selection-sync-owner');
$uid = 'selection-sync-reviewer';
$scope = \OCA\ProofingGallery\Domain\CollaborationReadScope::user($uid);
$other = \OCA\ProofingGallery\Domain\CollaborationReadScope::user('selection-sync-other');
$db->beginTransaction();
try {
	$check = $db->getQueryBuilder();
	$existing = $check->select('id')->from('proofing_galleries')
		->where($check->expr()->eq('id', $check->createNamedParameter($gallery->getId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
		->executeQuery();
	try {
		if ($existing->fetchOne() !== false) throw new RuntimeException('Selection test gallery ID is already in use');
	} finally {
		$existing->closeCursor();
	}
	$id = bin2hex(random_bytes(16));
	$repository->insertSelection($gallery->getId(), null, $uid, null, $id, 'Original', '', [], time());
	$cursor = $repository->insertEvent($gallery->getId(), null, $uid, 'selection.created', ['selectionId' => $id], time());
	$service->updateOwnerSelection($gallery, $id, 'Renamed', 'completed');
	$updated = $repository->state($gallery->getId(), $scope, $cursor);
	if (($updated['unchanged'] ?? true) || count($updated['events']) !== 1 || ($updated['selections'][0]['status'] ?? null) !== 'completed') {
		throw new RuntimeException('Private account selection did not receive its owner update');
	}
	if ($updated['events'][0]['actor_uid'] !== $gallery->getOwnerUid() || $updated['events'][0]['recipient_uid'] !== $uid) {
		throw new RuntimeException('Owner attribution and recipient identity were not preserved');
	}
	$cursor = (int)$updated['events'][0]['id'];
	$service->deleteOwnerSelection($gallery, $id);
	$deleted = $repository->state($gallery->getId(), $scope, $cursor);
	if (($deleted['unchanged'] ?? true) || count($deleted['events']) !== 1 || $deleted['events'][0]['event_type'] !== 'selection.deleted' || $deleted['selections'] !== []) {
		throw new RuntimeException('Private account selection did not receive its deletion');
	}
	if ($repository->state($gallery->getId(), $other, 0)['events'] !== []
		|| $repository->state($gallery->getId(), \OCA\ProofingGallery\Domain\CollaborationReadScope::guest(42), 0)['events'] !== []
		|| $repository->state($gallery->getId() - 1, $scope, 0)['events'] !== []) {
		throw new RuntimeException('Private selection events escaped their account or gallery');
	}
	$guestId = 42;
	$guestSelection = bin2hex(random_bytes(16));
	$repository->insertSelection($gallery->getId(), $guestId, null, null, $guestSelection, 'Guest selection', '', [], time());
	$guestCursor = $repository->insertEvent($gallery->getId(), $guestId, null, 'selection.created', ['selectionId' => $guestSelection], time());
	$service->updateOwnerSelection($gallery, $guestSelection, 'Guest revised', 'completed');
	$guestState = $repository->state($gallery->getId(), \OCA\ProofingGallery\Domain\CollaborationReadScope::guest($guestId), $guestCursor);
	if (count($guestState['events']) !== 1 || ($guestState['selections'][0]['status'] ?? null) !== 'completed'
		|| $repository->state($gallery->getId(), $scope, $guestCursor)['events'] !== []) {
		throw new RuntimeException('Guest selection synchronization or account isolation regressed');
	}
} finally {
	$db->rollBack();
}
echo "Selection synchronization preserves account/guest privacy and owner attribution.\n";
