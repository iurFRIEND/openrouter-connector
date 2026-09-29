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
use OCP\TaskProcessing\TaskTypes\TextToTextImprove;
use OCP\TaskProcessing\TaskTypes\TextToTextReformatParagraphs;
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
	/**
	 * Text providers for the task types every supported server version has.
	 * Nextcloud 35 deprecates reformulation, formalization and simplification
	 * in favour of improving a text and hides them in the Assistant, but still
	 * runs them for other apps.
	 *
	 * @var list<class-string<AbstractTextProvider>>
	 */
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
		EmojiProvider::class,
		TranslateProvider::class,
	];

	/**
	 * Text providers for task types that not every supported server version
	 * has, with the task type class they need. A provider whose task type the
	 * server lacks must not be handed to it: asking such a provider for its
	 * ID already fails, and that takes down the task processing of the whole
	 * instance.
	 *
	 * @var array<class-string<AbstractTextProvider>, string>
	 */
	public const OPTIONAL_TEXT_PROVIDER_CLASSES = [
		// since Nextcloud 34
		ReformatParagraphsProvider::class => TextToTextReformatParagraphs::class,
		// since Nextcloud 35
		ImproveProvider::class => TextToTextImprove::class,
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
		$textProviderClasses = self::getTextProviderClasses();
		$providers = [];
		foreach ($this->settings->getModels(Application::MODALITY_TEXT) as $model) {
			foreach ($textProviderClasses as $class) {
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
	 * The text providers whose task types the running server version has
	 *
	 * @return list<class-string<AbstractTextProvider>>
	 */
	public static function getTextProviderClasses(): array {
		$classes = self::TEXT_PROVIDER_CLASSES;
		foreach (self::OPTIONAL_TEXT_PROVIDER_CLASSES as $class => $taskTypeClass) {
			if (class_exists($taskTypeClass)) {
				$classes[] = $class;
			}
		}
		return $classes;
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
