<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCA\ProofingGallery\Db\GuestRating;

final class GuestRatingAggregator {
	/**
	 * @param list<GuestRating> $values
	 * @param array<string, string> $actorNames
	 * @return array<string, mixed>
	 */
	public function summarize(int $fileId, array $values, array $actorNames): array {
		if ($values === []) throw new \InvalidArgumentException('Cannot summarize an empty guest rating set');
		$distribution = array_fill(0, 6, 0);
		$picks = ['none' => 0, 'pick' => 0, 'reject' => 0];
		$sum = 0;
		$updatedAt = 0;
		$individuals = [];
		foreach ($values as $value) {
			$distribution[$value->getRating()]++;
			$picks[$value->getPickState()]++;
			$sum += $value->getRating();
			$updatedAt = max($updatedAt, $value->getUpdatedAt());
			$individuals[] = [
				'guestId' => $value->getGuestId(),
				'actorUid' => $value->getActorUid(),
				'actorKind' => $value->getGuestId() === null ? 'user' : 'guest',
				'name' => ($actorNames[$value->actorKey()] ?? '') ?: ($value->getGuestId() === null ? 'Account' : 'Guest'),
				...$value->jsonSerialize(),
			];
		}
		return [
			'fileId' => $fileId,
			'count' => count($values),
			'average' => round($sum / count($values), 2),
			'distribution' => $distribution,
			'picks' => $picks,
			'updatedAt' => $updatedAt,
			'individuals' => $individuals,
		];
	}
}
