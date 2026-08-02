<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IPreview;

final class SemanticEmbeddingProvider {
	public function __construct(
		private PolicyService $policies,
		private SemanticVectorizer $vectors,
		private MediaMetadataService $metadata,
		private IPreview $previews,
		private IClientService $clients,
	) {
	}

	/** @return array{vector: list<float>, concepts: list<string>, provider: string, model: string} */
	public function media(File $file): array {
		$settings = $this->enabledSettings();
		if ($settings['provider'] === 'local') {
			$summary = $this->metadata->summary($file);
			$fields = [$file->getName()];
			foreach (['title', 'description', 'camera', 'lens', 'creator', 'copyright'] as $field) {
				if (is_scalar($summary[$field] ?? null)) $fields[] = (string)$summary[$field];
			}
			$text = implode(' ', $fields);
			return ['vector' => $this->vectors->embed($text), 'concepts' => $this->vectors->concepts($text), ...$settings];
		}
		$preview = $this->previews->getPreview($file, 384, 384, false, IPreview::MODE_FILL);
		$content = $preview->getContent();
		if (strlen($content) > $this->policies->get('semanticPreviewMaxBytes')) throw new \RuntimeException('semantic_preview_too_large');
		return [...$this->remote(['type' => 'image', 'mimeType' => $preview->getMimeType(), 'data' => base64_encode($content)], $settings), ...$settings];
	}

	/** @return list<float> */
	public function query(string $query): array {
		$settings = $this->enabledSettings();
		return $settings['provider'] === 'local'
			? $this->vectors->embed($query)
			: $this->remote(['type' => 'text', 'text' => $query], $settings)['vector'];
	}

	/** @return array{provider: string, model: string} */
	private function enabledSettings(): array {
		$settings = $this->policies->semanticSettings();
		if ($settings['provider'] === 'disabled') throw new \InvalidArgumentException('Semantic search is disabled by the administrator');
		return ['provider' => $settings['provider'], 'model' => $settings['model']];
	}

	/** @param array<string, string> $input
	 * @param array{provider: string, model: string} $settings
	 * @return array{vector: list<float>, concepts: list<string>}
	 */
	private function remote(array $input, array $settings): array {
		$policy = $this->policies->semanticSettings();
		if (!$policy['externalTransfer'] || $policy['endpoint'] === '') throw new \RuntimeException('external_transfer_disabled');
		$response = $this->clients->newClient()->post($policy['endpoint'], [
			'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
			'body' => json_encode(['model' => $settings['model'], 'input' => $input], JSON_THROW_ON_ERROR),
			'timeout' => 30, 'allow_redirects' => false,
		]);
		$body = $response->getBody();
		if ($response->getStatusCode() !== 200 || !is_string($body) || strlen($body) > 1048576) throw new \RuntimeException('semantic_provider_failed');
		$data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
		$vector = is_array($data['embedding'] ?? null) ? array_values($data['embedding']) : [];
		if ($vector === [] || count($vector) > 2048 || array_filter($vector, static fn (mixed $value): bool => !is_numeric($value) || !is_finite((float)$value)) !== []) {
			throw new \RuntimeException('invalid_semantic_embedding');
		}
		$concepts = is_array($data['concepts'] ?? null) ? array_slice(array_values(array_filter(array_map(
			static fn (mixed $value): string => mb_substr(trim((string)$value), 0, 80), $data['concepts'],
		), static fn (string $value): bool => $value !== '')), 0, 40) : [];
		return ['vector' => array_map('floatval', $vector), 'concepts' => $concepts];
	}
}
