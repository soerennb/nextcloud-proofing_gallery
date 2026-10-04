<?php

declare(strict_types=1);

$sortingVerified = false;
register_shutdown_function(static function () use (&$sortingVerified): void {
	if (!$sortingVerified) { fwrite(STDERR, "Media sorting verification did not finish\n"); exit(1); }
});
require '/var/www/html/lib/base.php';
set_exception_handler(static function (\Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\Db\MediaIndexMapper;
use OCA\ProofingGallery\Dto\MediaIndexQuery;
use OCA\ProofingGallery\Service\MediaCursorCodec;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

$db = \OC::$server->get(IDBConnection::class);
$qb = $db->getQueryBuilder();
$id = (int)(getenv('PG_SORT_GALLERY_ID') ?: $qb->select('id')->from('proofing_galleries')->where($qb->expr()->eq('slug', $qb->createNamedParameter('upgrade-active')))->executeQuery()->fetchOne());
if ($id <= 0) throw new RuntimeException('Missing upgrade sentinel');
$mapper = \OC::$server->get(MediaIndexMapper::class);
$codec = \OC::$server->get(MediaCursorCodec::class);
$fixtures = [
	[99991001, 'IMG10.jpg', 'a/IMG10.jpg', 200, 40, 400],
	[99991002, 'img02.jpg', 'a/img02.jpg', 100, 30, 300],
	[99991003, 'IMG2.jpg', 'z/IMG2.jpg', 100, 20, 200],
	[99991004, 'img1.jpg', 'z/img1.jpg', null, 10, 100],
	[99991005, 'unknown.jpg', 'a/unknown.jpg', null, 50, 500],
];
$assert = static function (mixed $actual, mixed $expected, string $context): void {
	if ($actual !== $expected) throw new RuntimeException($context . ': ' . json_encode([$actual, $expected], JSON_THROW_ON_ERROR));
};
try {
	foreach ($fixtures as [$fileId, $name, $path, $captured, $size, $mtime]) {
		$mapper->upsert($id, $fileId, 1, '__sorting-upgrade__/' . $path, 1, 'sorting-upgrade-test', time(), [
			'name' => $name, 'mimeType' => 'image/jpeg', 'size' => $size, 'mtime' => $mtime, 'etag' => 'sort-fixture', 'capturedAt' => $captured, 'captureState' => 'ready',
		]);
	}
	foreach ([
		'name' => [99991004, 99991002, 99991003, 99991001, 99991005],
		'capturedAt' => [99991002, 99991003, 99991001, 99991004, 99991005],
		'modified' => [99991004, 99991003, 99991002, 99991001, 99991005],
		'size' => [99991004, 99991003, 99991002, 99991001, 99991005],
	] as $sort => $ascending) {
		foreach (['asc', 'desc'] as $direction) {
			$expected = $direction === 'asc' ? $ascending : array_reverse($ascending);
			if ($sort === 'capturedAt' && $direction === 'desc') $expected = [99991001, 99991003, 99991002, 99991005, 99991004];
			$query = new MediaIndexQuery($id, 'admin', 2, '__sorting-upgrade__', '', $sort, $direction, 0, 'revision-a');
			$all = $mapper->page($query->withLimit(20));
			$ids = static fn (array $entries): array => array_map(static fn ($entry): int => $entry->getFileId(), $entries);
			$assert($ids($all), $expected, "$sort/$direction full");
			$collected = [];
			$cursor = null;
			do {
				[$value, $fileId] = $codec->decode($cursor, $query);
				$page = $mapper->page($query, $value, $fileId);
				$collected = [...$collected, ...$ids($page)];
				if (count($collected) > count($fixtures)) throw new RuntimeException("Repeated page for $sort/$direction");
				$cursor = count($page) === 2 ? $codec->encode($page[1], $query) : null;
			} while ($cursor !== null);
			$assert($collected, $expected, "$sort/$direction forward");
			$anchor = $mapper->page($query, offset: 4)[0];
			[$value, $fileId] = $codec->decode($codec->encode($anchor, $query, 'previous'), $query);
			$assert($ids($mapper->page($query, $value, $fileId, true)), array_slice($expected, 2, 2), "$sort/$direction previous");
			foreach ($expected as $position => $fileId) $assert($mapper->positionOf($query, $fileId), $position, "$sort/$direction focus");
			if ($sort === 'capturedAt') {
				$encrypted = $codec->encode($all[0], $query);
				$assert(str_starts_with($encrypted, 'c2.'), true, 'Protected capture cursor');
				try { $codec->decode(substr($encrypted, 0, -8) . 'AAAAAAAA', $query); throw new RuntimeException('Tampered capture cursor accepted'); }
				catch (InvalidArgumentException) {}
			}
			try {
				$codec->decode($codec->encode($all[0], $query), new MediaIndexQuery($id, 'admin', 2, '__sorting-upgrade__', '', $sort, $direction, 0, 'revision-b'));
				throw new RuntimeException('Stale cursor accepted');
			} catch (InvalidArgumentException) {}
		}
	}
	$qb = $db->getQueryBuilder();
	$settings = $qb->select('settings')->from('proofing_galleries')->where($qb->expr()->eq('slug', $qb->createNamedParameter('upgrade-collection-sort')))->executeQuery()->fetchOne();
	if ($settings === false && !getenv('PG_SORT_GALLERY_ID')) throw new RuntimeException('Missing legacy collection fixture');
	if ($settings !== false) {
		$value = json_decode($settings, true, flags: JSON_THROW_ON_ERROR);
		$assert($value['navigation']['sortBy'], 'collection', 'Legacy collection order');
		$assert($value['navigation']['sortDirection'], 'asc', 'Legacy collection direction');
	}
	$sortingVerified = true;
	echo "Media sorting migration, binary keys, cursors and focus verified\n";
} finally {
	$qb = $db->getQueryBuilder();
	$qb->delete('proofing_media_index')->where($qb->expr()->eq('gallery_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
		->andWhere($qb->expr()->eq('scan_generation', $qb->createNamedParameter('sorting-upgrade-test')))->executeStatement();
}
