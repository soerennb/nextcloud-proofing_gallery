<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\BackgroundJob;

use OCA\ProofingGallery\Service\MediaCleanupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/** Only deletes backups whose stable identities match an acknowledged replacement. */
final class CleanupMediaReplacementJob extends QueuedJob {
	public function __construct(ITimeFactory $time, private IRootFolder $files, private MediaCleanupService $cleanup, private IJobList $jobs, private LoggerInterface $logger) { parent::__construct($time); }

	/** @param mixed $argument */
	protected function run($argument): void {
		try { $this->cleanup($argument); }
		catch (\Throwable $exception) {
			$retry = (int)($argument['retry'] ?? 0);
			if ($retry < 3) $this->jobs->add(self::class, [...$argument, 'retry' => $retry + 1]);
			$this->logger->error('Media replacement backup cleanup failed', ['fileId' => $argument['fileId'], 'exception' => $exception]);
		}
	}

	/** @param array<string, mixed> $argument */
	private function cleanup(array $argument): void {
		$root = $this->files->getUserFolder((string)$argument['userId']);
		foreach (['originalId', 'sidecarId'] as $key) {
			if (!isset($argument[$key])) continue;
			foreach ($root->getById((int)$argument[$key]) as $node) {
				if (!$node instanceof File || !str_starts_with($node->getName(), '.') || !str_ends_with($node->getName(), '.replaced-' . (int)$argument['fileId'])) continue;
				if ($key === 'originalId') $this->cleanup->purge((int)$argument[$key]);
				$node->delete();
			}
		}
	}
}
