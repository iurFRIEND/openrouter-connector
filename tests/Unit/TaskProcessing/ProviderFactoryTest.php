<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\TaskProcessing;

use OCA\OpenRouterConnector\Service\ChatMessageBuilder;
use OCA\OpenRouterConnector\Service\ChunkService;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCA\OpenRouterConnector\TaskProcessing\AbstractProvider;
use OCA\OpenRouterConnector\TaskProcessing\ProviderFactory;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\AnalyzeImages;
use OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition;
use OCP\TaskProcessing\TaskTypes\TextToImage;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use Psr\Log\NullLogger;

class ProviderFactoryTest extends TestCase {
	/**
	 * @param array<string, mixed> $store
	 */
	private function createFactory(array &$store): ProviderFactory {
		$settings = $this->createSettings($store);
		$l = $this->createL10n();
		return new ProviderFactory(
			$this->createMock(OpenRouterApiService::class),
			$settings,
			new ChunkService($settings),
			new ChatMessageBuilder($l),
			$l,
			new NullLogger(),
		);
	}

	public function testNoModelsNoProviders(): void {
		$store = [];
		$this->assertSame([], $this->createFactory($store)->getProviders());
	}

	public function testOneProviderPerModelAndTaskType(): void {
		$store = [
			'text_models' => ['openai/gpt-5-mini', 'meta-llama/llama-3-8b:free'],
			'image_models' => ['openai/gpt-image-1'],
			'stt_models' => ['openai/whisper-1'],
			'tts_models' => ['openai/gpt-4o-mini-tts'],
			'model_metadata' => [
				'openai/gpt-5-mini' => ['name' => 'OpenAI: GPT-5 Mini', 'input_modalities' => ['text', 'image', 'file'], 'output_modalities' => ['text'], 'supported_voices' => []],
				'meta-llama/llama-3-8b:free' => ['name' => 'Llama 3 8B (free)', 'input_modalities' => ['text'], 'output_modalities' => ['text'], 'supported_voices' => []],
			],
		];
		$providers = $this->createFactory($store)->getProviders();

		$textTaskTypes = count(ProviderFactory::TEXT_PROVIDER_CLASSES);
		$visionTaskTypes = count(ProviderFactory::VISION_PROVIDER_CLASSES);
		$this->assertCount(2 * $textTaskTypes + $visionTaskTypes + 3, $providers);

		$ids = array_map(static fn (ISynchronousProvider $provider): string => $provider->getId(), $providers);
		$this->assertSame($ids, array_unique($ids), 'provider IDs are unique');
		foreach ($ids as $id) {
			$this->assertMatchesRegularExpression('/^openrouter_connector-[A-Za-z0-9._-]+-[a-z0-9:-]+$/', $id);
		}

		$byTaskType = [];
		foreach ($providers as $provider) {
			$this->assertInstanceOf(AbstractProvider::class, $provider);
			$byTaskType[$provider->getTaskTypeId()][] = $provider->getModel();
		}
		$this->assertSame(['openai/gpt-5-mini', 'meta-llama/llama-3-8b:free'], $byTaskType[TextToTextSummary::ID]);
		$this->assertSame(['openai/gpt-5-mini'], $byTaskType[AnalyzeImages::ID], 'only models with image input analyze images');
		$this->assertSame(['openai/gpt-5-mini'], $byTaskType[ImageToTextOpticalCharacterRecognition::ID]);
		$this->assertSame(['openai/gpt-image-1'], $byTaskType[TextToImage::ID]);

		$summary = $providers[array_search(TextToTextSummary::ID, array_map(static fn ($p) => $p->getTaskTypeId(), $providers), true)];
		$this->assertSame('openrouter_connector-openai_gpt-5-mini-text2text:summary', $summary->getId());
		$this->assertSame('OpenAI: GPT-5 Mini (OpenRouter)', $summary->getName());
	}

	public function testUnknownModelsAreNamedByTheirId(): void {
		$store = ['text_models' => ['some/model:beta']];
		$provider = $this->createFactory($store)->getProviders()[0];
		$this->assertSame('some/model:beta (OpenRouter)', $provider->getName());
		$this->assertSame('openrouter_connector-some_model_beta-text2text', $provider->getId());
	}
}
