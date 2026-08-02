<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;
use OCA\ProofingGallery\Exception\MetadataConflictException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\FilesMetadata\IFilesMetadataManager;

final class MediaMetadataService {
	private const DETAILS_KEY = 'proofing-gallery-details';
	private const MAX_SIDECAR_BYTES = 1048576;
	private const EDITABLE_FIELDS = ['title', 'description', 'creator', 'copyright', 'keywords', 'rating', 'label'];
	private const PUBLIC_FIELDS = ['capturedAt', 'camera', 'lens', 'exposure', 'title', 'description', 'creator', 'copyright'];
	private const NS_RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
	private const NS_XMP = 'http://ns.adobe.com/xap/1.0/';
	private const NS_DC = 'http://purl.org/dc/elements/1.1/';
	private const NS_LR = 'http://ns.adobe.com/lightroom/1.0/';
	private const NS_PG = 'urn:nextcloud:proofing-gallery:1.0';
	private const CULL_COLORS = [
		'none' => null,
		'red' => 'Red',
		'yellow' => 'Yellow',
		'green' => 'Green',
		'blue' => 'Blue',
		'purple' => 'Purple',
	];

	public function __construct(
		private IFilesMetadataManager $metadataManager,
		private PolicyService $policies,
		private EmbeddedMetadataExtractor $extractor,
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
			return ['state' => 'ready', ...$details];
		} catch (\Throwable) {
			return ['state' => 'pending'];
		}
	}

	/** @return array<string, mixed> */
	public function index(File $file): array {
		if (!str_starts_with($file->getMimeType(), 'image/')) {
			return ['state' => 'unavailable'];
		}
		$metadata = $this->metadataManager->getMetadata($file->getId(), true);
		$details = $this->extract($file, $metadata->asArray());
		$metadata->setArray(self::DETAILS_KEY, $details);
		$metadata->setEtag(self::DETAILS_KEY, $file->getEtag());
		$this->metadataManager->saveMetadata($metadata);
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

		$document = $sidecar === null ? $this->newXmpDocument() : $this->loadXmp($sidecar->getContent());
		$this->applyEditableFields($document, $changes);
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
		$galleryTitle = $this->requiredProofingText($proofing['galleryTitle'] ?? null, 500, 'gallery title');
		$selectionId = $this->requiredProofingText($proofing['selectionId'] ?? null, 120, 'selection id');
		$selectionName = $this->requiredProofingText($proofing['selectionName'] ?? null, 120, 'selection name');
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

		$document = $sidecar === null ? $this->newXmpDocument() : $this->loadXmp($sidecar->getContent());
		$this->applyProofingFields($document, $galleryId, $galleryTitle, $selectionId, $selectionName, $likeCount, $label);

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
		$values = $this->readCullingDocument($this->loadXmp($node->getContent()));
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
		$document = $sidecar === null ? $this->newXmpDocument() : $this->loadXmp($sidecar->getContent());
		$this->applyCullingFields($document, ['rating' => $rating, 'color' => $color, 'pick' => $pick]);
		$xml = $document->saveXML();
		if (!is_string($xml) || strlen($xml) > self::MAX_SIDECAR_BYTES) throw new InvalidArgumentException('The generated XMP sidecar is too large');
		$changed = $sidecar === null || $sidecar->getContent() !== $xml;
		if ($dryRun || !$changed) return ['changed' => $changed, 'etag' => $sidecar?->getEtag(), 'xml' => $xml];
		if ($sidecar === null) $sidecar = $parent->newFile($name, $xml);
		else $sidecar->putContent($xml);
		return ['changed' => true, 'etag' => $sidecar->getEtag(), 'xml' => $xml];
	}

	/** @return array{rating: int, color: string, pick: string} */
	private function readCullingDocument(\DOMDocument $document): array {
		$description = $this->description($document);
		$rawRating = $description->hasAttributeNS(self::NS_XMP, 'Rating') ? (int)$description->getAttributeNS(self::NS_XMP, 'Rating') : 0;
		$label = mb_strtolower(trim($description->getAttributeNS(self::NS_XMP, 'Label')));
		$color = array_search($label === '' ? null : ucfirst($label), self::CULL_COLORS, true);
		$pick = $description->getAttributeNS(self::NS_PG, 'PickState');
		if (!in_array($pick, ['none', 'pick', 'reject'], true)) $pick = $rawRating < 0 ? 'reject' : 'none';
		return ['rating' => max(0, min(5, $rawRating)), 'color' => is_string($color) ? $color : 'none', 'pick' => $pick];
	}

	/** @param array{rating: int, color: string, pick: string} $state */
	private function applyCullingFields(\DOMDocument $document, array $state): void {
		$description = $this->description($document);
		$description->setAttributeNS(self::NS_XMP, 'xmp:Rating', (string)$state['rating']);
		$label = self::CULL_COLORS[$state['color']];
		if ($label === null) $description->removeAttributeNS(self::NS_XMP, 'Label');
		else $description->setAttributeNS(self::NS_XMP, 'xmp:Label', $label);
		$description->setAttributeNS(self::NS_PG, 'pg:PickState', $state['pick']);
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
			$document = $this->loadXmp($node->getContent());
			return [
				'values' => $this->readXmpValues($document),
				'info' => ['name' => $name, 'etag' => $node->getEtag(), 'fileId' => $node->getId()],
			];
		} catch (InvalidArgumentException) {
			return ['values' => [], 'info' => ['name' => $name, 'etag' => $node->getEtag(), 'fileId' => $node->getId(), 'invalid' => true]];
		}
	}

	/** @return array<string, mixed> */
	private function readXmpValues(\DOMDocument $document): array {
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('rdf', self::NS_RDF);
		$xpath->registerNamespace('xmp', self::NS_XMP);
		$xpath->registerNamespace('dc', self::NS_DC);
		$description = $xpath->query('//rdf:Description')->item(0);
		if (!$description instanceof \DOMElement) return [];
		return array_filter([
			'title' => $this->xpathText($xpath, './/dc:title/rdf:Alt/rdf:li', $description),
			'description' => $this->xpathText($xpath, './/dc:description/rdf:Alt/rdf:li', $description),
			'creator' => $this->xpathText($xpath, './/dc:creator/rdf:Seq/rdf:li', $description),
			'copyright' => $this->xpathText($xpath, './/dc:rights/rdf:Alt/rdf:li', $description),
			'keywords' => $this->xpathTexts($xpath, './/dc:subject/rdf:Bag/rdf:li', $description),
			'rating' => $description->hasAttributeNS(self::NS_XMP, 'Rating') ? (int)$description->getAttributeNS(self::NS_XMP, 'Rating') : null,
			'label' => $description->getAttributeNS(self::NS_XMP, 'Label') ?: null,
		], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
	}

	/** @param array<string, mixed> $changes */
	private function applyEditableFields(\DOMDocument $document, array $changes): void {
		$description = $this->description($document);
		foreach (['rating' => 'Rating', 'label' => 'Label'] as $field => $attribute) {
			if (!array_key_exists($field, $changes)) continue;
			$value = $changes[$field];
			if ($value === null || $value === '') $description->removeAttributeNS(self::NS_XMP, $attribute);
			else $description->setAttributeNS(self::NS_XMP, 'xmp:' . $attribute, (string)$value);
		}
		foreach (['title' => 'title', 'description' => 'description', 'copyright' => 'rights'] as $field => $element) {
			if (array_key_exists($field, $changes)) $this->setAlt($document, $description, $element, $changes[$field]);
		}
		if (array_key_exists('creator', $changes)) $this->setContainer($document, $description, 'creator', 'Seq', $changes['creator'] === null ? [] : [$changes['creator']]);
		if (array_key_exists('keywords', $changes)) $this->setContainer($document, $description, 'subject', 'Bag', $changes['keywords'] ?? []);
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

	private function loadXmp(string $xml): \DOMDocument {
		if (strlen($xml) > self::MAX_SIDECAR_BYTES || stripos($xml, '<!DOCTYPE') !== false) throw new InvalidArgumentException('Invalid XMP sidecar');
		$document = new \DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		try {
			if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $document->doctype !== null) throw new InvalidArgumentException('Invalid XMP sidecar');
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		return $document;
	}

	private function newXmpDocument(): \DOMDocument {
		$document = new \DOMDocument('1.0', 'UTF-8');
		$document->formatOutput = true;
		$root = $document->createElementNS('adobe:ns:meta/', 'x:xmpmeta');
		$document->appendChild($root);
		$root->appendChild($document->createElementNS(self::NS_RDF, 'rdf:RDF'));
		$this->description($document);
		return $document;
	}

	private function description(\DOMDocument $document): \DOMElement {
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('rdf', self::NS_RDF);
		$existing = $xpath->query('//rdf:Description')->item(0);
		if ($existing instanceof \DOMElement) return $existing;
		$rdf = $xpath->query('//rdf:RDF')->item(0);
		if (!$rdf instanceof \DOMElement) throw new InvalidArgumentException('Invalid XMP document');
		$description = $document->createElementNS(self::NS_RDF, 'rdf:Description');
		$description->setAttributeNS(self::NS_RDF, 'rdf:about', '');
		$rdf->appendChild($description);
		return $description;
	}

	private function setAlt(\DOMDocument $document, \DOMElement $description, string $name, mixed $value): void {
		$this->removeProperty($description, self::NS_DC, $name);
		if ($value === null || $value === '') return;
		$property = $document->createElementNS(self::NS_DC, 'dc:' . $name);
		$alt = $document->createElementNS(self::NS_RDF, 'rdf:Alt');
		$item = $document->createElementNS(self::NS_RDF, 'rdf:li');
		$item->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'x-default');
		$item->appendChild($document->createTextNode((string)$value));
		$alt->appendChild($item);
		$property->appendChild($alt);
		$description->appendChild($property);
	}

	/** @param list<string> $values */
	private function setContainer(\DOMDocument $document, \DOMElement $description, string $name, string $container, array $values): void {
		$this->setNamespacedContainer($document, $description, self::NS_DC, 'dc', $name, $container, $values);
	}

	/** @param list<string> $values */
	private function setNamespacedContainer(
		\DOMDocument $document,
		\DOMElement $description,
		string $namespace,
		string $prefix,
		string $name,
		string $container,
		array $values,
	): void {
		$this->removeProperty($description, $namespace, $name);
		if ($values === []) return;
		$property = $document->createElementNS($namespace, $prefix . ':' . $name);
		$list = $document->createElementNS(self::NS_RDF, 'rdf:' . $container);
		foreach ($values as $value) {
			$item = $document->createElementNS(self::NS_RDF, 'rdf:li');
			$item->appendChild($document->createTextNode($value));
			$list->appendChild($item);
		}
		$property->appendChild($list);
		$description->appendChild($property);
	}

	private function requiredProofingText(mixed $value, int $maxLength, string $field): string {
		if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
			throw new InvalidArgumentException('Invalid ' . $field);
		}
		return trim($value);
	}

	private function applyProofingFields(
		\DOMDocument $document,
		int $galleryId,
		string $galleryTitle,
		string $selectionId,
		string $selectionName,
		int $likeCount,
		?string $label,
	): void {
		$description = $this->description($document);
		$wasPreviouslyExported = $description->hasAttributeNS(self::NS_PG, 'SelectionId');
		$description->setAttributeNS(self::NS_XMP, 'xmp:Rating', '5');
		if ($label !== null && trim($label) !== '') {
			$description->setAttributeNS(self::NS_XMP, 'xmp:Label', trim($label));
		} elseif ($wasPreviouslyExported) {
			$description->removeAttributeNS(self::NS_XMP, 'Label');
		}
		$existingKeywords = $this->readXmpValues($document)['keywords'] ?? [];
		$existingKeywords = array_values(array_filter(
			$existingKeywords,
			static fn (string $keyword): bool => $keyword !== 'Proofing'
				&& !str_starts_with($keyword, 'Gallery: ')
				&& !str_starts_with($keyword, 'Selection: '),
		));
		$this->setContainer($document, $description, 'subject', 'Bag', array_values(array_unique([
			...$existingKeywords,
			'Proofing',
			'Gallery: ' . $galleryTitle,
			'Selection: ' . $selectionName,
		])));
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('rdf', self::NS_RDF);
		$xpath->registerNamespace('lr', self::NS_LR);
		$hierarchy = array_values(array_filter(
			$this->xpathTexts($xpath, './/lr:hierarchicalSubject/rdf:Bag/rdf:li', $description),
			static fn (string $keyword): bool => !str_starts_with($keyword, 'Proofing|'),
		));
		$this->setNamespacedContainer(
			$document,
			$description,
			self::NS_LR,
			'lr',
			'hierarchicalSubject',
			'Bag',
			[...$hierarchy, 'Proofing|' . $galleryTitle . '|' . $selectionName],
		);
		$description->setAttributeNS(self::NS_PG, 'pg:GalleryId', (string)$galleryId);
		$description->setAttributeNS(self::NS_PG, 'pg:SelectionId', $selectionId);
		$description->setAttributeNS(self::NS_PG, 'pg:SelectionName', $selectionName);
		$description->setAttributeNS(self::NS_PG, 'pg:Selected', 'true');
		$description->setAttributeNS(self::NS_PG, 'pg:LikeCount', (string)$likeCount);
		$description->setAttributeNS(self::NS_PG, 'pg:ExportedAt', gmdate(DATE_ATOM));
	}

	private function removeProperty(\DOMElement $description, string $namespace, string $name): void {
		foreach (iterator_to_array($description->childNodes) as $child) {
			if ($child instanceof \DOMElement && $child->namespaceURI === $namespace && $child->localName === $name) $description->removeChild($child);
		}
	}

	private function xpathText(\DOMXPath $xpath, string $query, \DOMElement $context): ?string {
		$node = $xpath->query($query, $context)->item(0);
		if ($node === null) return null;
		$text = trim($node->textContent);
		return $text === '' ? null : mb_substr($text, 0, 500);
	}

	/** @return list<string> */
	private function xpathTexts(\DOMXPath $xpath, string $query, \DOMElement $context): array {
		$values = [];
		foreach ($xpath->query($query, $context) as $node) {
			$value = trim($node->textContent);
			$value = $value === '' ? null : mb_substr($value, 0, 500);
			if ($value !== null) $values[] = $value;
		}
		return array_values(array_unique($values));
	}
}
