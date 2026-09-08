<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void {
	fwrite(STDERR, $error->getMessage() . "\n");
	exit(1);
});

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$qb = $db->getQueryBuilder();
$galleryId = (int)$qb->select('id')->from('proofing_galleries')
	->where($qb->expr()->eq('slug', $qb->createNamedParameter('upgrade-existing')))->executeQuery()->fetchOne();
if ($galleryId < 1) throw new RuntimeException('Upgrade sentinel gallery missing');
$insert = static function (string $table, array $values) use ($db): void {
	$qb = $db->getQueryBuilder();
	$qb->insert($table)->values(array_map(static fn ($value) => $qb->createNamedParameter($value), $values))->executeStatement();
};
$rows = static function (string $table) use ($db, $galleryId): array {
	$qb = $db->getQueryBuilder();
	$result = $qb->select('*')->from($table)
		->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($galleryId)))->orderBy('id')->executeQuery();
	try { return $result->fetchAllAssociative(); } finally { $result->closeCursor(); }
};
$feedback = ['gallery_id' => $galleryId, 'file_id' => 1, 'guest_id' => null, 'actor_uid' => 'upgrade-reviewer', 'kind' => 'color', 'value' => 'old', 'created_at' => 1, 'updated_at' => 1];
if (($argv[1] ?? '') === 'seed') {
	$insert('proofing_feedback', $feedback);
	$insert('proofing_feedback', [...$feedback, 'value' => 'new', 'updated_at' => 2]);
	$insert('proofing_comments', ['gallery_id' => $galleryId, 'file_id' => 1, 'guest_id' => 424242, 'actor_uid' => null, 'parent_id' => null, 'body' => 'Historical guest pin', 'created_at' => 1]);
	$comment = (int)$db->lastInsertId('proofing_comments');
	$insert('proofing_annotations', ['gallery_id' => $galleryId, 'file_id' => 1, 'comment_id' => $comment, 'x' => 6700, 'y' => 4200, 'width' => 800, 'height' => 800]);
	echo "Seeded duplicate account feedback and historical guest annotation.\n";
	exit(0);
}
$values = $rows('proofing_feedback');
if (count($values) !== 1 || $values[0]['value'] !== 'new') throw new RuntimeException('Upgrade did not retain greatest-ID account feedback');
$comments = $rows('proofing_comments');
$annotations = $rows('proofing_annotations');
if (count($comments) !== 1 || $comments[0]['body'] !== 'Historical guest pin' || (int)$comments[0]['guest_id'] !== 424242 || $comments[0]['actor_uid'] !== null
	|| count($annotations) !== 1 || (int)$annotations[0]['x'] !== 6700 || (int)$annotations[0]['y'] !== 4200) {
	throw new RuntimeException('Upgrade changed historical guest authorship or coordinates');
}
$qb = $db->getQueryBuilder();
$versions = $qb->select('version')->from('migrations')->where($qb->expr()->eq('app', $qb->createNamedParameter('proofing_gallery')))->executeQuery()->fetchAllAssociative();
foreach (['000130Date20260903', '000140Date20260908'] as $version) {
	if (!in_array($version, array_column($versions, 'version'), true)) throw new RuntimeException('Missing collaboration migration: ' . $version);
}
foreach (['proofing_guest_ratings' => 'actor_uid', 'proofing_review_rounds' => 'submitted_by_actor_uid', 'proofing_events' => 'recipient_uid'] as $table => $column) {
	$qb = $db->getQueryBuilder();
	$qb->select($column)->from($table)->setMaxResults(1)->executeQuery()->closeCursor();
}
$db->beginTransaction();
$rejected = false;
try {
	$insert('proofing_feedback', $feedback);
} catch (\OCP\DB\Exception $exception) {
	if ($exception->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) throw $exception;
	$rejected = true;
} finally {
	$db->rollBack();
}
if (!$rejected) throw new RuntimeException('Account feedback uniqueness not enforced');
echo "Collaboration upgrade preserves guests, deduplicates accounts and installs both migrations.\n";
