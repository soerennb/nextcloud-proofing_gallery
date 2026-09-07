<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

/** Parse recipient assignments without changing gallery or link state. */
final class EventCsvPreview {
	private const MAX_BYTES = 1048576;
	private const MAX_ROWS = 500;

	/**
	 * @param list<array{path: string, name: string, mediaCount: int}> $folders
	 * @return array{headers: list<string>, rows: list<array<string, mixed>>, summary: array{total: int, ready: int, conflicts: int}}
	 */
	public function preview(string $csv, array $folders, string $matchMode = 'exact'): array {
		if (strlen($csv) > self::MAX_BYTES) throw new \InvalidArgumentException('Recipient CSV exceeds 1 MiB');
		if (!in_array($matchMode, ['exact', 'prefix'], true)) throw new \InvalidArgumentException('Unsupported folder matching mode');
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		if (trim($csv) === '') throw new \InvalidArgumentException('Recipient CSV is empty');
		$delimiter = $this->delimiter($csv);
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) throw new \RuntimeException('CSV preview could not be created');
		try {
			fwrite($stream, $csv);
			rewind($stream);
			$rawHeaders = fgetcsv($stream, 0, $delimiter, '"', '');
			if ($rawHeaders === false) throw new \InvalidArgumentException('Recipient CSV is empty');
			$headers = array_map(static fn (mixed $value): string => mb_strtolower(trim((string)$value)), $rawHeaders);
			if (count($headers) !== count(array_unique($headers))) throw new \InvalidArgumentException('CSV column names must be unique');
			foreach (['folder', 'name'] as $required) if (!in_array($required, $headers, true)) throw new \InvalidArgumentException('CSV requires folder and name columns');
			$rows = [];
			$line = 1;
			while (($values = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
				$line++;
				if ($this->emptyRow($values)) continue;
				if (count($rows) >= self::MAX_ROWS) throw new \InvalidArgumentException('Recipient CSV exceeds 500 data rows');
				$record = [];
				foreach ($headers as $index => $header) $record[$header] = trim((string)($values[$index] ?? ''));
				$rows[] = $this->row($line, $record, $folders, $matchMode);
			}
		} finally {
			fclose($stream);
		}
		$privatePaths = array_values(array_unique(array_filter(array_column($rows, 'folderPath'), 'is_string')));
		$allGroupRoots = [];
		foreach ($rows as $row) $allGroupRoots = [...$allGroupRoots, ...$row['groupRoots']];
		$crossRolePaths = array_intersect($privatePaths, array_unique($allGroupRoots));
		if ($crossRolePaths !== []) foreach ($rows as &$row) {
			if (in_array($row['folderPath'], $crossRolePaths, true) || array_intersect($row['groupRoots'], $crossRolePaths) !== []) $row['conflicts'][] = 'folder_role_conflict';
			$row['conflicts'] = array_values(array_unique($row['conflicts']));
		}
		unset($row);
		$ready = count(array_filter($rows, static fn (array $row): bool => $row['conflicts'] === []));
		return ['headers' => $headers, 'rows' => $rows, 'summary' => ['total' => count($rows), 'ready' => $ready, 'conflicts' => count($rows) - $ready]];
	}

	/**
	 * @param array<string, string> $record
	 * @param list<array{path: string, name: string, mediaCount: int}> $folders
	 * @return array{line: int, folderInput: string, folderPath: ?string, groupInputs: list<string>, groupRoots: list<string>, name: string, email: string, locale: ?string, pin: string, conflicts: list<string>}
	 */
	private function row(int $line, array $record, array $folders, string $matchMode): array {
		$folderInput = $record['folder'] ?? '';
		$folderMatch = $this->match($folderInput, $folders, $matchMode);
		$conflicts = $folderMatch['conflicts'];
		$name = trim($record['name'] ?? '');
		$email = mb_strtolower(trim($record['email'] ?? ''));
		$locale = mb_strtolower(trim($record['locale'] ?? ''));
		$pin = trim($record['pin'] ?? '');
		if ($name === '' || mb_strlen($name) > 120) $conflicts[] = 'recipient_name_invalid';
		if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) $conflicts[] = 'recipient_email_invalid';
		if ($locale !== '' && !in_array($locale, ['de', 'en'], true)) $conflicts[] = 'recipient_locale_invalid';
		if ($pin !== '' && (mb_strlen($pin) < 10 || mb_strlen($pin) > 64)) $conflicts[] = 'recipient_pin_invalid';
		$groupInputs = array_values(array_filter(array_map('trim', explode('|', $record['groups'] ?? '')), static fn (string $value): bool => $value !== ''));
		$groupRoots = [];
		foreach (array_values(array_unique($groupInputs)) as $groupInput) {
			$groupMatch = $this->match($groupInput, $folders, $matchMode);
			if ($groupMatch['path'] !== null) $groupRoots[] = $groupMatch['path'];
			foreach ($groupMatch['conflicts'] as $conflict) $conflicts[] = 'group_' . substr($conflict, 7) . ':' . $groupInput;
		}
		if ($folderMatch['path'] !== null && in_array($folderMatch['path'], $groupRoots, true)) $conflicts[] = 'folder_role_conflict';
		return [
			'line' => $line, 'folderInput' => $folderInput, 'folderPath' => $folderMatch['path'], 'groupInputs' => $groupInputs,
			'groupRoots' => array_values(array_unique($groupRoots)), 'name' => $name, 'email' => $email,
			'locale' => in_array($locale, ['de', 'en'], true) ? $locale : null, 'pin' => $pin, 'conflicts' => array_values(array_unique($conflicts)),
		];
	}

	/**
	 * @param list<array{path: string, name: string, mediaCount: int}> $folders
	 * @return array{path: ?string, conflicts: list<string>}
	 */
	private function match(string $input, array $folders, string $mode): array {
		$needle = mb_strtolower(trim($input, " /\t\n\r\0\x0B"));
		if ($needle === '') return ['path' => null, 'conflicts' => ['folder_missing']];
		$exact = array_values(array_filter($folders, static function (array $folder) use ($needle): bool {
			return mb_strtolower(trim($folder['path'], '/')) === $needle || mb_strtolower($folder['name']) === $needle;
		}));
		if (count($exact) === 1) return ['path' => $exact[0]['path'], 'conflicts' => []];
		if (count($exact) > 1) return ['path' => null, 'conflicts' => ['folder_ambiguous']];
		if ($mode === 'prefix') {
			$prefix = array_values(array_filter($folders, static function (array $folder) use ($needle): bool {
				return str_starts_with(mb_strtolower(trim($folder['path'], '/')), $needle) || str_starts_with(mb_strtolower($folder['name']), $needle);
			}));
			if (count($prefix) === 1) return ['path' => $prefix[0]['path'], 'conflicts' => []];
			if (count($prefix) > 1) return ['path' => null, 'conflicts' => ['folder_ambiguous']];
		}
		return ['path' => null, 'conflicts' => ['folder_missing']];
	}

	private function delimiter(string $csv): string {
		$firstLine = strtok($csv, "\r\n");
		$best = ',';
		$fields = 0;
		foreach ([',', ';', "\t"] as $candidate) {
			$count = count(str_getcsv((string)$firstLine, $candidate, '"', ''));
			if ($count > $fields) { $best = $candidate; $fields = $count; }
		}
		return $best;
	}

	/** @param list<mixed> $values */
	private function emptyRow(array $values): bool { return count(array_filter($values, static fn (mixed $value): bool => trim((string)$value) !== '')) === 0; }
}
