<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Http;

use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\StreamResponse;

final class TemporaryFileResponse extends StreamResponse {
	public function callback(IOutput $output): void {
		try {
			parent::callback($output);
		} finally {
			@unlink($this->temporaryPath);
		}
	}

	public function __construct(
		private string $temporaryPath,
		string $filename,
		string $contentType,
	) {
		parent::__construct($temporaryPath, headers: [
			'Content-Type' => $contentType,
			'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, '"\\') . '"',
			'Content-Length' => (string)filesize($temporaryPath),
			'Cache-Control' => 'private, no-store',
		]);
	}
}
