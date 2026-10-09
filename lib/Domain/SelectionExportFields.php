<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

final class SelectionExportFields {
	/** @param list<string> $requested
	 * @return list<string>
	 */
	public static function owner(array $requested, bool $ratings): array {
		$allowed = ['filename', 'path', 'mimeType', 'size', 'modifiedAt', 'ownerRating', 'ownerPick', 'ownerColor', 'selection', 'comments'];
		if ($ratings) $allowed = [...$allowed, 'guestAverage', 'guestCount'];
		return self::select($requested, $allowed);
	}

	/** @param list<string> $requested
	 * @param array<string, bool> $feedback
	 * @return list<string>
	 */
	public static function reviewer(array $requested, array $feedback): array {
		$allowed = ['filename'];
		if ($feedback['ratings']) $allowed[] = 'rating';
		if ($feedback['pick']) $allowed[] = 'pick';
		return self::select($requested, $allowed);
	}

	/** @param list<string> $requested
	 * @param list<string> $allowed
	 * @return list<string>
	 */
	private static function select(array $requested, array $allowed): array {
		$fields = array_values(array_unique(array_intersect($requested, $allowed)));
		return $fields === [] ? ['filename'] : $fields;
	}
}
