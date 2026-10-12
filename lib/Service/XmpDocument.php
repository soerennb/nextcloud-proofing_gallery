<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use InvalidArgumentException;

/** Pure Adobe-compatible XML transformations; no files or application state. */
final class XmpDocument {
	public const MAX_SIDECAR_BYTES = 1048576;
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

	/** @return array{rating: int, color: string, pick: string} */
	public function readCullingDocument(\DOMDocument $document): array {
		$description = $this->description($document);
		$rawRating = $description->hasAttributeNS(self::NS_XMP, 'Rating') ? (int)$description->getAttributeNS(self::NS_XMP, 'Rating') : 0;
		$label = mb_strtolower(trim($description->getAttributeNS(self::NS_XMP, 'Label')));
		$color = array_search($label === '' ? null : ucfirst($label), self::CULL_COLORS, true);
		$pick = $description->getAttributeNS(self::NS_PG, 'PickState');
		if (!in_array($pick, ['none', 'pick', 'reject'], true)) $pick = $rawRating < 0 ? 'reject' : 'none';
		return ['rating' => max(0, min(5, $rawRating)), 'color' => is_string($color) ? $color : 'none', 'pick' => $pick];
	}

	/** @param array{rating: int, color: string, pick: string} $state */
	public function applyCullingFields(\DOMDocument $document, array $state): void {
		$description = $this->description($document);
		$description->setAttributeNS(self::NS_XMP, 'xmp:Rating', (string)$state['rating']);
		$label = self::CULL_COLORS[$state['color']];
		if ($label === null) $description->removeAttributeNS(self::NS_XMP, 'Label');
		else $description->setAttributeNS(self::NS_XMP, 'xmp:Label', $label);
		$description->setAttributeNS(self::NS_PG, 'pg:PickState', $state['pick']);
	}

	/** @return array<string, mixed> */
	public function readXmpValues(\DOMDocument $document): array {
		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('rdf', self::NS_RDF);
		$xpath->registerNamespace('xmp', self::NS_XMP);
		$xpath->registerNamespace('dc', self::NS_DC);
		$xpath->registerNamespace('exif', 'http://ns.adobe.com/exif/1.0/');
		$description = $xpath->query('//rdf:Description')->item(0);
		if (!$description instanceof \DOMElement) return [];
		return array_filter([
			'capturedAt' => CaptureTimestamp::parse($description->getAttributeNS('http://ns.adobe.com/exif/1.0/', 'DateTimeOriginal') ?: $this->xpathText($xpath, './/exif:DateTimeOriginal', $description)),
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
	public function applyEditableFields(\DOMDocument $document, array $changes): void {
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

	public function loadXmp(string $xml): \DOMDocument {
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

	public function newXmpDocument(): \DOMDocument {
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

	public function requiredProofingText(mixed $value, int $maxLength, string $field): string {
		if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
			throw new InvalidArgumentException('Invalid ' . $field);
		}
		return trim($value);
	}

	public function applyProofingFields(
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
