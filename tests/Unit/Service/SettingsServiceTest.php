<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\Service;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;

class SettingsServiceTest extends TestCase {
	public function testDefaults(): void {
		$store = [];
		$settings = $this->createSettings($store);
		$this->assertFalse($settings->hasApiKey());
		$this->assertSame([], $settings->getModels(Application::MODALITY_TEXT));
		$this->assertSame(Application::DEFAULT_MAX_TOKENS, $settings->getMaxTokens());
		$this->assertSame(Application::DEFAULT_REQUEST_TIMEOUT, $settings->getRequestTimeout());
		$this->assertSame(Application::DEFAULT_CHUNK_SIZE, $settings->getChunkSize());
		$this->assertFalse($settings->isDataCollectionDenied());
		$this->assertSame(Application::DEFAULT_RUNTIMES[Application::MODALITY_IMAGE], $settings->getExpectedRuntime(Application::MODALITY_IMAGE));
	}

	public function testApiKeyIsStoredAndRemoved(): void {
		$store = [];
		$settings = $this->createSettings($store);
		$settings->setApiKey('  sk-or-v1-secret ');
		$this->assertSame('sk-or-v1-secret', $settings->getApiKey());
		$this->assertTrue($settings->getAdminConfig()['api_key_set']);
		$this->assertArrayNotHasKey('api_key', $settings->getAdminConfig());
		$settings->setApiKey('');
		$this->assertFalse($settings->hasApiKey());
	}

	public function testAdminConfigIsValidatedAndClamped(): void {
		$store = [];
		$settings = $this->createSettings($store);
		$changed = $settings->setAdminConfig([
			'text_models' => ['openai/gpt-5-mini', ' openai/gpt-5-mini ', '', 42, 'anthropic/claude-sonnet-4'],
			'image_models' => 'not a list',
			'max_tokens' => '0',
			'request_timeout' => 999999,
			'chunk_size' => 10,
			'data_collection_deny' => 'true',
			'zdr' => false,
			'tts_voice' => ' nova ',
			'unknown_key' => 'ignored',
		]);
		$this->assertTrue($changed);
		$this->assertSame(['openai/gpt-5-mini', 'anthropic/claude-sonnet-4'], $settings->getModels(Application::MODALITY_TEXT));
		$this->assertSame([], $settings->getModels(Application::MODALITY_IMAGE));
		$this->assertSame(1, $settings->getMaxTokens());
		$this->assertSame(Application::MAX_REQUEST_TIMEOUT, $settings->getRequestTimeout());
		$this->assertSame(Application::MIN_CHUNK_SIZE, $settings->getChunkSize());
		$this->assertTrue($settings->isDataCollectionDenied());
		$this->assertFalse($settings->isZdrOnly());
		$this->assertSame('nova', $settings->getTtsVoice());
		$this->assertArrayNotHasKey('unknown_key', $store);

		$this->assertFalse($settings->setAdminConfig(['text_models' => ['openai/gpt-5-mini', 'anthropic/claude-sonnet-4']]));
	}

	public function testModelMetadata(): void {
		$store = [];
		$settings = $this->createSettings($store);
		$settings->setModelMetadata([
			'openai/gpt-5-mini' => ['name' => 'OpenAI: GPT-5 Mini', 'input_modalities' => ['text', 'image', 7], 'output_modalities' => ['text'], 'supported_voices' => []],
			'' => ['name' => 'dropped'],
		]);
		$this->assertSame('OpenAI: GPT-5 Mini', $settings->getModelName('openai/gpt-5-mini'));
		$this->assertSame(['text', 'image'], $settings->getModelInputModalities('openai/gpt-5-mini'));
		$this->assertSame('unknown/model', $settings->getModelName('unknown/model'));
		$this->assertSame(['text'], $settings->getModelInputModalities('unknown/model'));
		$this->assertCount(1, $settings->getModelMetadata());
	}

	public function testRuntimeEstimateIsLowPassFiltered(): void {
		$store = [];
		$settings = $this->createSettings($store);
		$settings->updateExpectedRuntime(Application::MODALITY_TEXT, 115);
		// 0.9 * 15 + 0.1 * 115 = 25
		$this->assertSame(25, $settings->getExpectedRuntime(Application::MODALITY_TEXT));
		$settings->updateExpectedRuntime(Application::MODALITY_TEXT, 0);
		$this->assertSame(25, $settings->getExpectedRuntime(Application::MODALITY_TEXT));
	}
}
