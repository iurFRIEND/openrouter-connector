<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\TaskProcessing;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Service\ChatMessageBuilder;
use OCA\OpenRouterConnector\Service\ChunkService;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCA\OpenRouterConnector\Service\SettingsService;
use OCP\IL10N;
use OCP\TaskProcessing\IProvider;
use Psr\Log\LoggerInterface;

/**
 * Builds one task processing provider per (selected model, task type).
 *
 * The providers depend on the admin's model selection, so they cannot be
 * registered as classes and resolved by the DI container. They are built here
 * from the app configuration alone, without any network request, and handed to
 * the server through the TaskProcessingProviderListener.
 */
class ProviderFactory {
	/** @var list<class-string<AbstractTextProvider>> */
	public const TEXT_PROVIDER_CLASSES = [
		TextToTextProvider::class,
		TextToTextChatProvider::class,
		TextToTextChatWithToolsProvider::class,
		SummaryProvider::class,
		HeadlineProvider::class,
		TopicsProvider::class,
		ContextWriteProvider::class,
		ReformulateProvider::class,
		ProofreadProvider::class,
		ChangeToneProvider::class,
		FormalizationProvider::class,
		SimplificationProvider::class,
		ReformatParagraphsProvider::class,
		EmojiProvider::class,
		TranslateProvider::class,
	];

	/** @var list<class-string<AbstractTextProvider>> */
	public const VISION_PROVIDER_CLASSES = [
		AnalyzeImagesProvider::class,
		ImageToTextOcrProvider::class,
	];

	public function __construct(
		private OpenRouterApiService $api,
		private SettingsService $settings,
		private ChunkService $chunkService,
		private ChatMessageBuilder $messageBuilder,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * All providers the current configuration exposes
	 *
	 * @return list<IProvider>
	 */
	public function getProviders(): array {
		$providers = [];
		foreach ($this->settings->getModels(Application::MODALITY_TEXT) as $model) {
			foreach (self::TEXT_PROVIDER_CLASSES as $class) {
				$providers[] = $this->build($class, $model);
			}
			if (in_array('image', $this->settings->getModelInputModalities($model), true)) {
				foreach (self::VISION_PROVIDER_CLASSES as $class) {
					$providers[] = $this->build($class, $model);
				}
			}
		}
		foreach ($this->settings->getModels(Application::MODALITY_IMAGE) as $model) {
			$providers[] = $this->build(TextToImageProvider::class, $model);
		}
		foreach ($this->settings->getModels(Application::MODALITY_STT) as $model) {
			$providers[] = $this->build(AudioToTextProvider::class, $model);
		}
		foreach ($this->settings->getModels(Application::MODALITY_TTS) as $model) {
			$providers[] = $this->build(TextToSpeechProvider::class, $model);
		}
		return $providers;
	}

	/**
	 * @template T of AbstractProvider
	 * @param class-string<T> $class
	 * @return T
	 */
	private function build(string $class, string $model): AbstractProvider {
		return new $class($this->api, $this->settings, $this->chunkService, $this->messageBuilder, $this->l, $this->logger, $model);
	}
}
