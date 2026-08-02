<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

final class RangedStreamResponse extends Response implements ICallbackResponse {
	private const CHUNK_BYTES = 1048576;
	private \Closure $open;
	private int $start = 0;
	private int $length = 0;
	private bool $head;
	private bool $valid = true;

	/** @param callable(): mixed $open */
	public function __construct(
		IRequest $request,
		callable $open,
		int $size,
		string $mimeType,
		string $etag,
		?string $filename = null,
		string $cacheControl = 'private, max-age=3600',
	) {
		$this->open = \Closure::fromCallable($open);
		$this->head = strtoupper($request->getMethod()) === 'HEAD';
		$size = max(0, $size);
		$end = max(0, $size - 1);
		$status = Http::STATUS_OK;
		$quotedEtag = '"' . trim($etag, '"') . '"';
		$range = trim($request->getHeader('Range'));
		$ifRange = trim($request->getHeader('If-Range'));
		if ($range !== '' && ($ifRange === '' || hash_equals($quotedEtag, $ifRange))) {
			$parsed = $this->parseRange($range, $size);
			if ($parsed === null) {
				$this->valid = false;
				parent::__construct(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, [
					'Accept-Ranges' => 'bytes',
					'Content-Range' => 'bytes */' . $size,
					'Cache-Control' => $cacheControl,
					'ETag' => $quotedEtag,
				]);
				return;
			}
			[$this->start, $end] = $parsed;
			$status = Http::STATUS_PARTIAL_CONTENT;
		}
		$this->length = $size === 0 ? 0 : $end - $this->start + 1;
		$headers = [
			'Accept-Ranges' => 'bytes',
			'Content-Length' => (string)$this->length,
			'Content-Type' => $mimeType,
			'Cache-Control' => $cacheControl,
			'ETag' => $quotedEtag,
		];
		if ($status === Http::STATUS_PARTIAL_CONTENT) {
			$headers['Content-Range'] = sprintf('bytes %d-%d/%d', $this->start, $end, $size);
		}
		if ($filename !== null) {
			$fallback = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'download';
			$headers['Content-Disposition'] = sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", trim($fallback, '-'), rawurlencode($filename));
		}
		parent::__construct($status, $headers);
	}

	public function callback(IOutput $output): void {
		if (!$this->valid || $this->head || $this->length === 0 || $output->getHttpResponseCode() === Http::STATUS_NOT_MODIFIED) return;
		$stream = ($this->open)();
		if (!is_resource($stream) || ($this->start > 0 && fseek($stream, $this->start) !== 0)) {
			if (is_resource($stream)) fclose($stream);
			$output->setHttpResponseCode(Http::STATUS_NOT_FOUND);
			return;
		}
		try {
			$remaining = $this->length;
			while ($remaining > 0 && !feof($stream)) {
				$chunk = fread($stream, min(self::CHUNK_BYTES, $remaining));
				if ($chunk === false || $chunk === '') break;
				$output->setOutput($chunk);
				$remaining -= strlen($chunk);
			}
			if ($remaining !== 0) $output->setHttpResponseCode(Http::STATUS_BAD_GATEWAY);
		} finally {
			fclose($stream);
		}
	}

	/** @return array{int, int}|null */
	private function parseRange(string $header, int $size): ?array {
		if ($size < 1 || str_contains($header, ',') || preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) !== 1) return null;
		if ($matches[1] === '' && $matches[2] === '') return null;
		if ($matches[1] === '') {
			$suffix = (int)$matches[2];
			if ($suffix < 1) return null;
			return [max(0, $size - $suffix), $size - 1];
		}
		$start = (int)$matches[1];
		$end = $matches[2] === '' ? $size - 1 : min($size - 1, (int)$matches[2]);
		return $start >= $size || $start > $end ? null : [$start, $end];
	}
}
