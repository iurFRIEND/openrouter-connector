<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Listener;

use OCA\OpenRouterConnector\TaskProcessing\ProviderFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\TaskProcessing\Events\GetTaskProcessingProvidersEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands the providers built from the admin's model selection to the server.
 *
 * @template-implements IEventListener<GetTaskProcessingProvidersEvent>
 */
class TaskProcessingProviderListener implements IEventListener {
	public function __construct(
		private ProviderFactory $providerFactory,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof GetTaskProcessingProvidersEvent) {
			return;
		}
		try {
			foreach ($this->providerFactory->getProviders() as $provider) {
				$event->addProvider($provider);
			}
		} catch (Throwable $e) {
			$this->logger->error('Could not build the OpenRouter task processing providers', ['exception' => $e]);
		}
	}
}
