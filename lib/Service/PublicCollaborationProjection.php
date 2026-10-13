<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Dto\PublicShareContext;
use OCA\ProofingGallery\Domain\CollaborationActor;

/** One public projection owns feature gating and media visibility. */
final class PublicCollaborationProjection {
	public function __construct(private CollaborationService $collaboration, private FeedbackPolicyService $feedback, private PublicMediaResolver $media, private GuestRatingService $guestRatings) {
	}

	/** @param list<int> $visibleFileIds
	 * @return array<string, mixed> */
	public function state(PublicShareContext $context, ?CollaborationActor $actor, int $cursor, array $visibleFileIds = []): array {
		$state = $this->collaboration->publicState($context->gallery, $actor, $cursor, $visibleFileIds);
		$allowed = [];
		$allows = function (int $fileId) use ($context, &$allowed): bool { return $allowed[$fileId] ??= $this->media->allows($context, $fileId); };
		if (($state['unchanged'] ?? false) === true) return $state;
		$features = $this->feedback->effective($context->settings, $context->policy);
		$ratingEnabled = $features['ratings'] || $features['pick'];
		$state['policy']['features'] = $features;
		$state['policy']['enabled'] = in_array(true, $features, true);
		$state['likes'] = $features['likes']
			? array_filter($state['likes'], fn (mixed $_, int $fileId): bool => $allows($fileId), ARRAY_FILTER_USE_BOTH)
			: [];
		$state['colors'] = $features['colors']
			? array_filter($state['colors'], fn (mixed $_, int $fileId): bool => $allows($fileId), ARRAY_FILTER_USE_BOTH)
			: [];
		$state['colorStates'] = $features['colors']
			? array_filter($state['colorStates'], fn (mixed $_, int $fileId): bool => $allows($fileId), ARRAY_FILTER_USE_BOTH)
			: [];
		$state['comments'] = $features['comments']
			? array_values(array_filter($state['comments'], fn (array $comment): bool => $allows((int)$comment['fileId'])))
			: [];
		if (!$features['annotations']) {
			foreach ($state['comments'] as &$comment) $comment['annotations'] = [];
			unset($comment);
		}
		$state['selections'] = $features['selections'] ? array_values(array_filter(array_map(function (array $selection) use ($allows): array {
			$selection['fileIds'] = array_values(array_filter($selection['fileIds'], fn (int $fileId): bool => $allows($fileId)));
			return $selection;
		}, $state['selections']), static fn (array $selection): bool => $selection['fileIds'] !== [])) : [];
		$visibleSelectionIds = array_fill_keys(array_column($state['selections'], 'id'), true);
		$state['events'] = array_values(array_filter($state['events'], function (array $event) use ($features, $ratingEnabled, $visibleSelectionIds, $allows): bool {
			$type = (string)($event['type'] ?? '');
			$family = strtok($type, '.');
			$feature = match ($family) {
				'like' => 'likes', 'color' => 'colors', 'comment' => 'comments', 'selection' => 'selections',
				default => null,
			};
			if ($family === 'rating' && !$ratingEnabled) return false;
			if ($feature !== null && !$features[$feature]) return false;
			if (isset($event['payload']['fileId']) && !$allows((int)$event['payload']['fileId'])) return false;
			if ($feature === 'selections' && isset($event['payload']['selectionId'])) {
				if ($type === 'selection.deleted') return true;
				return isset($visibleSelectionIds[(string)$event['payload']['selectionId']]);
			}
			return true;
		}));
		$state['ratings'] = $actor === null || !$ratingEnabled
			? []
			: array_values(array_map(
				static fn ($rating): array => $rating->jsonSerialize(),
				array_filter(
					$visibleFileIds === []
						? $this->guestRatings->forActor($context->gallery->getId(), $actor)
						: $this->guestRatings->forActorFiles($context->gallery->getId(), $actor, $visibleFileIds),
					fn ($rating): bool => $allows($rating->getFileId()),
				),
			));
		return $state;
	}
}
