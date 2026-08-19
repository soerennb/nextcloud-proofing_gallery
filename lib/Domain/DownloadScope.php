<?php

declare(strict_types=1);

namespace OCA\ProofingGallery\Domain;

enum DownloadScope: string {
	case None = 'none';
	case Individual = 'individual';
	case Selection = 'selection';
	case All = 'all';

	public function allowsIndividual(): bool {
		return $this === self::Individual || $this === self::All;
	}

	public function allowsSelection(): bool {
		return $this === self::Selection || $this === self::All;
	}

	public function allowsGallery(): bool {
		return $this === self::All;
	}

	public function restrict(self $other): self {
		$individual = $this->allowsIndividual() && $other->allowsIndividual();
		$selection = $this->allowsSelection() && $other->allowsSelection();
		return match ([$individual, $selection]) {
			[true, true] => self::All,
			[true, false] => self::Individual,
			[false, true] => self::Selection,
			default => self::None,
		};
	}
}
