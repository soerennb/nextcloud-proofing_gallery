<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use DateTime;
use InvalidArgumentException;
use OCA\ProofingGallery\Domain\PublicLinkCapability;
use OCA\ProofingGallery\Domain\PublicLinkPolicy;

final class PublicLinkConfiguration {
	private function __construct(
		public readonly string $name,
		public readonly PublicLinkPolicy $policy,
		public readonly string $startPath,
		/** @var list<string> */
		public readonly array $allowedRoots,
		public readonly string $viewMode,
		public readonly int $groupDepth,
		public readonly int $minOwnerRating,
		public readonly ?string $publicLocale,
		public readonly ?string $password,
		public readonly ?DateTime $expiresAt,
		public readonly bool $reviewEnabled,
		public readonly ?string $reviewDueDate,
		public readonly ?int $reviewSelectionMinimum,
		public readonly ?int $reviewSelectionMaximum,
	) {
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$name = self::string($input['name'] ?? null, 'Link name');
		if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Link name must contain 1 to 120 characters');
		$policyInput = $input['policy'] ?? [];
		if (!is_array($policyInput)) throw new InvalidArgumentException('Public link policy must be an object');
		$policy = PublicLinkPolicy::fromArray($policyInput);
		if (!$policy->allows(PublicLinkCapability::View)) throw new InvalidArgumentException('Public links must allow viewing');
		if ($policy->allows(PublicLinkCapability::Annotations) && !$policy->allows(PublicLinkCapability::Comments)) {
			throw new InvalidArgumentException('Image annotations require comments');
		}
		$startPath = self::string($input['startPath'] ?? '', 'Public link start path');
		$allowedRoots = $input['allowedRoots'] ?? [];
		if (!is_array($allowedRoots) || array_filter($allowedRoots, static fn (mixed $root): bool => !is_string($root)) !== []) {
			throw new InvalidArgumentException('Public link allowed roots must be a list of paths');
		}
		$allowedRoots = array_values(array_unique($allowedRoots));
		if (count($allowedRoots) > 32) throw new InvalidArgumentException('A public link may contain at most 32 allowed roots');
		$viewMode = self::string($input['viewMode'] ?? 'folder', 'Public link view mode');
		$groupDepth = self::int($input['groupDepth'] ?? 0, 'Public link group depth');
		$minOwnerRating = self::int($input['minOwnerRating'] ?? 0, 'Minimum owner rating');
		if (!in_array($viewMode, ['folder', 'recursive'], true) || $groupDepth < 0 || $groupDepth > 8 || $minOwnerRating < 0 || $minOwnerRating > 5) {
			throw new InvalidArgumentException('Invalid public link view');
		}
		$locale = $input['publicLocale'] ?? null;
		if ($locale !== null && (!is_string($locale) || !in_array($locale, ['en', 'de'], true))) throw new InvalidArgumentException('Invalid public locale');
		$password = $input['password'] ?? null;
		if ($password !== null && !is_string($password)) throw new InvalidArgumentException('Public link password must be a string or null');
		$reviewEnabled = $input['reviewEnabled'] ?? false;
		if (!is_bool($reviewEnabled)) throw new InvalidArgumentException('Review workflow setting must be a boolean');
		$reviewDueDate = self::dateString($input['reviewDueDate'] ?? null, 'Review due date');
		$reviewSelectionMinimum = self::nullableInt($input['reviewSelectionMinimum'] ?? null, 'Minimum selection count');
		$reviewSelectionMaximum = self::nullableInt($input['reviewSelectionMaximum'] ?? null, 'Maximum selection count');
		if (($reviewSelectionMinimum ?? 0) < 0 || ($reviewSelectionMaximum ?? 0) < 0
			|| ($reviewSelectionMinimum ?? 0) > 1000 || ($reviewSelectionMaximum ?? 0) > 1000
			|| ($reviewSelectionMaximum !== null && $reviewSelectionMaximum > 0 && ($reviewSelectionMinimum ?? 0) > $reviewSelectionMaximum)) {
			throw new InvalidArgumentException('Invalid selection limits');
		}
		return new self(
			$name,
			$policy,
			$startPath, $allowedRoots,
			$viewMode,
			$groupDepth,
			$minOwnerRating,
			$locale,
			$password,
			self::expirationDate($input['expiresAt'] ?? null),
			$reviewEnabled,
			$reviewDueDate,
			$reviewSelectionMinimum,
			$reviewSelectionMaximum,
		);
	}

	public function withStartPath(string $startPath): self {
		return $this->withScope($startPath, $this->allowedRoots);
	}

	/** @param list<string> $allowedRoots */
	public function withScope(string $startPath, array $allowedRoots): self {
		return new self(
			$this->name, $this->policy, $startPath, $allowedRoots, $this->viewMode, $this->groupDepth,
			$this->minOwnerRating, $this->publicLocale, $this->password, $this->expiresAt,
			$this->reviewEnabled, $this->reviewDueDate, $this->reviewSelectionMinimum, $this->reviewSelectionMaximum,
		);
	}

	private static function string(mixed $value, string $label): string {
		if (!is_string($value)) throw new InvalidArgumentException($label . ' must be a string');
		return trim($value);
	}

	private static function int(mixed $value, string $label): int {
		if (!is_int($value)) throw new InvalidArgumentException($label . ' must be an integer');
		return $value;
	}

	private static function nullableInt(mixed $value, string $label): ?int {
		if ($value === null || $value === '') return null;
		if (!is_int($value)) throw new InvalidArgumentException($label . ' must be an integer or null');
		return $value;
	}

	private static function expirationDate(mixed $value): ?DateTime {
		if ($value === null || $value === '') return null;
		if (!is_string($value)) throw new InvalidArgumentException('Expiration date must use YYYY-MM-DD');
		$date = DateTime::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Expiration date must use YYYY-MM-DD');
		return $date;
	}

	private static function dateString(mixed $value, string $label): ?string {
		if ($value === null || $value === '') return null;
		if (!is_string($value)) throw new InvalidArgumentException($label . ' must use YYYY-MM-DD');
		$date = DateTime::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException($label . ' must use YYYY-MM-DD');
		return $value;
	}
}
