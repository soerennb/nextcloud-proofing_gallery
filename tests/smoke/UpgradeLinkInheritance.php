<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });

use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCP\DB\QueryBuilder\IQueryBuilder;

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$config = \OCP\Server::get(\OCP\IConfig::class);
$mode = $argv[1] ?? 'verify';
if ($mode === 'fresh') {
	$qb = $db->getQueryBuilder();
	$qb->select('permissions_policy_mode', 'navigation_policy_mode')->from('proofing_public_links')->setMaxResults(1)->executeQuery()->closeCursor();
	echo "Fresh installation has independent link permission and navigation inheritance.\n";
	exit(0);
}
$policy = PublicLinkPolicy::fromArray(['downloadScope' => 'all', 'upload' => true, 'export' => true, 'metadata' => true])->jsonSerialize();
$restricted = array_replace($policy, ['downloadScope' => 'none', 'upload' => false, 'export' => false, 'metadata' => false]);
$insert = static function (string $table, array $values) use ($db): int {
	$qb = $db->getQueryBuilder();
	$qb->insert($table)->values(array_map(static fn (mixed $value) => $qb->createNamedParameter($value,
		is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)), $values))->executeStatement();
	return (int)$db->lastInsertId($table);
};
if ($mode === 'seed') {
	$qb = $db->getQueryBuilder();
	$gallery = QueryResult::rows($qb->select('*')->from('proofing_galleries')->where($qb->expr()->eq('slug', $qb->createNamedParameter('upgrade-existing')))->executeQuery())[0];
	$qb = $db->getQueryBuilder();
	$link = QueryResult::rows($qb->select('*')->from('proofing_public_links')->where($qb->expr()->eq('token', $qb->createNamedParameter('upgrade-existing-token')))->executeQuery())[0];
	unset($gallery['id'], $link['id']);
	$expected = [];
	$hasDeliveryMode = array_key_exists('delivery_mode', $gallery);
	$hasScopes = array_key_exists('scope_mode', $link) && array_key_exists('allowed_roots', $link);
	$cases = ['inherit', 'permissions-only', 'navigation-only', 'custom', 'auto', 'secondary', ...($hasDeliveryMode ? ['event'] : []), ...($hasScopes ? ['multi'] : []), 'invalid-policy', 'invalid-settings', ...array_map(static fn (int $i): string => 'batch-' . $i, range(1, 201))];
	foreach ($cases as $case) {
		$gallery['slug'] = 'upgrade-inheritance-' . $case;
		if ($hasDeliveryMode) $gallery['delivery_mode'] = $case === 'event' ? 'event' : 'standard';
		$gallery['settings'] = $case === 'invalid-settings' ? '{' : json_encode(GallerySettings::fromArray([
			'delivery' => ['downloadScope' => 'all', 'guestUploads' => true], 'metadata' => ['publicFields' => ['copyright']],
			'navigation' => ['recursive' => false, 'groupBy' => 'folder', 'groupDepth' => 3],
		]), JSON_THROW_ON_ERROR);
		$link['gallery_id'] = $insert('proofing_galleries', $gallery);
		$link['token'] = 'upgrade-inheritance-' . $case;
		$link['is_primary'] = $case !== 'secondary';
		if ($hasScopes) {
			$link['scope_mode'] = $case === 'multi' ? 'nodes' : 'legacy';
			$link['allowed_roots'] = $case === 'multi' ? '["A"]' : null;
		}
		$link['view_mode'] = in_array($case, ['navigation-only', 'custom', 'auto'], true) ? 'recursive' : 'folder';
		$link['group_depth'] = in_array($case, ['auto', 'invalid-policy', 'invalid-settings'], true) ? 0 : (in_array($case, ['navigation-only', 'custom'], true) ? 2 : 3);
		$link['policy'] = $case === 'invalid-policy' ? '{' : json_encode(in_array($case, ['permissions-only', 'custom'], true) ? $restricted : $policy, JSON_THROW_ON_ERROR);
		$insert('proofing_public_links', $link);
		$unclear = in_array($case, ['invalid-policy', 'invalid-settings'], true);
		$expected[$link['token']] = ['policy' => $link['policy'], 'view_mode' => $link['view_mode'],
			'group_depth' => $case === 'auto' ? 3 : $link['group_depth'],
			'permissions_policy_mode' => in_array($case, ['permissions-only', 'custom', 'secondary', 'event'], true) || $unclear ? 'custom' : 'inherit',
			'navigation_policy_mode' => in_array($case, ['navigation-only', 'custom', 'auto', 'secondary', 'event', 'multi'], true) || $unclear ? 'custom' : 'inherit'];
	}
	$config->setAppValue('proofing_gallery', 'upgradeInheritanceFixtures', json_encode($expected, JSON_THROW_ON_ERROR));
	echo 'Seeded ' . count($expected) . " independent inheritance fixtures with legacy automatic depths and invalid data.\n";
	exit(0);
}
$expected = json_decode($config->getAppValue('proofing_gallery', 'upgradeInheritanceFixtures'), true, flags: JSON_THROW_ON_ERROR);
$qb = $db->getQueryBuilder();
$rows = QueryResult::rows($qb->select('token', 'policy', 'view_mode', 'group_depth', 'permissions_policy_mode', 'navigation_policy_mode')->from('proofing_public_links')
	->where($qb->expr()->like('token', $qb->createNamedParameter('upgrade-inheritance-%')))->executeQuery());
if (count($rows) !== count($expected)) throw new RuntimeException('Link inheritance upgrade lost fixtures');
foreach ($rows as $row) {
	$case = $expected[$row['token']];
	foreach ($case as $key => $value) {
		if ($key === 'group_depth' ? (int)$row[$key] !== $value : $row[$key] !== $value) throw new RuntimeException('Changed inheritance fixture: ' . $row['token'] . ' / ' . $key);
	}
}
echo 'Link inheritance upgrade preserves ' . count($rows) . " policies and scopes, classifies each area and freezes automatic depths.\n";
