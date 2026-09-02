<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

use InvalidArgumentException;
use OCA\ProofingGallery\Exception\ProjectCreationException;

final class ProjectCreationOptions {
	/** @return array<string, array{deliveryModes: list<string>, sourceModes: array<string, list<string>>, defaults: array{deliveryMode: string, sourceMode: string}}> */
	public static function all(): array {
		$flexible = [
			'deliveryModes' => ['standard', 'event'],
			'sourceModes' => [
				'standard' => ['existing', 'new', 'collection'],
				'event' => ['existing', 'new'],
			],
			'defaults' => ['deliveryMode' => 'standard', 'sourceMode' => 'existing'],
		];
		return [
			GalleryPurpose::Delivery->value => $flexible,
			GalleryPurpose::Showcase->value => $flexible,
			GalleryPurpose::Selection->value => $flexible,
			GalleryPurpose::Proofing->value => $flexible,
			GalleryPurpose::Uploads->value => [
				'deliveryModes' => ['standard'],
				'sourceModes' => ['standard' => ['existing', 'new']],
				'defaults' => ['deliveryMode' => 'standard', 'sourceMode' => 'new'],
			],
		];
	}

	public static function assertValid(string $purpose, string $deliveryMode, string $sourceMode): void {
		// Custom projects remain available to integrations and retain the existing
		// structural constraints. The guided wizard only exposes built-in recipes.
		if ($purpose === GalleryPurpose::Custom->value) return;
		$recipe = self::all()[$purpose] ?? throw new InvalidArgumentException('Unknown gallery purpose');
		if (!in_array($deliveryMode, $recipe['deliveryModes'], true)
			|| !in_array($sourceMode, $recipe['sourceModes'][$deliveryMode] ?? [], true)) {
			throw new ProjectCreationException('This project purpose does not support the selected audience and photo source');
		}
	}
}
