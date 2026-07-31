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

	public function __construct(
		private IFilesMetadataManager $metadataManager,
		private PolicyService $policies,
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

	/** @param list<string> $fields @return array<string, mixed> */
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

	/** @param array<string, mixed> $known @return array<string, mixed> */
	private function extract(File $file, array $known): array {
		$flat = $this->flatten($known);
		$details = [
			'capturedAt' => $this->timestamp($this->first($flat, ['photos-original_date_time', 'datetimeoriginal', 'date_time_original'])),
			'camera' => $this->joined($this->first($flat, ['make']), $this->first($flat, ['model', 'cameramodelname'])),
			'lens' => $this->text($this->first($flat, ['lensmodel', 'lens'])),
			'focalLength' => $this->number($this->first($flat, ['focallength'])),
			'aperture' => $this->number($this->first($flat, ['fnumber', 'aperturevalue'])),
			'exposureTime' => $this->text($this->first($flat, ['exposuretime'])),
			'iso' => $this->integer($this->first($flat, ['isospeedratings', 'photographicsensitivity', 'iso'])),
			'width' => $this->integer($this->first($flat, ['width', 'exifimagewidth', 'pixelxdimension'])),
			'height' => $this->integer($this->first($flat, ['height', 'exifimagelength', 'pixelydimension'])),
			'gps' => $this->gps($flat),
		];
		$embedded = $this->embeddedMetadata($file);
		$sidecar = $this->sidecarMetadata($file);
		$details = array_replace($details, array_filter($embedded, static fn (mixed $value): bool => $value !== null && $value !== []));
		$details = array_replace($details, array_filter($sidecar['values'], static fn (mixed $value): bool => $value !== null && $value !== []));
		if ($sidecar['info'] !== null) $details['sidecar'] = $sidecar['info'];
		return array_filter($details, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
	}

	/** @return array<string, mixed> */
	private function embeddedMetadata(File $file): array {
		if ($file->getSize() <= 0 || $file->getSize() > $this->policies->get('metadataMaxBytes')) return [];
		$temporaryPath = tempnam(sys_get_temp_dir(), 'proofing-meta-');
		if ($temporaryPath === false) return [];
		$input = $file->fopen('rb');
		$output = fopen($temporaryPath, 'wb');
		if (!is_resource($input) || !is_resource($output)) {
			if (is_resource($input)) fclose($input);
			if (is_resource($output)) fclose($output);
			@unlink($temporaryPath);
			return [];
		}
		try {
			$copied = stream_copy_to_stream($input, $output, $this->policies->get('metadataMaxBytes') + 1);
		} finally {
			fclose($input);
			fclose($output);
		}
		if ($copied === false || $copied > $this->policies->get('metadataMaxBytes')) {
			@unlink($temporaryPath);
			return [];
		}
		try {
			$raw = function_exists('exif_read_data') ? @exif_read_data($temporaryPath, null, true, false) : false;
			$info = [];
			$dimensions = @getimagesize($temporaryPath, $info);
			$flat = is_array($raw) ? $this->flatten($raw) : [];
			$iptc = isset($info['APP13']) && function_exists('iptcparse') ? @iptcparse($info['APP13']) : false;
			return [
				'capturedAt' => $this->timestamp($this->first($flat, ['datetimeoriginal', 'date_time_original'])),
				'camera' => $this->joined($this->first($flat, ['make']), $this->first($flat, ['model'])),
				'lens' => $this->text($this->first($flat, ['lensmodel', 'lens'])),
				'focalLength' => $this->number($this->first($flat, ['focallength'])),
				'aperture' => $this->number($this->first($flat, ['fnumber'])),
				'exposureTime' => $this->text($this->first($flat, ['exposuretime'])),
				'iso' => $this->integer($this->first($flat, ['isospeedratings', 'photographicsensitivity'])),
				'width' => is_array($dimensions) ? (int)$dimensions[0] : null,
				'height' => is_array($dimensions) ? (int)$dimensions[1] : null,
				'gps' => $this->gps($flat),
				'title' => $this->iptcFirst($iptc, '2#005'),
				'description' => $this->iptcFirst($iptc, '2#120'),
				'creator' => $this->iptcFirst($iptc, '2#080'),
				'copyright' => $this->iptcFirst($iptc, '2#116'),
				'keywords' => $this->iptcList($iptc, '2#025'),
			];
		} finally {
			@unlink($temporaryPath);
		}
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

	/** @param array<string, mixed> $changes @return array<string, mixed> */
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

	/** @param array<string, mixed> $input @return array<string, mixed> */
	private function flatten(array $input): array {
		$result = [];
		array_walk_recursive($input, static function (mixed $value, string|int $key) use (&$result): void {
			if (is_string($key)) $result[mb_strtolower(str_replace([' ', '-', '_'], '', $key))] = $value;
		});
		foreach ($input as $key => $value) if (is_string($key)) $result[mb_strtolower(str_replace([' ', '-', '_'], '', $key))] = $value;
		return $result;
	}

	/** @param array<string, mixed> $values @param list<string> $keys */
	private function first(array $values, array $keys): mixed {
		foreach ($keys as $key) {
			$normalized = mb_strtolower(str_replace([' ', '-', '_'], '', $key));
			if (array_key_exists($normalized, $values)) return $values[$normalized];
		}
		return null;
	}

	private function timestamp(mixed $value): ?int {
		if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
		if (!is_string($value) || trim($value) === '') return null;
		$date = \DateTimeImmutable::createFromFormat('Y:m:d H:i:s', trim($value));
		return $date === false ? null : $date->getTimestamp();
	}

	private function number(mixed $value): ?float {
		if (is_int($value) || is_float($value)) return (float)$value;
		if (!is_string($value)) return null;
		if (preg_match('/^(-?\d+(?:\.\d+)?)\s*\/\s*(-?\d+(?:\.\d+)?)$/', trim($value), $matches) === 1 && (float)$matches[2] !== 0.0) return (float)$matches[1] / (float)$matches[2];
		return is_numeric($value) ? (float)$value : null;
	}

	private function integer(mixed $value): ?int {
		if (is_array($value)) $value = reset($value);
		return is_int($value) || (is_string($value) && is_numeric($value)) ? (int)$value : null;
	}

	/** @param array<string, mixed> $values @return ?array{latitude: float, longitude: float} */
	private function gps(array $values): ?array {
		$latitude = $this->coordinate(
			$this->first($values, ['gpslatitude']),
			$this->text($this->first($values, ['gpslatituderef'])),
		);
		$longitude = $this->coordinate(
			$this->first($values, ['gpslongitude']),
			$this->text($this->first($values, ['gpslongituderef'])),
		);
		if ($latitude === null || $longitude === null || abs($latitude) > 90 || abs($longitude) > 180) return null;
		return ['latitude' => round($latitude, 7), 'longitude' => round($longitude, 7)];
	}

	private function coordinate(mixed $parts, ?string $reference): ?float {
		if (!is_array($parts) || count($parts) < 3) return null;
		$degrees = $this->number($parts[0]);
		$minutes = $this->number($parts[1]);
		$seconds = $this->number($parts[2]);
		if ($degrees === null || $minutes === null || $seconds === null) return null;
		$value = $degrees + ($minutes / 60) + ($seconds / 3600);
		if (in_array(mb_strtoupper((string)$reference), ['S', 'W'], true)) $value *= -1;
		return $value;
	}

	private function text(mixed $value): ?string {
		if (is_array($value)) $value = reset($value);
		if (!is_scalar($value)) return null;
		$text = trim((string)$value);
		return $text === '' ? null : mb_substr($text, 0, 500);
	}

	private function joined(mixed ...$values): ?string {
		$parts = array_values(array_unique(array_filter(array_map(fn (mixed $value): ?string => $this->text($value), $values))));
		return $parts === [] ? null : implode(' ', $parts);
	}

	private function iptcFirst(array|false $iptc, string $key): ?string {
		return is_array($iptc) ? $this->text($iptc[$key][0] ?? null) : null;
	}

	/** @return list<string> */
	private function iptcList(array|false $iptc, string $key): array {
		if (!is_array($iptc) || !is_array($iptc[$key] ?? null)) return [];
		return array_values(array_unique(array_filter(array_map(fn (mixed $value): ?string => $this->text($value), $iptc[$key]))));
	}

	private function xpathText(\DOMXPath $xpath, string $query, \DOMElement $context): ?string {
		$node = $xpath->query($query, $context)->item(0);
		return $node === null ? null : $this->text($node->textContent);
	}

	/** @return list<string> */
	private function xpathTexts(\DOMXPath $xpath, string $query, \DOMElement $context): array {
		$values = [];
		foreach ($xpath->query($query, $context) as $node) {
			$value = $this->text($node->textContent);
			if ($value !== null) $values[] = $value;
		}
		return array_values(array_unique($values));
	}
}
