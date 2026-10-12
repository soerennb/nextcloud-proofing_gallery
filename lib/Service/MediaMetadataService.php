<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\FilesMetadata\IFilesMetadataManager;

final class MediaMetadataService {
	private const DETAILS_KEY = 'proofing-gallery-details-v2';
	private const MAX_SIDECAR_BYTES = 1048576;
	private const EDITABLE_FIELDS = ['title', 'description', 'creator', 'copyright', 'keywords', 'rating', 'label'];
	private const PUBLIC_FIELDS = ['capturedAt', 'camera', 'lens', 'exposure', 'title', 'description', 'creator', 'copyright'];
	private const CULL_COLORS = ['none' => null, 'red' => 'Red', 'yellow' => 'Yellow', 'green' => 'Green', 'blue' => 'Blue', 'purple' => 'Purple'];

	public function __construct(
		private IFilesMetadataManager $metadataManager,
		private PolicyService $policies,
		private EmbeddedMetadataExtractor $extractor,
		private \OCP\EventDispatcher\IEventDispatcher $events,
		private XmpDocument $xmp = new XmpDocument(),
	) {
	}

	/** @return array<string, mixed> */
	public function summary(File $file): array {
		try {
			$metadata = $this->metadataManager->getMetadata($file->getId(), false);
			if (!$metadata->hasKey(self::DETAILS_KEY) || $metadata->getEtag(self::DETAILS_KEY) !== $file->getEtag()) {
				return ['state' => 'pending'];
			}
			$details = $metadata->getArray(self::DETAILS_KEY);
			$parent = $file->getParent();
			if ($parent instanceof Folder) {
				$name = pathinfo($file->getName(), PATHINFO_FILENAME) . '.xmp';
				$current = $parent->nodeExists($name) ? $parent->get($name) : null;
				if (($details['sidecar']['etag'] ?? null) !== ($current instanceof File ? $current->getEtag() : null)) return ['state' => 'pending'];
			}
			return ['state' => 'ready', ...$details];
		} catch (\Throwable) {
			return ['state' => 'pending'];
		}
	}

	/** Cache-only access for the bounded filecache scan; never reads image bytes.
	 * @return array<string, mixed>
	 */
	public function cachedSummary(int $fileId, string $etag): array {
		try {
			$value = $this->metadataManager->getMetadata($fileId, false);
			if ($value->hasKey(self::DETAILS_KEY) && $value->getEtag(self::DETAILS_KEY) === $etag) return ['state' => 'ready', ...$value->getArray(self::DETAILS_KEY)];
		} catch (\Throwable) {}
		return ['state' => 'pending'];
	}

	public function invalidate(int $fileId): void {
		try { $metadata = $this->metadataManager->getMetadata($fileId, false); } catch (\Throwable) { return; }
		if ($metadata->hasKey(self::DETAILS_KEY)) {
			$metadata->setEtag(self::DETAILS_KEY, '');
			$this->metadataManager->saveMetadata($metadata);
		}
	}

	/** @return array<string, mixed> */
	public function index(File $file): array {
		if (!str_starts_with($file->getMimeType(), 'image/')) {
			$this->events->dispatchTyped(new \OCA\ProofingGallery\Event\MediaMetadataIndexedEvent($file->getId(), $file->getEtag(), null, 'ready'));
			return ['state' => 'unavailable'];
		}
		$metadata = $this->metadataManager->getMetadata($file->getId(), true);
		$details = $this->extract($file, $metadata->asArray());
		$metadata->setArray(self::DETAILS_KEY, $details);
		$metadata->setEtag(self::DETAILS_KEY, $file->getEtag());
		$this->metadataManager->saveMetadata($metadata);
		$this->events->dispatchTyped(new \OCA\ProofingGallery\Event\MediaMetadataIndexedEvent($file->getId(), $file->getEtag(), isset($details['capturedAt']) ? (int)$details['capturedAt'] : null));
		return ['state' => 'ready', ...$details];
	}

	/**
	 * @param list<string> $fields
	 * @return array<string, mixed>
	 */
	public function publicSummary(File $file, array $fields): array {
		$summary = $this->summary($file);
		if (($summary['state'] ?? '') !== 'ready' || $fields === []) return ['state' => $summary['state'] ?? 'pending'];
		$allowed = ['state' => 'ready'];
		foreach (array_intersect($fields, self::PUBLIC_FIELDS) as $field) {
			if ($field === 'exposure') {
				foreach (['focalLength', 'aperture', 'exposureTime', 'iso'] as $key) {
					if (array_key_exists($key, $summary)) $allowed[$key] = $summary[$key];
				}
			} elseif (array_key_exists($field, $summary)) {
				$allowed[$field] = $summary[$field];
			}
		}
		return $allowed;
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	public function writeSidecar(
		File $file,
		array $changes,
		string $expectedSourceEtag,
		?string $expectedSidecarEtag,
	): array {
		if ($this->policies->get('xmpWritingEnabled') !== 1) {
			throw new InvalidArgumentException('XMP sidecar writing is disabled by the administrator');
		}
		if (!hash_equals($file->getEtag(), $expectedSourceEtag)) {
			throw new MetadataConflictException('The source file changed');
		}
		$changes = $this->validatedChanges($changes);
		$parent = $file->getParent();
		if (!$parent instanceof Folder || !$parent->isUpdateable()) {
			throw new InvalidArgumentException('The source folder is not writable');
		}
		$sidecarName = pathinfo($file->getName(), PATHINFO_FILENAME) . '.xmp';
		$this->assertUnambiguousBaseName($parent, $file);
		$sidecar = null;
		if ($parent->nodeExists($sidecarName)) {
			$node = $parent->get($sidecarName);
			if (!$node instanceof File || $node->getSize() > self::MAX_SIDECAR_BYTES) {
				throw new InvalidArgumentException('The XMP sidecar is unavailable or too large');
			}
			$sidecar = $node;
			if ($expectedSidecarEtag === null || !hash_equals($sidecar->getEtag(), $expectedSidecarEtag)) {
				throw new MetadataConflictException('The XMP sidecar changed');
			}
		} elseif ($expectedSidecarEtag !== null) {
			throw new MetadataConflictException('The XMP sidecar was removed');
		}

		$document = $sidecar === null ? $this->xmp->newXmpDocument() : $this->xmp->loadXmp($sidecar->getContent());
		$this->xmp->applyEditableFields($document, $changes);
		$xml = $document->saveXML();
		if (!is_string($xml) || strlen($xml) > self::MAX_SIDECAR_BYTES) {
			throw new InvalidArgumentException('The generated XMP sidecar is too large');
		}
		if ($sidecar === null) {
			$sidecar = $parent->newFile($sidecarName, $xml);
		} else {
			$sidecar->putContent($xml);
		}
		return $this->index($file);
	}

	/**
	 * Export proofing state into interoperable XMP fields while preserving
	 * unrelated properties already written by Adobe or other applications.
	 *
	 * @param array{galleryId: int, galleryTitle: string, selectionId: string, selectionName: string, likeCount: int, label: ?string} $proofing
	 * @return array<string, mixed>
	 */
	public function writeProofingSidecar(
		File $file,
		array $proofing,
		string $expectedSourceEtag,
		?string $expectedSidecarEtag,
	): array {
		if ($this->policies->get('xmpWritingEnabled') !== 1) {
			throw new InvalidArgumentException('XMP sidecar writing is disabled by the administrator');
		}
		if (!hash_equals($file->getEtag(), $expectedSourceEtag)) {
			throw new MetadataConflictException('The source file changed');
		}
		$galleryTitle = $this->xmp->requiredProofingText($proofing['galleryTitle'] ?? null, 500, 'gallery title');
		$selectionId = $this->xmp->requiredProofingText($proofing['selectionId'] ?? null, 120, 'selection id');
		$selectionName = $this->xmp->requiredProofingText($proofing['selectionName'] ?? null, 120, 'selection name');
		$galleryId = $proofing['galleryId'] ?? null;
		$likeCount = $proofing['likeCount'] ?? null;
		$label = $proofing['label'] ?? null;
		if (!is_int($galleryId) || $galleryId <= 0 || !is_int($likeCount) || $likeCount < 0
			|| ($label !== null && (!is_string($label) || mb_strlen($label) > 500))) {
			throw new InvalidArgumentException('Invalid proofing metadata');
		}

		$parent = $file->getParent();
		if (!$parent instanceof Folder || !$parent->isUpdateable()) {
			throw new InvalidArgumentException('The source folder is not writable');
		}
		$this->assertUnambiguousBaseName($parent, $file);
		$sidecarName = pathinfo($file->getName(), PATHINFO_FILENAME) . '.xmp';
		$sidecar = null;
		if ($parent->nodeExists($sidecarName)) {
			$node = $parent->get($sidecarName);
			if (!$node instanceof File || $node->getSize() > self::MAX_SIDECAR_BYTES) {
				throw new InvalidArgumentException('The XMP sidecar is unavailable or too large');
			}
			$sidecar = $node;
			if ($expectedSidecarEtag === null || !hash_equals($sidecar->getEtag(), $expectedSidecarEtag)) {
				throw new MetadataConflictException('The XMP sidecar changed');
			}
		} elseif ($expectedSidecarEtag !== null) {
			throw new MetadataConflictException('The XMP sidecar was removed');
		}

		$document = $sidecar === null ? $this->xmp->newXmpDocument() : $this->xmp->loadXmp($sidecar->getContent());
		$this->xmp->applyProofingFields($document, $galleryId, $galleryTitle, $selectionId, $selectionName, $likeCount, $label);

		$xml = $document->saveXML();
		if (!is_string($xml) || strlen($xml) > self::MAX_SIDECAR_BYTES) {
			throw new InvalidArgumentException('The generated XMP sidecar is too large');
		}
		if ($sidecar === null) {
			$parent->newFile($sidecarName, $xml);
		} else {
			$sidecar->putContent($xml);
		}
		return $this->index($file);
	}

	/**
	 * Read canonical culling values from an Adobe-compatible sidecar.
	 * Lightroom's -1 rating is interpreted as reject while explicit app pick
	 * state is retained in the proofing namespace.
	 *
	 * @return array{exists: bool, etag: ?string, rating: int, color: string, pick: string}
	 */
	public function readCullingSidecar(File $file): array {
		$parent = $file->getParent();
		if (!$parent instanceof Folder) return ['exists' => false, 'etag' => null, 'rating' => 0, 'color' => 'none', 'pick' => 'none'];
		$name = pathinfo($file->getName(), PATHINFO_FILENAME) . '.xmp';
		if (!$parent->nodeExists($name)) return ['exists' => false, 'etag' => null, 'rating' => 0, 'color' => 'none', 'pick' => 'none'];
		$node = $parent->get($name);
		if (!$node instanceof File || $node->getSize() > self::MAX_SIDECAR_BYTES) throw new InvalidArgumentException('The XMP sidecar is unavailable or too large');
		$values = $this->xmp->readCullingDocument($this->xmp->loadXmp($node->getContent()));
		return [
			'exists' => true,
			'etag' => $node->getEtag(),
			...$values,
		];
	}

	/**
	 * @param array{rating: int, color: string, pick: string} $state
	 * @return array{changed: bool, etag: ?string, xml: string}
	 */
	public function writeCullingSidecar(File $file, array $state, ?string $expectedSidecarEtag, bool $dryRun = false): array {
		if (!$dryRun && $this->policies->get('xmpWritingEnabled') !== 1) throw new InvalidArgumentException('XMP sidecar writing is disabled by the administrator');
		$rating = (int)($state['rating'] ?? -1);
		$color = (string)($state['color'] ?? '');
		$pick = (string)($state['pick'] ?? '');
		if ($rating < 0 || $rating > 5 || !array_key_exists($color, self::CULL_COLORS) || !in_array($pick, ['none', 'pick', 'reject'], true)) {
			throw new InvalidArgumentException('Invalid culling value');
		}
		$parent = $file->getParent();
		if (!$parent instanceof Folder || (!$dryRun && !$parent->isUpdateable())) throw new InvalidArgumentException('The source folder is not writable');
		$this->assertUnambiguousBaseName($parent, $file);
		$name = pathinfo($file->getName(), PATHINFO_FILENAME) . '.xmp';
		$sidecar = null;
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if (!$node instanceof File || $node->getSize() > self::MAX_SIDECAR_BYTES) throw new InvalidArgumentException('The XMP sidecar is unavailable or too large');
			$sidecar = $node;
			if ($expectedSidecarEtag === null || !hash_equals($sidecar->getEtag(), $expectedSidecarEtag)) throw new MetadataConflictException('The XMP sidecar changed');
		} elseif ($expectedSidecarEtag !== null) {
			throw new MetadataConflictException('The XMP sidecar was removed');
		}
		$document = $sidecar === null ? $this->xmp->newXmpDocument() : $this->xmp->loadXmp($sidecar->getContent());
		$this->xmp->applyCullingFields($document, ['rating' => $rating, 'color' => $color, 'pick' => $pick]);
		$xml = $document->saveXML();
		if (!is_string($xml) || strlen($xml) > self::MAX_SIDECAR_BYTES) throw new InvalidArgumentException('The generated XMP sidecar is too large');
		$changed = $sidecar === null || $sidecar->getContent() !== $xml;
		if ($dryRun || !$changed) return ['changed' => $changed, 'etag' => $sidecar?->getEtag(), 'xml' => $xml];
		if ($sidecar === null) $sidecar = $parent->newFile($name, $xml);
		else $sidecar->putContent($xml);
		return ['changed' => true, 'etag' => $sidecar->getEtag(), 'xml' => $xml];
	}

	/**
	 * @param array<string, mixed> $known
	 * @return array<string, mixed>
	 */
	private function extract(File $file, array $known): array {
		$details = $this->extractor->extract($file, $known);
		$sidecar = $this->sidecarMetadata($file);
		$details = array_replace($details, array_filter($sidecar['values'], static fn (mixed $value): bool => $value !== null && $value !== []));
		if ($sidecar['info'] !== null) $details['sidecar'] = $sidecar['info'];
		return array_filter($details, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
	}

	/** @return array{values: array<string, mixed>, info: ?array<string, mixed>} */
	private function sidecarMetadata(File $file): array {
		$parent = $file->getParent();
		if (!$parent instanceof Folder) return ['values' => [], 'info' => null];
		$name = pathinfo($file->getName(), PATHINFO_FILENAME) . '.xmp';
		if (!$parent->nodeExists($name)) return ['values' => [], 'info' => null];
		$node = $parent->get($name);
		if (!$node instanceof File || $node->getSize() > self::MAX_SIDECAR_BYTES) return ['values' => [], 'info' => null];
		try {
			$document = $this->xmp->loadXmp($node->getContent());
			return [
				'values' => $this->xmp->readXmpValues($document),
				'info' => ['name' => $name, 'etag' => $node->getEtag(), 'fileId' => $node->getId()],
			];
		} catch (InvalidArgumentException) {
			return ['values' => [], 'info' => ['name' => $name, 'etag' => $node->getEtag(), 'fileId' => $node->getId(), 'invalid' => true]];
		}
	}

	/**
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function validatedChanges(array $changes): array {
		$unknown = array_diff(array_keys($changes), self::EDITABLE_FIELDS);
		if ($unknown !== [] || $changes === []) throw new InvalidArgumentException('Invalid metadata fields');
		foreach (['title', 'creator', 'copyright', 'label'] as $field) {
			if (array_key_exists($field, $changes) && $changes[$field] !== null && (!is_string($changes[$field]) || mb_strlen($changes[$field]) > 500)) {
				throw new InvalidArgumentException('Invalid ' . $field);
			}
		}
		if (array_key_exists('description', $changes) && $changes['description'] !== null && (!is_string($changes['description']) || mb_strlen($changes['description']) > 4000)) {
			throw new InvalidArgumentException('Invalid description');
		}
		if (array_key_exists('rating', $changes) && $changes['rating'] !== null && (!is_int($changes['rating']) || $changes['rating'] < 0 || $changes['rating'] > 5)) {
			throw new InvalidArgumentException('Rating must be between 0 and 5');
		}
		if (array_key_exists('keywords', $changes)) {
			if ($changes['keywords'] !== null && (!is_array($changes['keywords']) || !array_is_list($changes['keywords']) || count($changes['keywords']) > 100)) {
				throw new InvalidArgumentException('Invalid keywords');
			}
			$changes['keywords'] = array_values(array_unique(array_map(static function (mixed $value): string {
				if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 120) throw new InvalidArgumentException('Invalid keyword');
				return trim($value);
			}, $changes['keywords'] ?? [])));
		}
		return $changes;
	}

	private function assertUnambiguousBaseName(Folder $parent, File $file): void {
		$base = mb_strtolower(pathinfo($file->getName(), PATHINFO_FILENAME));
		foreach ($parent->getDirectoryListing() as $node) {
			if ($node instanceof File && $node->getId() !== $file->getId()
				&& str_starts_with($node->getMimeType(), 'image/')
				&& mb_strtolower(pathinfo($node->getName(), PATHINFO_FILENAME)) === $base) {
				throw new MetadataConflictException('Another image uses the same base filename');
			}
		}
	}

}
