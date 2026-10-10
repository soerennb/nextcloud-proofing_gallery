<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\IPreview;

/** Find the nearest readable image without collecting the entire gallery tree. */
final class AutomaticCoverSelector {
	public function __construct(private MediaTypePolicy $mediaTypes, private IPreview $previews) {
	}

	public function find(Folder $root): ?File {
		$queue = new \SplQueue();
		$queue->enqueue($root);
		$seen = [];
		$video = null;
		while (!$queue->isEmpty()) {
			/** @var Folder $folder */
			$folder = $queue->dequeue();
			$key = $folder->getId();
			if (isset($seen[$key]) || !$folder->isReadable()) continue;
			$seen[$key] = true;
			try { $nodes = $folder->getDirectoryListing(); }
			catch (\OCP\Files\NotFoundException) { continue; }
			usort($nodes, static fn (Node $a, Node $b): int => strnatcasecmp($a->getName(), $b->getName()) ?: ($a->getId() <=> $b->getId()));
			foreach ($nodes as $node) {
				if (str_starts_with($node->getName(), '.') || !$node->isReadable()) continue;
				if ($node instanceof Folder) { $queue->enqueue($node); continue; }
				if (!$node instanceof File || !$this->mediaTypes->supports($node) || !$this->previews->isAvailable($node)) continue;
				if (str_starts_with($node->getMimeType(), 'image/')) return $node;
				$video ??= $node;
			}
		}
		return $video;
	}
}
