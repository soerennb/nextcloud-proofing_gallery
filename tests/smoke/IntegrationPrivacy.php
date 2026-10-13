<?php

declare(strict_types=1);

define('OC_CONSOLE', 1);
require '/var/www/html/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . "\n"); exit(1); });

use OCA\ProofingGallery\ContextChat\GalleryContentSyncService;
use OCA\ProofingGallery\Db\CollaborationRepository;
use OCA\ProofingGallery\Db\ExternalResourceRepository;
use OCA\ProofingGallery\Db\GalleryMapper;
use OCA\ProofingGallery\Db\ManagerMapper;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCA\ProofingGallery\Db\PurgeRepository;
use OCA\ProofingGallery\Event\GalleryIntegrationEvent;
use OCA\ProofingGallery\Listener\GalleryFilesMetadataProvider;
use OCA\ProofingGallery\Service\GalleryService;
use OCA\ProofingGallery\Service\GuestService;
use OCA\ProofingGallery\Service\PrivacyService;
use OCA\ProofingGallery\Service\PublicShareService;
use OCA\ProofingGallery\Service\ReviewIntegrationService;
use OCA\ProofingGallery\Workflow\GalleryOperation;
use OCP\ContextChat\IContentManager;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\Event\MetadataLiveEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IOperation;
use OCP\WorkflowEngine\IRuleMatcher;

// Simulate optional apps without installing them or changing tenant app config.
$apps = new class extends \OC\App\AppManager {
	public function __construct() {}
	public function isInstalled($appId): bool { return $appId === 'context_chat'; }
	public function isEnabledForUser($appId, $user = null) { return $appId === 'spreed'; }
};
$get = static fn (string $class) => \OC::$server->get($class);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$mode = $argv[1] ?? '';
if (!in_array($mode, ['flow', 'files', 'context', 'talk'], true)) throw new InvalidArgumentException('Choose flow, files, context or talk');
$owner = 'admin';
$get(\OCP\IUserSession::class)->setUser($get(\OCP\IUserManager::class)->get($owner));
$folder = $get(IRootFolder::class)->getUserFolder($owner)->newFolder('__integration-privacy-' . bin2hex(random_bytes(6)));
$gallery = null;
try {
	$photo = $folder->newFile('private.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true));
	$gallery = $get(GalleryService::class)->create($owner, 'Private integration fixture', (int)$folder->getId(), [
		'mode' => 'collaboration', 'review' => ['comments' => true, 'visibility' => 'private'],
	]);
	$id = (int)$gallery->getId();

	if ($mode === 'flow') {
		$matcher = new class implements IRuleMatcher {
			public function getFlows(bool $returnFirstMatchingOperationOnly = true): array { return [['id' => 1, 'operation' => 'archive']]; }
			public function getMatchingOperations(string $class, bool $returnFirstMatchingOperationOnly = true): array { return $this->getFlows(); }
			public function setOperation(IOperation $operation): void {}
			public function setEntity(IEntity $entity): void {}
			public function getEntity(): IEntity { throw new LogicException('No entity lookup expected'); }
			public function setEventName(string $eventName): void {}
			public function setFileInfo(\OCP\Files\Storage\IStorage $storage, string $path, bool $isDir = false): void {}
			public function setEntitySubject(IEntity $entity, $subject): void {}
		};
		$logger = new class extends \Psr\Log\AbstractLogger {
			public ?Throwable $failure = null;
			public function log($level, string|Stringable $message, array $context = []): void { $this->failure = $context['exception'] ?? null; }
		};
		$flow = new GalleryOperation($get(\OCP\L10N\IFactory::class)->get('proofing_gallery'), $get(\OCP\IURLGenerator::class), $get(GalleryMapper::class), $get(\OCA\ProofingGallery\Service\AgentMutationService::class), $logger);
		$event = static fn (string $actor) => new GalleryIntegrationEvent('gallery.updated', $id, ['actorUid' => $actor, 'eventId' => bin2hex(random_bytes(8))]);
		$flow->onEvent(GalleryIntegrationEvent::class, $event('integration-outsider'), $matcher);
		$unchanged = $get(GalleryMapper::class)->find($id);
		$assert($unchanged->getStatus() === 'draft' && $unchanged->getRevision() === $gallery->getRevision(), 'Flow used the owner privileges for an unauthorized actor');
		$assert($logger->failure instanceof \OCA\ProofingGallery\Exception\AuthorizationException, 'Flow did not reach the permission boundary: ' . ($logger->failure?->getMessage() ?? 'no failure'));
		$logger->failure = null;
		$flow->onEvent(GalleryIntegrationEvent::class, $event($owner), $matcher);
		if ($logger->failure !== null) throw $logger->failure;
		$assert($get(GalleryMapper::class)->find($id)->getStatus() === 'archived', 'Authorized Flow did not execute');
	}

	if ($mode === 'files') {
		$metadata = new \OC\FilesMetadata\Model\FilesMetadata((int)$folder->getId());
		$get(GalleryFilesMetadataProvider::class)->handle(new MetadataLiveEvent($folder, $metadata));
		$keys = $metadata->getKeys(); sort($keys);
		$assert($keys === ['proofing-gallery-source', 'proofing-gallery-states'], 'Files metadata exposed extra gallery data');
		$assert($metadata->getBool(GalleryFilesMetadataProvider::SOURCE_KEY), 'Gallery folder was not identified');
		$assert($metadata->getStringList(GalleryFilesMetadataProvider::STATE_KEY) === [$gallery->getWorkflowState()], 'Files metadata lost its workflow state');
	}

	if ($mode === 'context') {
		$guest = $get(GuestService::class)->create($gallery, 'Private reviewer', 'private-reviewer@example.invalid')['guest'];
		$privateComment = 'Private feedback ' . bin2hex(random_bytes(8));
		$get(CollaborationRepository::class)->insertComment($id, (int)$guest->getId(), null, (int)$photo->getId(), $privateComment, null, time());
		$gallery = $get(PublicShareService::class)->publish($gallery, null, null, 'none');
		$manager = new class implements IContentManager {
			public array $items = [];
			public array $users = [];
			public array $deleted = [];
			public function isContextChatAvailable(): bool { return true; }
			public function registerContentProvider(string $appId, string $providerId, string $providerClass): void {}
			public function collectAllContentProviders(): void {}
			public function submitContent(string $appId, array $items): void { $this->items = $items; }
			public function updateAccess(string $appId, string $providerId, string $itemId, string $op, array $userIds): void {}
			public function updateAccessProvider(string $appId, string $providerId, string $op, array $userIds): void {}
			public function updateAccessDeclarative(string $appId, string $providerId, string $itemId, array $userIds): void { $this->users = $userIds; }
			public function deleteProvider(string $appId, string $providerId): void {}
			public function deleteContent(string $appId, string $providerId, array $itemIds): void { $this->deleted = $itemIds; }
		};
		// This registration is in memory and ends with this isolated PHP process.
		\OC::$server->registerService(IContentManager::class, static fn () => $manager);
		$sync = new GalleryContentSyncService($get(GalleryMapper::class), $get(ManagerMapper::class), $get(\OCP\IGroupManager::class), $get(\OCA\ProofingGallery\Service\IntegrationReadService::class), $apps, \OC::$server);
		$sync->sync($id);
		$assert(count($manager->items) === 1 && $manager->items[0]->itemId === (string)$id, 'Context Chat did not receive the gallery');
		$assert($manager->items[0]->users === [$owner] && $manager->users === [$owner], 'Context Chat widened gallery access');
		$payload = json_encode($manager->items, JSON_THROW_ON_ERROR);
		foreach ([$gallery->getShareToken(), $privateComment, 'Private reviewer', 'private-reviewer@example.invalid'] as $secret) {
			$assert(!str_contains($payload, $secret), 'Context Chat received private gallery data');
		}
		$get(GalleryService::class)->archive($owner, $id);
		$sync->sync($id);
		$assert($manager->deleted === [(string)$id], 'Archived gallery remained in Context Chat');
	}

	if ($mode === 'talk') {
		$gallery = $get(PublicShareService::class)->publish($gallery, null, null, 'none');
		$link = $get(PublicLinkMapper::class)->findPrimary($id);
		$talk = new class implements \OCP\Talk\IBroker {
			public bool $public = true;
			public bool $failDeletion = false;
			public int $created = 0;
			public array $deleted = [];
			public function hasBackend(): bool { return true; }
			public function isAllowedToCreateConversations(): bool { return true; }
			public function isEnabledForUser(): bool { return true; }
			public function newConversationOptions(): \OCP\Talk\IConversationOptions {
				return new class implements \OCP\Talk\IConversationOptions {
					private bool $public = true;
					private ?DateTimeInterface $start = null;
					private ?DateTimeInterface $end = null;
					public function isPublic(): bool { return $this->public; }
					public function setPublic(bool $isPublic = true): self { $this->public = $isPublic; return $this; }
					public function setMeetingDate(DateTimeInterface $meetingStartDate, DateTimeInterface $meetingEndDate): self { $this->start = $meetingStartDate; $this->end = $meetingEndDate; return $this; }
					public function getMeetingStartDate(): ?DateTimeInterface { return $this->start; }
					public function getMeetingEndDate(): ?DateTimeInterface { return $this->end; }
				};
			}
			public function createConversation(string $name, array $moderators, ?\OCP\Talk\IConversationOptions $options = null): \OCP\Talk\IConversation {
				$this->public = $options?->isPublic() ?? true;
				return new class('integration-room-' . ++$this->created) implements \OCP\Talk\IConversation {
					public function __construct(private string $id) {}
					public function getId(): string { return $this->id; }
					public function getAbsoluteUrl(): string { return 'https://talk.example.invalid/' . $this->id; }
				};
			}
			public function deleteConversation(string $id): void {
				if ($this->failDeletion) throw new RuntimeException('Injected Talk cleanup failure');
				$this->deleted[] = $id;
			}
		};
		$resources = $get(ExternalResourceRepository::class);
		$reviews = new ReviewIntegrationService($get(\OCA\ProofingGallery\Service\GalleryAccessService::class), $get(PublicLinkMapper::class), $resources, $get(\OCP\Calendar\IManager::class), $apps, $get(\OCP\IUserManager::class), $get(\OCP\IURLGenerator::class), $get(\OCP\AppFramework\Utility\ITimeFactory::class), $talk);
		$reviews->createTalkConversation($owner, $id, (int)$link->getId());
		$assert(!$talk->public && $talk->created === 1, 'Talk room was public or was not created');
		$reviews->deleteTalkConversation($owner, $id, (int)$link->getId());
		$assert($talk->deleted === ['integration-room-1'] && $resources->forGalleryProvider($id, 'talk') === [], 'Explicit Talk removal left a room or its mapping');
		$reviews->createTalkConversation($owner, $id, (int)$link->getId());
		$get(GalleryService::class)->archive($owner, $id);
		$purge = $get(PurgeRepository::class);
		$purge->create($id, $gallery->getTitle(), $owner, [], time(), time() - 1);
		$privacy = new PrivacyService($purge, $get(GalleryMapper::class), $get(PublicShareService::class), $get(\OCA\ProofingGallery\Service\PublicLinkManagerService::class), $get(\OCP\AppFramework\Utility\ITimeFactory::class), $get(\OCP\BackgroundJob\IJobList::class), $get(\OCP\Files\AppData\IAppDataFactory::class)->get('proofing_gallery'), $get(\OCP\Security\ICrypto::class), $resources, $talk, $get(\OCA\ProofingGallery\Service\PrivateGuestInboxService::class));
		$talk->failDeletion = true;
		try { $privacy->processDue(); throw new LogicException('Talk cleanup failure was ignored'); }
		catch (RuntimeException $error) { $assert($error->getMessage() === 'Injected Talk cleanup failure', 'Unexpected purge failure'); }
		$assert((int)$purge->active($id)['stage'] === 0 && count($resources->forGalleryProvider($id, 'talk')) === 1, 'Failed Talk cleanup lost its retry state');
		$talk->failDeletion = false;
		$privacy->processDue();
		$assert($talk->deleted === ['integration-room-1', 'integration-room-2'] && $resources->forGalleryProvider($id, 'talk') === [], 'Privacy purge did not remove the Talk room');
		$assert($folder->nodeExists('private.gif'), 'Privacy purge deleted the original photo');
	}
} finally {
	if ($gallery !== null) {
		$purge = $get(PurgeRepository::class);
		foreach (PurgeRepository::TABLES as $table) while ($purge->deleteBatch($table, (int)$gallery->getId(), 1000) > 0) {}
		$db = $get(\OCP\IDBConnection::class);
		$query = $db->getQueryBuilder();
		$query->delete('proofing_purge_requests')->where($query->expr()->eq('gallery_id', $query->createNamedParameter((int)$gallery->getId())))->executeStatement();
	}
	$folder->delete();
}
echo "Integration privacy verified: {$mode}.\n";
