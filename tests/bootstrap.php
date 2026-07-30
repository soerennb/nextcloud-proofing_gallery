<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
