<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\EventSetupRepository;
use OCA\ProofingGallery\Db\Gallery;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

final class EventSetupService {
	private const STEPS = ['photos', 'visibility', 'recipients', 'delivery', 'review'];
	private const ROLES = ['shared', 'group', 'private', 'ignored'];

	public function __construct(
		private EventSetupRepository $repository,
		private FolderService $folders,
		private EventDeliveryService $events,
		private PublicLinkManagerService $links,
		private ITimeFactory $clock,
		private ICrypto $crypto,
		private ISecureRandom $random,
	) {
	}

	/** @return array<string, mixed> */
	public function deliveryPlan(Gallery $gallery, int $expectedRevision): array {
		$setup = $this->get($gallery);
		if ((int)$setup['revision'] !== $expectedRevision) throw new \OCA\ProofingGallery\Exception\GalleryConflictException('Event setup changed in another session');
		if (!(bool)$setup['readiness']['ready']) throw new \InvalidArgumentException('Complete the event setup before creating client links');
		$pathById = []; foreach ($setup['folders'] as $folder) $pathById[(int)$folder['id']] = (string)$folder['path'];
		$roles = []; foreach ($setup['folderAssignments'] as $assignment) $roles[(int)$assignment['folderId']] = (string)$assignment['role'];
		$sharedRoots = []; foreach ($roles as $id => $role) if ($role === 'shared' && isset($pathById[$id])) $sharedRoots[] = $pathById[$id];
		$recipients = [];
		foreach ($setup['recipients'] as $recipient) {
			$id = (int)$recipient['folderId']; if (($roles[$id] ?? null) !== 'private' || !isset($pathById[$id]) || trim((string)$recipient['name']) === '') continue;
			$groupRoots = []; foreach ($recipient['groupFolderIds'] as $groupId) if (($roles[(int)$groupId] ?? null) === 'group' && isset($pathById[(int)$groupId])) $groupRoots[] = $pathById[(int)$groupId];
			$pin = (string)$recipient['pin'];
			if ($setup['delivery']['pinMode'] === 'generated') $pin = $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
			if ($setup['delivery']['pinMode'] === 'none') $pin = '';
			$recipients[] = ['folderPath' => $pathById[$id], 'groupRoots' => $groupRoots, 'name' => $recipient['name'], 'email' => $recipient['email'], 'locale' => $recipient['locale'], 'pin' => $pin];
		}
		if (count($recipients) > (int)$setup['capacity']) throw new \InvalidArgumentException('The event does not have enough public link capacity');
		return ['sharedRoots' => $sharedRoots, 'recipients' => $recipients, 'delivery' => $setup['delivery']];
	}

	/** @return array<string, mixed> */
	public function get(Gallery $gallery): array {
		$this->assertEvent($gallery);
		$row = $this->repository->find((int)$gallery->getId());
		$payload = $row === false ? $this->defaults() : $this->decode((string)$row['payload_cipher']);
		return $this->present($gallery, $payload, $row === false ? 0 : (int)$row['revision']);
	}

	/** @param array<string, mixed> $setup
	 * @return array<string, mixed>
	 */
	public function save(Gallery $gallery, array $setup, int $expectedRevision): array {
		$this->assertEvent($gallery);
		$normalized = $this->normalize($gallery, $setup);
		$revision = $this->repository->save((int)$gallery->getId(), $expectedRevision, $this->crypto->encrypt(json_encode($normalized, JSON_THROW_ON_ERROR)), $this->clock->getTime());
		return $this->present($gallery, $normalized, $revision);
	}

	/** @return array<string, mixed> */
	private function defaults(): array {
		return [
			'currentStep' => 'photos', 'folderAssignments' => [], 'recipients' => [],
			'delivery' => ['pinMode' => 'none', 'expiresAt' => '', 'releaseMode' => 'draft', 'releaseAt' => '', 'sendInvitations' => false],
		];
	}

	/** @param array<string, mixed> $setup
	 * @return array<string, mixed>
	 */
	private function normalize(Gallery $gallery, array $setup): array {
		$root = $this->folders->resolveFolder($gallery->getOwnerUid(), $gallery->getFolderId());
		$step = is_string($setup['currentStep'] ?? null) && in_array($setup['currentStep'], self::STEPS, true) ? $setup['currentStep'] : 'photos';
		$assignments = [];
		$seen = [];
		foreach (is_array($setup['folderAssignments'] ?? null) ? $setup['folderAssignments'] : [] as $assignment) {
			if (!is_array($assignment)) continue;
			$id = (int)($assignment['folderId'] ?? 0); $role = (string)($assignment['role'] ?? 'ignored');
			if ($id < 1 || isset($seen[$id]) || !in_array($role, self::ROLES, true)) continue;
			$this->folderById($root, $id); $seen[$id] = true;
			$assignments[] = ['folderId' => $id, 'role' => $role];
		}
		$recipients = [];
		foreach (is_array($setup['recipients'] ?? null) ? $setup['recipients'] : [] as $recipient) {
			if (!is_array($recipient)) continue;
			$folderId = (int)($recipient['folderId'] ?? 0); $folder = $this->folderById($root, $folderId);
			$name = trim((string)($recipient['name'] ?? '')); $email = mb_strtolower(trim((string)($recipient['email'] ?? ''))); $pin = trim((string)($recipient['pin'] ?? ''));
			if (mb_strlen($name) > 120) throw new \InvalidArgumentException('Recipient name is too long');
			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Recipient email address is invalid');
			if ($pin !== '' && (mb_strlen($pin) < 10 || mb_strlen($pin) > 64)) throw new \InvalidArgumentException('Recipient PIN must contain 10 to 64 characters');
			$groups = [];
			foreach (is_array($recipient['groupFolderIds'] ?? null) ? $recipient['groupFolderIds'] : [] as $groupId) {
				$groupId = (int)$groupId; if ($groupId < 1 || $groupId === $folderId || in_array($groupId, $groups, true)) continue;
				$this->folderById($root, $groupId); $groups[] = $groupId;
			}
			$locale = in_array($recipient['locale'] ?? null, ['de', 'en'], true) ? $recipient['locale'] : null;
			$key = preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string)($recipient['key'] ?? '')) === 1 ? (string)$recipient['key'] : bin2hex(random_bytes(12));
			$recipients[] = ['key' => $key, 'folderId' => (int)$folder->getId(), 'groupFolderIds' => $groups, 'name' => $name, 'email' => $email, 'locale' => $locale, 'pin' => $pin];
		}
		$deliveryInput = is_array($setup['delivery'] ?? null) ? $setup['delivery'] : [];
		$pinMode = in_array($deliveryInput['pinMode'] ?? null, ['none', 'generated', 'manual'], true) ? $deliveryInput['pinMode'] : 'none';
		$releaseMode = in_array($deliveryInput['releaseMode'] ?? null, ['draft', 'now', 'schedule'], true) ? $deliveryInput['releaseMode'] : 'draft';
		$expiresAt = trim((string)($deliveryInput['expiresAt'] ?? '')); $releaseAt = trim((string)($deliveryInput['releaseAt'] ?? ''));
		if ($expiresAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt) !== 1) throw new \InvalidArgumentException('Invalid expiration date');
		return ['currentStep' => $step, 'folderAssignments' => $assignments, 'recipients' => $recipients, 'delivery' => [
			'pinMode' => $pinMode, 'expiresAt' => $expiresAt, 'releaseMode' => $releaseMode, 'releaseAt' => $releaseAt,
			'sendInvitations' => (bool)($deliveryInput['sendInvitations'] ?? false),
		]];
	}

	/** @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function present(Gallery $gallery, array $payload, int $revision): array {
		$preview = $this->events->preview($gallery); $folders = $preview['folders'];
		$pathById = []; foreach ($folders as $folder) $pathById[(int)$folder['id']] = (string)$folder['path'];
		$roles = []; foreach ($payload['folderAssignments'] as $assignment) $roles[(int)$assignment['folderId']] = (string)$assignment['role'];
		$checks = $this->readiness($payload, $roles, $pathById);
		return ['revision' => $revision, ...$payload, 'folders' => array_map(static fn (array $folder): array => [...$folder, 'role' => $roles[(int)$folder['id']] ?? 'ignored'], $folders),
			'readiness' => ['ready' => !in_array('blocked', array_column($checks, 'state'), true), 'checks' => $checks],
			'capacity' => $this->links->eventCapacity($gallery),
		];
	}

	/** @param array<string, mixed> $payload
	 * @param array<int, string> $roles
	 * @param array<int, string> $paths
	 * @return list<array{code: string, state: string}>
	 */
	private function readiness(array $payload, array $roles, array $paths): array {
		$private = array_keys(array_filter($roles, static fn (string $role): bool => $role === 'private'));
		$groups = array_keys(array_filter($roles, static fn (string $role): bool => $role === 'group'));
		$shared = array_keys(array_filter($roles, static fn (string $role): bool => $role === 'shared'));
		$valid = 0; $emailMissing = false; $overlap = false;
		$deliveredRoots = [...$shared, ...$groups, ...$private];
		foreach ($deliveredRoots as $index => $left) {
			foreach (array_slice($deliveredRoots, $index + 1) as $right) {
				if (isset($paths[$left], $paths[$right]) && $this->pathsOverlap($paths[$left], $paths[$right])) $overlap = true;
			}
		}
		foreach ($payload['recipients'] as $recipient) {
			if (in_array((int)$recipient['folderId'], $private, true) && trim((string)$recipient['name']) !== '') $valid++;
			if (($payload['delivery']['sendInvitations'] ?? false) && trim((string)$recipient['email']) === '') $emailMissing = true;
			if (array_diff($recipient['groupFolderIds'], $groups) !== []) $overlap = true;
			$rootIds = [(int)$recipient['folderId'], ...array_map('intval', $recipient['groupFolderIds'])];
			foreach ($rootIds as $left) foreach ($rootIds as $right) if ($left !== $right && isset($paths[$left], $paths[$right]) && $this->pathsOverlap($paths[$left], $paths[$right])) $overlap = true;
		}
		return [
			['code' => 'folders_classified', 'state' => $roles === [] ? 'blocked' : 'ready'],
			['code' => 'private_deliveries', 'state' => $valid > 0 ? 'ready' : 'blocked'],
			['code' => 'recipient_contacts', 'state' => $emailMissing ? 'blocked' : 'ready'],
			['code' => 'privacy_scopes', 'state' => $overlap ? 'blocked' : 'ready'],
		];
	}

	private function pathsOverlap(string $left, string $right): bool {
		return $left === $right || str_starts_with($left . '/', $right . '/') || str_starts_with($right . '/', $left . '/');
	}

	/** @return array<string, mixed> */
	private function decode(string $cipher): array {
		$value = json_decode($this->crypto->decrypt($cipher), true, flags: JSON_THROW_ON_ERROR);
		return is_array($value) ? array_replace_recursive($this->defaults(), $value) : $this->defaults();
	}

	private function folderById(Folder $root, int $id): Folder {
		foreach ($root->getById($id) as $node) if ($node instanceof Folder && $root->isSubNode($node)) return $node;
		throw new \InvalidArgumentException('Event folder is unavailable');
	}

	private function assertEvent(Gallery $gallery): void {
		if ($gallery->getDeliveryMode() !== 'event') throw new \InvalidArgumentException('Event setup requires an event project');
	}
}
