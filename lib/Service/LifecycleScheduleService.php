<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\ProofingGallery\Db\Gallery;
use OCA\ProofingGallery\Dto\GallerySettings;
use OCA\ProofingGallery\Dto\Settings\LifecycleSettings;

/** Projects JSON lifecycle settings into indexed, queryable timestamps. */
final class LifecycleScheduleService {
	public function project(Gallery $gallery, int $now, bool $catchUp = true): void {
		$gallery->setLifecycleRevokeAt(null);
		$gallery->setLifecycleArchiveAt(null);
		$gallery->setLifecycleNextAt(null);
		if ($gallery->getStatus() === 'archived') {
			return;
		}

		$settings = GallerySettings::fromArray(json_decode($gallery->getSettings(), true, flags: JSON_THROW_ON_ERROR));
		$rule = $settings->lifecycle;
		if (!$rule->enabled) {
			return;
		}

		$revokeAt = $this->revokeTimestamp($gallery->getCompletedAt(), $rule);
		$gallery->setLifecycleRevokeAt($revokeAt);
		if ($gallery->getShareToken() !== null && $revokeAt !== null) {
			$actions = [$revokeAt];
			foreach (array_values(array_unique($rule->reminderDays)) as $days) {
				$actions[] = $revokeAt - $days * 86400;
			}
			sort($actions, SORT_NUMERIC);
			$next = $catchUp ? $actions[0] : null;
			if (!$catchUp) {
				foreach ($actions as $actionAt) {
					if ($actionAt > $now) {
						$next = $actionAt;
						break;
					}
				}
			}
			$gallery->setLifecycleNextAt($next);
			return;
		}

		if ($gallery->getShareToken() === null && $gallery->getRevokedAt() !== null) {
			$archiveAt = $gallery->getRevokedAt() + $rule->archiveAfterDays * 86400;
			$gallery->setLifecycleArchiveAt($archiveAt);
			$gallery->setLifecycleNextAt($archiveAt);
		}
	}

	public function revokeTimestamp(?int $completedAt, LifecycleSettings $rule): ?int {
		if ($rule->trigger === 'after_completion') {
			return $completedAt === null ? null : $completedAt + $rule->revokeAfterDays * 86400;
		}
		if ($rule->revokeAt === '') {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $rule->revokeAt, new DateTimeZone('UTC'));
		return $date === false ? null : $date->setTime(23, 59, 59)->getTimestamp();
	}
}
