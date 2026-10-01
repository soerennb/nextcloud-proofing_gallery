<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

final class KioskUploadBodyService {
	public function __construct(private PolicyService $policies) {
	}

	/** @param resource $input
	 * @return array{path: string, checksum: string}
	 * The caller owns and deletes the returned temporary file.
	 */
	public function receive(mixed $input): array {
		if (!is_resource($input)) throw new \InvalidArgumentException('The upload body could not be read');
		$path = tempnam(sys_get_temp_dir(), 'proofing-kiosk-');
		if ($path === false) throw new \RuntimeException('Upload staging is unavailable');
		$output = fopen($path, 'wb');
		if ($output === false) {
			@unlink($path);
			throw new \RuntimeException('Upload staging is unavailable');
		}
		try {
			$bytes = stream_copy_to_stream($input, $output, $this->policies->get('maxUploadBytes') + 1);
			fclose($output);
			if ($bytes === false || $bytes < 1 || $bytes > $this->policies->get('maxUploadBytes')) throw new \InvalidArgumentException('Upload size is outside the allowed range');
			$geometry = @getimagesize($path);
			if ($geometry === false || $geometry[2] !== IMAGETYPE_JPEG) throw new \InvalidArgumentException('The kiosk upload must contain a JPEG image');
			$checksum = hash_file('sha256', $path);
			if ($checksum === false) throw new \RuntimeException('Upload checksum could not be calculated');
			return ['path' => $path, 'checksum' => $checksum];
		} catch (\Throwable $exception) {
			if (is_resource($output)) fclose($output);
			@unlink($path);
			throw $exception;
		}
	}
}
