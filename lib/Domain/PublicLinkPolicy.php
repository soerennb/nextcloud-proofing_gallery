<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

final class PublicLinkPolicy implements \JsonSerializable {
	private const BOOLEAN_KEYS = ['view', 'likes', 'colors', 'comments', 'annotations', 'selections', 'ratings', 'pick', 'upload', 'export', 'metadata'];

	/** @param array<string, bool> $permissions */
	private function __construct(
		private readonly array $permissions,
		public readonly DownloadScope $downloadScope,
	) {
	}

	/** @param array<string, mixed> $input */
	public static function fromArray(array $input): self {
		$unknown = array_diff(array_keys($input), [...self::BOOLEAN_KEYS, 'downloadScope']);
		if ($unknown !== []) {
			throw new \InvalidArgumentException('Unknown public link permission: ' . reset($unknown));
		}
		$permissions = array_fill_keys(self::BOOLEAN_KEYS, false);
		$permissions['view'] = true;
		foreach (self::BOOLEAN_KEYS as $key) {
			if (!array_key_exists($key, $input)) continue;
			if (!is_bool($input[$key])) throw new \InvalidArgumentException($key . ' must be a boolean');
			$permissions[$key] = $input[$key];
		}
		$scope = $input['downloadScope'] ?? DownloadScope::None->value;
		if (!is_string($scope) || DownloadScope::tryFrom($scope) === null) {
			throw new \InvalidArgumentException('Invalid public link download scope');
		}
		return new self($permissions, DownloadScope::from($scope));
	}

	public function allows(string $permission): bool {
		if (!array_key_exists($permission, $this->permissions)) {
			throw new \InvalidArgumentException('Unknown public link permission: ' . $permission);
		}
		return $this->permissions[$permission];
	}

	public function restrict(self $other): self {
		$permissions = [];
		foreach (self::BOOLEAN_KEYS as $key) {
			$permissions[$key] = $this->permissions[$key] && $other->permissions[$key];
		}
		return new self($permissions, $this->downloadScope->restrict($other->downloadScope));
	}

	/** @return array<string, bool|string> */
	public function jsonSerialize(): array {
		return [...$this->permissions, 'downloadScope' => $this->downloadScope->value];
	}
}
