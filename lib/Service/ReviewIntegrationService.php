<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\ExternalResourceRepository;
use OCA\ProofingGallery\Db\PublicLinkMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager as CalendarManager;
use OCP\Constants;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Talk\IBroker as TalkBroker;

final class ReviewIntegrationService {
	public function __construct(
		private GalleryAccessService $access,
		private PublicLinkMapper $links,
		private ExternalResourceRepository $resources,
		private CalendarManager $calendars,
		private IAppManager $apps,
		private IUserManager $users,
		private IURLGenerator $urls,
		private ITimeFactory $clock,
		private TalkBroker $talk,
	) {
	}

	/** @return array<string, mixed> */
	public function status(string $userUid, int $galleryId): array {
		$this->access->view($userUid, $galleryId);
		$user = $this->users->get($userUid);
		$calendarAvailable = $user !== null && $this->apps->isEnabledForUser('calendar', $user);
		return [
			'calendar' => ['available' => $calendarAvailable, 'items' => $calendarAvailable ? $this->calendarList($userUid) : []],
			'deck' => ['available' => $user !== null && $this->apps->isEnabledForUser('deck', $user)],
			'talk' => ['available' => $this->talkAvailable($user)],
			'links' => array_map(static fn (array $row): array => [
				'linkId' => (int)$row['public_link_id'], 'provider' => (string)$row['provider'],
				'status' => (string)$row['sync_status'], 'remote' => json_decode((string)$row['remote_data'], true, flags: JSON_THROW_ON_ERROR),
			], $this->resources->forGalleryUser($galleryId, $userUid)),
		];
	}

	/** @return array<string, mixed> */
	public function createCalendarEvent(string $userUid, int $galleryId, int $linkId, string $calendarUri): array {
		$gallery = $this->access->edit($userUid, $galleryId);
		$link = $this->link($galleryId, $linkId);
		if (!$link->getReviewEnabled() || $link->getReviewDueDate() === null) throw new \InvalidArgumentException('Set a review due date first');
		if ($this->resources->find($galleryId, $linkId, $userUid, 'calendar') !== null) return $this->status($userUid, $galleryId);
		$calendar = $this->calendar($userUid, $calendarUri);
		$start = new \DateTimeImmutable($link->getReviewDueDate() . ' 09:00:00');
		$filename = $this->calendars->createEventBuilder()
			->setStartDate($start)->setEndDate($start->modify('+1 hour'))
			->setSummary('Review deadline: ' . $gallery->getTitle())
			->setDescription('Client link: ' . $link->getName())
			->setLocation($this->internalUrl($galleryId))
			->createInCalendar($calendar);
		$this->resources->upsert($galleryId, $linkId, $userUid, 'calendar', ['calendarUri' => $calendarUri, 'event' => $filename, 'url' => $this->urls->linkToRouteAbsolute('calendar.view.index')], $this->clock->getTime());
		return $this->status($userUid, $galleryId);
	}

	/** @return array<string, mixed> */
	public function registerDeckCard(string $userUid, int $galleryId, int $linkId, int $boardId, int $stackId, int $cardId): array {
		$this->access->edit($userUid, $galleryId);
		$this->link($galleryId, $linkId);
		if ($boardId < 1 || $stackId < 1 || $cardId < 1) throw new \InvalidArgumentException('Invalid Deck resource');
		$url = $this->urls->linkToRouteAbsolute('deck.page.index') . '#/board/' . $boardId . '/card/' . $cardId;
		$this->resources->upsert($galleryId, $linkId, $userUid, 'deck', ['boardId' => $boardId, 'stackId' => $stackId, 'cardId' => $cardId, 'url' => $url], $this->clock->getTime());
		return $this->status($userUid, $galleryId);
	}

	/** @return array<string, mixed> */
	public function createTalkConversation(string $userUid, int $galleryId, int $linkId): array {
		$gallery = $this->access->edit($userUid, $galleryId);
		$link = $this->link($galleryId, $linkId);
		$user = $this->users->get($userUid) ?? throw new \InvalidArgumentException('User not found');
		if (!$this->talkAvailable($user)) throw new \InvalidArgumentException('Talk is unavailable');
		if ($this->resources->find($galleryId, $linkId, $userUid, 'talk') !== null) return $this->status($userUid, $galleryId);
		$options = $this->talk->newConversationOptions()->setPublic(false);
		if ($link->getReviewDueDate() !== null && method_exists($options, 'setMeetingDate')) {
			$start = new \DateTimeImmutable($link->getReviewDueDate() . ' 09:00:00');
			$options->setMeetingDate($start, $start->modify('+1 hour'));
		}
		$conversation = $this->talk->createConversation('Review: ' . $gallery->getTitle() . ' — ' . $link->getName(), [$user], $options);
		try {
			$this->resources->upsert($galleryId, $linkId, $userUid, 'talk', [
				'conversationId' => $conversation->getId(), 'url' => $conversation->getAbsoluteUrl(),
			], $this->clock->getTime());
		} catch (\Throwable $error) {
			try { $this->talk->deleteConversation($conversation->getId()); } catch (\Throwable) {}
			throw $error;
		}
		return $this->status($userUid, $galleryId);
	}

	/** @return array<string, mixed> */
	public function deleteTalkConversation(string $userUid, int $galleryId, int $linkId): array {
		$this->access->edit($userUid, $galleryId);
		$this->link($galleryId, $linkId);
		$resource = $this->resources->find($galleryId, $linkId, $userUid, 'talk');
		if ($resource === null) return $this->status($userUid, $galleryId);
		$remote = json_decode((string)$resource['remote_data'], true, flags: JSON_THROW_ON_ERROR);
		$conversationId = is_string($remote['conversationId'] ?? null) ? $remote['conversationId'] : '';
		if ($conversationId !== '' && $this->talk->hasBackend()) $this->talk->deleteConversation($conversationId);
		$this->resources->delete($galleryId, $linkId, $userUid, 'talk');
		return $this->status($userUid, $galleryId);
	}

	/** @return list<array{uri: string, name: string, color: ?string}> */
	private function calendarList(string $userUid): array {
		$result = [];
		foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $userUid) as $calendar) {
			if (!$calendar instanceof ICreateFromString || $calendar->isDeleted() || (($calendar->getPermissions() & Constants::PERMISSION_CREATE) === 0)) continue;
			$result[] = ['uri' => $calendar->getUri(), 'name' => $calendar->getDisplayName() ?? $calendar->getUri(), 'color' => $calendar->getDisplayColor()];
		}
		return $result;
	}

	private function calendar(string $userUid, string $uri): ICreateFromString {
		foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $userUid, [$uri]) as $calendar) {
			if ($calendar instanceof ICreateFromString && !$calendar->isDeleted() && $calendar->getUri() === $uri && (($calendar->getPermissions() & Constants::PERMISSION_CREATE) !== 0)) return $calendar;
		}
		throw new \InvalidArgumentException('Writable calendar not found');
	}

	private function link(int $galleryId, int $linkId): \OCA\ProofingGallery\Db\PublicLink {
		$link = $this->links->find($linkId);
		if ($link->getGalleryId() !== $galleryId || $link->getStatus() !== 'active') throw new \InvalidArgumentException('Public link not found');
		return $link;
	}

	private function internalUrl(int $galleryId): string {
		return $this->urls->linkToRouteAbsolute('proofing_gallery.page.index') . '#gallery/' . $galleryId . '/feedback';
	}

	private function talkAvailable(?\OCP\IUser $user): bool {
		if ($user === null || !$this->apps->isEnabledForUser('spreed', $user) || !$this->talk->hasBackend()) return false;
		return !method_exists($this->talk, 'isAllowedToCreateConversations') || $this->talk->isAllowedToCreateConversations();
	}
}
