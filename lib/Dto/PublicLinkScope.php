<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Dto;

use OCA\ProofingGallery\Db\PublicLink;
use OCA\ProofingGallery\Service\MediaTypePolicy;
use OCP\Files\NotFoundException;

/** Immutable roots resolved once for one public request. */
final class PublicLinkScope {
	/** @param list<string> $roots
	 * @param list<array{path:string, name:string, role:string}> $details
	 * @param array{state:string, total:int, available:int, missing:int} $health */
	public function __construct(public readonly bool $multiRoot, public readonly string $startPath, public readonly array $roots, public readonly array $details, public readonly array $health) {
	}

	/** Legacy contexts without a resolver fail closed for stable node scopes. */
	public static function legacy(PublicLink $link): self {
		$multi = in_array($link->getScopeMode(), ['nodes', 'empty'], true) || $link->allowedRootList() !== [];
		$roots = in_array($link->getScopeMode(), ['nodes', 'empty'], true) ? [] : ($link->allowedRootList() ?: [$link->getStartPath()]);
		return new self($multi, $link->getStartPath(), $roots, [], ['state' => $roots === [] ? 'empty' : 'healthy', 'total' => count($roots), 'available' => count($roots), 'missing' => 0]);
	}

	public function indexPath(string $guestPath): string {
		$path = trim($guestPath, '/');
		if (!MediaTypePolicy::visiblePath($path)) throw new NotFoundException('Invalid gallery path');
		return $this->multiRoot ? $path : implode('/', array_filter([$this->startPath, $path], static fn (string $part): bool => $part !== ''));
	}

	public function visiblePath(string $path): bool {
		$path = trim($path, '/');
		if (!MediaTypePolicy::visiblePath($path)) return false;
		foreach ($this->roots as $root) {
			if ($root === '' || $path === $root || str_starts_with($path, $root . '/') || ($path !== '' && str_starts_with($root, $path . '/'))) return true;
		}
		return $path === '';
	}

	public function contains(string $relativeFilePath, bool $recursive): bool {
		$path = trim($relativeFilePath, '/');
		if (!MediaTypePolicy::visiblePath($path)) return false;
		foreach ($this->roots as $root) {
			if ($root !== '' && $path !== $root && !str_starts_with($path, $root . '/')) continue;
			return $this->multiRoot || $recursive || trim(dirname($path), './') === $root;
		}
		return false;
	}
}
