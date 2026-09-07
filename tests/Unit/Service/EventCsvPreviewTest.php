<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Service;

use OCA\ProofingGallery\Service\EventCsvPreview;
use PHPUnit\Framework\TestCase;

final class EventCsvPreviewTest extends TestCase {
	/** @return list<array{path: string, name: string, mediaCount: int}> */
	private function folders(): array {
		return [
			['path' => 'Allgemein', 'name' => 'Allgemein', 'mediaCount' => 12],
			['path' => 'Klassen/1a', 'name' => '1a', 'mediaCount' => 20],
			['path' => 'Kinder/Anna', 'name' => 'Anna', 'mediaCount' => 8],
			['path' => 'Kinder/Anton', 'name' => 'Anton', 'mediaCount' => 7],
		];
	}

	public function testParsesQuotedMultilineAndPipeSeparatedGroups(): void {
		$csv = "folder,name,email,locale,pin,groups\r\n\"Kinder/Anna\",\"Anna\nMuster\",anna@example.test,de,Aa2!34567890,\"Klassen/1a|Allgemein\"\r\n";
		$result = (new EventCsvPreview())->preview($csv, $this->folders());

		self::assertSame(['total' => 1, 'ready' => 1, 'conflicts' => 0], $result['summary']);
		self::assertSame("Anna\nMuster", $result['rows'][0]['name']);
		self::assertSame(['Klassen/1a', 'Allgemein'], $result['rows'][0]['groupRoots']);
	}

	public function testReportsAmbiguousPrefixAndValidationConflictsWithoutAssigning(): void {
		$csv = "folder;name;email;locale;pin\nKinder/A;Anna;wrong;fr;short\n";
		$result = (new EventCsvPreview())->preview($csv, $this->folders(), 'prefix');

		self::assertSame(['total' => 1, 'ready' => 0, 'conflicts' => 1], $result['summary']);
		self::assertNull($result['rows'][0]['folderPath']);
		self::assertEqualsCanonicalizing(['folder_ambiguous', 'recipient_email_invalid', 'recipient_locale_invalid', 'recipient_pin_invalid'], $result['rows'][0]['conflicts']);
	}

	public function testExactModeDoesNotApplyPrefixSuggestion(): void {
		$result = (new EventCsvPreview())->preview("folder,name\nAnn,Anna\n", $this->folders());

		self::assertSame(['folder_missing'], $result['rows'][0]['conflicts']);
		self::assertNull($result['rows'][0]['folderPath']);
	}
}
