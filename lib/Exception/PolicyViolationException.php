<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Exception;

final class PolicyViolationException extends \RuntimeException {
	public function __construct(public readonly string $policyCode, string $message) {
		parent::__construct($message);
	}
}
