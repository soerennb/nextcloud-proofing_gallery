<?php

declare(strict_types=1);

namespace Symfony\Component\Console\Output;

if (!interface_exists(OutputInterface::class)) {
	interface OutputInterface {
		public function writeln(iterable|string $messages, int $options = 0): void;
	}
}
