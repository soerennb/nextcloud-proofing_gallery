<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });

use OCA\ProofingGallery\Db\QueryResult;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Service\PolicyService;
use OCP\DB\QueryBuilder\IQueryBuilder;

$db = \OCP\Server::get(\OCP\IDBConnection::class);
$config = \OCP\Server::get(\OCP\IConfig::class);
$policies = \OCP\Server::get(PolicyService::class);
$mode = $argv[1] ?? 'verify';
if ($mode === 'fresh') {
	if (!$policies->feature('guestRatings')) throw new RuntimeException('Fresh installation did not enable client ratings');
	if ($config->getAppValue('proofing_gallery', 'guestRatingsDefault', '') !== '') throw new RuntimeException('Fresh installation received the legacy disabled fallback');
	$qb = $db->getQueryBuilder();
	$qb->select('feedback_policy_mode')->from('proofing_public_links')->setMaxResults(1)->executeQuery()->closeCursor();
	echo "Fresh installation enables ratings and installs feedback inheritance schema.\n";
	exit(0);
}

$feedback = array_fill_keys(['likes', 'colors', 'comments', 'annotations', 'selections', 'ratings', 'pick'], true);
$inheritedPolicy = PublicLinkPolicy::fromArray($feedback)->jsonSerialize();
$customPolicy = array_replace($inheritedPolicy, ['ratings' => false, 'pick' => false]);
$choice = getenv('PG_FEEDBACK_ADMIN_CHOICE') ?: 'absent';
$insert = static function (string $table, array $values) use ($db): int {
	$qb = $db->getQueryBuilder();
	$qb->insert($table)->values(array_map(static fn (mixed $value) => $qb->createNamedParameter($value,
		is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)), $values))->executeStatement();
	return (int)$db->lastInsertId($table);
};

if ($mode === 'seed') {
	$raw = json_decode($config->getAppValue('proofing_gallery', 'instanceSettingsV2', '{}'), true, flags: JSON_THROW_ON_ERROR);
	if ($choice !== 'absent') $raw['features']['guestRatings'] = $choice === 'true';
	$config->setAppValue('proofing_gallery', 'instanceSettingsV2', json_encode($raw, JSON_THROW_ON_ERROR));
	$qb = $db->getQueryBuilder();
	$gallery = QueryResult::rows($qb->select('*')->from('proofing_galleries')->where($qb->expr()->eq('slug', $qb->createNamedParameter('upgrade-existing')))->executeQuery())[0];
	$qb = $db->getQueryBuilder();
	$link = QueryResult::rows($qb->select('*')->from('proofing_public_links')->where($qb->expr()->eq('token', $qb->createNamedParameter('upgrade-existing-token')))->executeQuery())[0];
	unset($gallery['id'], $link['id']);
	$hasDeliveryMode = array_key_exists('delivery_mode', $gallery);
	$cases = ['inherit', 'custom', ...($hasDeliveryMode ? ['event'] : []), 'comments-off', ...array_map(static fn (int $index): string => 'batch-' . $index, range(1, 201))];
	$config->setAppValue('proofing_gallery', 'upgradeFeedbackFixtureCount', (string)(count($cases) + 1));
	foreach ($cases as $case) {
		$gallery['settings'] = json_encode(GallerySettings::fromArray(['mode' => 'collaboration', 'review' => $case === 'comments-off' ? array_replace($feedback, ['comments' => false]) : $feedback]), JSON_THROW_ON_ERROR);
		$gallery['slug'] = 'upgrade-feedback-' . $case;
		if ($hasDeliveryMode) $gallery['delivery_mode'] = $case === 'event' ? 'event' : 'standard';
		$galleryId = $insert('proofing_galleries', $gallery);
		$link['gallery_id'] = $galleryId;
		$link['token'] = 'upgrade-feedback-' . $case;
		$link['is_primary'] = true;
		$link['policy'] = json_encode($case === 'custom' ? $customPolicy : ($case === 'comments-off' ? array_replace($inheritedPolicy, ['comments' => false]) : $inheritedPolicy), JSON_THROW_ON_ERROR);
		$insert('proofing_public_links', $link);
		if ($case === 'inherit') {
			$link['token'] = 'upgrade-feedback-secondary';
			$link['is_primary'] = false;
			$insert('proofing_public_links', $link);
		}
	}
	echo "Seeded feedback inheritance, custom restrictions, supported event cases and administrative choice {$choice}.\n";
	exit(0);
}

if ($config->getAppValue('proofing_gallery', 'guestRatingsDefault', '') !== '0') throw new RuntimeException('Upgrade did not preserve the old implicit rating default');
if ($policies->feature('guestRatings') !== ($choice === 'true')) throw new RuntimeException('Upgrade changed the administrative rating choice');
$document = json_decode($config->getAppValue('proofing_gallery', 'instanceSettingsV2', '{}'), true, flags: JSON_THROW_ON_ERROR);
if ($choice === 'absent' && array_key_exists('guestRatings', $document['features'] ?? [])) throw new RuntimeException('Upgrade overwrote the existing configuration document');
$qb = $db->getQueryBuilder();
$rows = QueryResult::rows($qb->select('token', 'policy', 'feedback_policy_mode')->from('proofing_public_links')
	->where($qb->expr()->like('token', $qb->createNamedParameter('upgrade-feedback-%')))->executeQuery());
if (count($rows) !== (int)$config->getAppValue('proofing_gallery', 'upgradeFeedbackFixtureCount', '206')) throw new RuntimeException('Feedback upgrade fixtures disappeared');
foreach ($rows as $row) {
	$case = substr((string)$row['token'], strlen('upgrade-feedback-'));
	$expectedMode = in_array($case, ['custom', 'event', 'secondary'], true) ? 'custom' : 'inherit';
	if ($row['feedback_policy_mode'] !== $expectedMode) throw new RuntimeException('Wrong upgraded feedback mode: ' . $case);
	$expectedPolicy = $case === 'custom' ? $customPolicy : ($case === 'comments-off' ? array_replace($inheritedPolicy, ['comments' => false]) : $inheritedPolicy);
	if (json_decode((string)$row['policy'], true, flags: JSON_THROW_ON_ERROR) !== $expectedPolicy) throw new RuntimeException('Upgrade changed link rights: ' . $case);
}
echo 'Feedback upgrade preserves administrative choices and ' . count($rows) . " link policies across multiple batches.\n";
