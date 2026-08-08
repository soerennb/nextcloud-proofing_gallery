<?php

declare(strict_types=1);

namespace OCP\ContextChat;

if (!interface_exists(IContentProvider::class)) {
	interface IContentProvider {
		public function getId(): string;
		public function getAppId(): string;
		public function getItemUrl(string $id): string;
		public function triggerInitialImport(): void;
	}
}

if (!interface_exists(IContentManager::class)) {
	interface IContentManager {
		public function isContextChatAvailable(): bool;
		public function registerContentProvider(string $appId, string $providerId, string $providerClass): void;
		public function submitContent(string $appId, array $items): void;
		public function updateAccessDeclarative(string $appId, string $providerId, string $itemId, array $userIds): void;
		public function deleteContent(string $appId, string $providerId, array $itemIds): void;
	}
}

if (!class_exists(ContentItem::class)) {
	class ContentItem {
		public function __construct(
			public string $itemId,
			public string $providerId,
			public string $title,
			public string $content,
			public string $documentType,
			public \DateTime $lastModified,
			public array $users,
		) {
		}
	}
}

namespace OCP\ContextChat\Events;

use OCP\ContextChat\IContentManager;
use OCP\EventDispatcher\Event;

if (!class_exists(ContentProviderRegisterEvent::class)) {
	class ContentProviderRegisterEvent extends Event {
		public function __construct(private IContentManager $manager) { parent::__construct(); }
		public function registerContentProvider(string $appId, string $providerId, string $providerClass): void {
			$this->manager->registerContentProvider($appId, $providerId, $providerClass);
		}
	}
}
