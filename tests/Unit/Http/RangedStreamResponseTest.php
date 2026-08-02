<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Tests\Unit\Http;

use OCA\ProofingGallery\Http\RangedStreamResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\IOutput;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class RangedStreamResponseTest extends TestCase {
	public function testStreamsOnlyRequestedRange(): void {
		$response = new RangedStreamResponse(
			$this->request('GET', 'bytes=2-5'),
			fn () => $this->stream('abcdefgh'),
			8,
			'video/mp4',
			'etag',
		);
		$content = '';
		$output = $this->createMock(IOutput::class);
		$output->method('getHttpResponseCode')->willReturn(Http::STATUS_PARTIAL_CONTENT);
		$output->method('setOutput')->willReturnCallback(static function (string $chunk) use (&$content): void {
			$content .= $chunk;
		});

		$response->callback($output);

		self::assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
		self::assertSame('bytes 2-5/8', $this->headers($response)['Content-Range']);
		self::assertSame('4', $this->headers($response)['Content-Length']);
		self::assertSame('cdef', $content);
	}

	public function testRejectsMultipleOrOutOfBoundsRangesWithoutOpeningFile(): void {
		$opened = false;
		$response = new RangedStreamResponse(
			$this->request('GET', 'bytes=10-12,20-22'),
			function () use (&$opened) { $opened = true; return $this->stream('abcdefgh'); },
			8,
			'application/octet-stream',
			'etag',
		);
		$output = $this->createMock(IOutput::class);
		$response->callback($output);

		self::assertSame(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE, $response->getStatus());
		self::assertSame('bytes */8', $this->headers($response)['Content-Range']);
		self::assertFalse($opened);
	}

	public function testHeadAndMismatchedIfRangeDoNotStreamBody(): void {
		$opened = false;
		$response = new RangedStreamResponse(
			$this->request('HEAD', 'bytes=2-5', '"older"'),
			function () use (&$opened) { $opened = true; return $this->stream('abcdefgh'); },
			8,
			'application/octet-stream',
			'etag',
		);
		$response->callback($this->createMock(IOutput::class));

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('8', $this->headers($response)['Content-Length']);
		self::assertFalse($opened);
	}

	private function request(string $method, string $range = '', string $ifRange = ''): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => match ($name) {
			'Range' => $range,
			'If-Range' => $ifRange,
			default => '',
		});
		return $request;
	}

	/** @return array<string, string> */
	private function headers(RangedStreamResponse $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		/** @var array<string, string> $headers */
		$headers = $property->getValue($response);
		return $headers;
	}

	/** @return resource */
	private function stream(string $content) {
		$stream = fopen('php://temp', 'w+b');
		self::assertIsResource($stream);
		fwrite($stream, $content);
		rewind($stream);
		return $stream;
	}
}
