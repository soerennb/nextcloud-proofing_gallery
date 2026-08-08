<?php

declare(strict_types=1);

// Context Agent uses these current-user OCS routes. Attribute routes remain
// available to the first-party web client under /apps/proofing_gallery.
return [
	'ocs' => [
		['name' => 'FileIntegration#open', 'url' => '/api/v1/files/open/{fileId}', 'verb' => 'GET'],
		['name' => 'FileIntegration#create', 'url' => '/api/v1/files/create/{fileId}', 'verb' => 'POST'],
		['name' => 'Agent#galleries', 'url' => '/api/v1/agent/galleries', 'verb' => 'GET'],
		['name' => 'Agent#gallery', 'url' => '/api/v1/agent/galleries/{galleryId}', 'verb' => 'GET'],
		['name' => 'Agent#readiness', 'url' => '/api/v1/agent/galleries/{galleryId}/readiness', 'verb' => 'GET'],
		['name' => 'Agent#feedback', 'url' => '/api/v1/agent/galleries/{galleryId}/feedback', 'verb' => 'GET'],
		['name' => 'Agent#reviews', 'url' => '/api/v1/agent/galleries/{galleryId}/reviews', 'verb' => 'GET'],
		['name' => 'Agent#media', 'url' => '/api/v1/agent/galleries/{galleryId}/media', 'verb' => 'GET'],
		['name' => 'Agent#presets', 'url' => '/api/v1/agent/presets', 'verb' => 'GET'],
		['name' => 'Agent#create', 'url' => '/api/v1/agent/galleries', 'verb' => 'POST'],
		['name' => 'Agent#update', 'url' => '/api/v1/agent/galleries/{galleryId}', 'verb' => 'PUT'],
		['name' => 'Agent#applyPreset', 'url' => '/api/v1/agent/galleries/{galleryId}/preset', 'verb' => 'POST'],
		['name' => 'Agent#publish', 'url' => '/api/v1/agent/galleries/{galleryId}/publish', 'verb' => 'POST'],
		['name' => 'Agent#revoke', 'url' => '/api/v1/agent/galleries/{galleryId}/publish', 'verb' => 'DELETE'],
		['name' => 'Agent#archive', 'url' => '/api/v1/agent/galleries/{galleryId}/archive', 'verb' => 'POST'],
		['name' => 'Agent#restore', 'url' => '/api/v1/agent/galleries/{galleryId}/restore', 'verb' => 'POST'],
		['name' => 'Agent#complete', 'url' => '/api/v1/agent/galleries/{galleryId}/complete', 'verb' => 'POST'],
		['name' => 'Agent#saveManager', 'url' => '/api/v1/agent/galleries/{galleryId}/managers', 'verb' => 'PUT'],
		['name' => 'Agent#removeManager', 'url' => '/api/v1/agent/galleries/{galleryId}/managers/{managerId}', 'verb' => 'DELETE'],
		['name' => 'Agent#transitionReview', 'url' => '/api/v1/agent/galleries/{galleryId}/public-links/{linkId}/review/{transition}', 'verb' => 'POST'],
	],
];
