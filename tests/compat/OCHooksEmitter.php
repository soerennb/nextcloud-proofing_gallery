<?php

declare(strict_types=1);

namespace OC\Hooks;

if (!interface_exists(Emitter::class)) {
	interface Emitter {
	}
}
