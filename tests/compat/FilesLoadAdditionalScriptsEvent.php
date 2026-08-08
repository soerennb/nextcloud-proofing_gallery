<?php

declare(strict_types=1);

namespace OCA\Files\Event;

use OCP\EventDispatcher\Event;

// The files app owns this public integration event, so it is intentionally not
// part of the nextcloud/ocp package used for standalone analysis and unit tests.
if (!class_exists(LoadAdditionalScriptsEvent::class)) {
	class LoadAdditionalScriptsEvent extends Event {
	}
}
