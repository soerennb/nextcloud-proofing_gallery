<?php

declare(strict_types=1);

// Development-only export using the canonical Nextcloud container's renderer:
// docker compose exec -T --user "$(id -u):$(id -g)" nextcloud php custom_apps/proofing_gallery/scripts/build-favicons.php
// Served assets never require Imagick at runtime.
if (!extension_loaded('imagick')) throw new RuntimeException('Rebuilding favicons requires PHP Imagick with SVG and ICO support');
$directory = dirname(__DIR__) . '/img';
$render = static function (int $size) use ($directory): Imagick {
	$image = new Imagick();
	$image->setBackgroundColor('transparent');
	$image->setResolution($size * 72 / 32, $size * 72 / 32);
	$image->readImageBlob('<?xml version="1.0"?>' . file_get_contents($directory . '/favicon.svg'));
	$image->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
	$image->setImageFormat('PNG32');
	$image->stripImage();
	return $image;
};
$touch = $render(180);
$touch->writeImage($directory . '/favicon-touch.png');
$ico = new Imagick();
$ico->setFormat('ICO');
foreach ([16, 32, 48] as $size) $ico->addImage($render($size));
$ico->writeImages($directory . '/favicon.ico', true);
echo "Exported gallery ICO and 180 px touch icon.\n";
