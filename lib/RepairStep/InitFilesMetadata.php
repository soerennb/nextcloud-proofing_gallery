<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\RepairStep;

use OCA\ProofingGallery\Listener\GalleryFilesMetadataProvider;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IMetadataValueWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class InitFilesMetadata implements IRepairStep {
	public function __construct(private IFilesMetadataManager $metadata) {
	}

	public function getName(): string {
		return 'Register privacy-safe Proofing Gallery file metadata';
	}

	public function run(IOutput $output): void {
		$this->metadata->initMetadata(GalleryFilesMetadataProvider::SOURCE_KEY, IMetadataValueWrapper::TYPE_BOOL, true, IMetadataValueWrapper::EDIT_FORBIDDEN);
		$this->metadata->initMetadata(GalleryFilesMetadataProvider::STATE_KEY, IMetadataValueWrapper::TYPE_STRING_LIST, false, IMetadataValueWrapper::EDIT_FORBIDDEN);
	}
}
