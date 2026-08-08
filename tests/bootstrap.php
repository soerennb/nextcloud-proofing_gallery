<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// IRootFolder extends this server-internal interface, which is intentionally
// absent from the public nextcloud/ocp Composer package used by unit tests.
require_once __DIR__ . '/compat/OCHooksEmitter.php';

// nextcloud/ocp ships API stubs without a Composer namespace mapping.
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}
	$path = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});

require_once __DIR__ . '/compat/FilesLoadAdditionalScriptsEvent.php';
require_once __DIR__ . '/compat/ContextChat.php';
require_once __DIR__ . '/compat/OpenMetrics.php';
require_once __DIR__ . '/compat/SymfonyConsole.php';
