<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\TaskProcessing;

use OCA\OpenRouterConnector\Exception\OpenRouterApiException;
use OCA\OpenRouterConnector\Service\ChatMessageBuilder;
use OCA\OpenRouterConnector\Service\ChunkService;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCA\OpenRouterConnector\Service\SettingsService;
use OCA\OpenRouterConnector\TaskProcessing\AudioToTextProvider;
use OCA\OpenRouterConnector\TaskProcessing\ChangeToneProvider;
use OCA\OpenRouterConnector\TaskProcessing\SummaryProvider;
use OCA\OpenRouterConnector\TaskProcessing\TextToImageProvider;
use OCA\OpenRouterConnector\TaskProcessing\TextToSpeechProvider;
use OCA\OpenRouterConnector\TaskProcessing\TextToTextChatProvider;
use OCA\OpenRouterConnector\TaskProcessing\TextToTextChatWithToolsProvider;
use OCA\OpenRouterConnector\TaskProcessing\TextToTextProvider;
use OCA\OpenRouterConnector\TaskProcessing\TranslateProvider;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;
use OCP\Files\File;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\Exception\UserFacingProcessingException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

class ProvidersTest extends TestCase {
	/** @var array<string, mixed> */
	private array $store = [];
	private OpenRouterApiService&MockObject $api;
	private SettingsService $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->store = ['api_key' => 'k', 'chunk_size' => 1000];
		$this->api = $this->createMock(OpenRouterApiService::class);
		$this->settings = $this->createSettings($this->store);
	}

	/**
	 * @template T
	 * @param class-string<T> $class
	 * @return T
	 */
	private function createProvider(string $class, string $model = 'openai/gpt-5-mini') {
		$l = $this->createL10n();
		return new $class($this->api, $this->settings, new ChunkService($this->settings), new ChatMessageBuilder($l), $l, new NullLogger(), $model);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function completion(string $content, array $overrides = []): array {
		return array_merge(['content' => $content, 'tool_calls' => [], 'reasoning' => '', 'finish_reason' => 'stop', 'usage' => []], $overrides);
	}

	private static function progress(): callable {
		return static fn (float $progress): bool => true;
	}

	public function testFreePrompt(): void {
		$this->api->expects($this->once())->method('createChatCompletion')
			->with('openai/gpt-5-mini', [['role' => 'user', 'content' => 'Say hi']], 42, [])
			->willReturn(self::completion('Hi!'));
		$provider = $this->createProvider(TextToTextProvider::class);
		$this->assertSame(['output' => 'Hi!'], $provider->process('admin', ['input' => 'Say hi', 'max_tokens' => 42], self::progress()));
		$this->assertSame('core:text2text', $provider->getTaskTypeId());
		$this->assertSame(EShapeType::Number, $provider->getOptionalInputShape()['max_tokens']->getShapeType());
	}

	public function testEmptyCompletionIsAnError(): void {
		$this->api->method('createChatCompletion')->willReturn(self::completion('   '));
		$this->expectException(UserFacingProcessingException::class);
		$this->createProvider(TextToTextProvider::class)->process(null, ['input' => 'x'], self::progress());
	}

	public function testApiErrorsPropagateUnchanged(): void {
		$exception = new OpenRouterApiException('401', 401, null, 'The OpenRouter API key is invalid.', 401);
		$this->api->method('createChatCompletion')->willThrowException($exception);
		try {
			$this->createProvider(TextToTextProvider::class)->process(null, ['input' => 'x'], self::progress());
			$this->fail('expected an exception');
		} catch (ProcessingException $e) {
			$this->assertSame($exception, $e);
		}
	}

	public function testCancelledTaskStopsProcessing(): void {
		$this->api->expects($this->never())->method('createChatCompletion');
		$this->expectException(ProcessingException::class);
		$this->createProvider(SummaryProvider::class)->process(null, ['input' => 'text'], static fn (float $p): bool => false);
	}

	public function testChatWithHistoryAndMemories(): void {
		$this->api->expects($this->once())->method('createChatCompletion')
			->willReturnCallback(function (string $model, array $messages): array {
				$this->assertSame('system', $messages[0]['role']);
				$this->assertStringContainsString('Be nice.', $messages[0]['content']);
				$this->assertStringContainsString('- likes cats', $messages[0]['content']);
				$this->assertSame(['role' => 'user', 'content' => 'Hi'], $messages[1]);
				$this->assertSame(['role' => 'assistant', 'content' => 'Hello!'], $messages[2]);
				$this->assertSame(['role' => 'user', 'content' => 'What do I like?'], $messages[3]);
				return self::completion('Cats!');
			});
		$output = $this->createProvider(TextToTextChatProvider::class)->process('admin', [
			'system_prompt' => 'Be nice.',
			'input' => 'What do I like?',
			'history' => [json_encode(['role' => 'human', 'content' => 'Hi']), json_encode(['role' => 'assistant', 'content' => 'Hello!'])],
			'memories' => ['likes cats', ''],
		], self::progress());
		$this->assertSame(['output' => 'Cats!'], $output);
	}

	public function testChatWithToolsReturnsToolCalls(): void {
		$this->api->expects($this->once())->method('createChatCompletion')
			->with('openai/gpt-5-mini', $this->anything(), null, ['tools' => [['type' => 'function', 'function' => ['name' => 'get_weather']]]])
			->willReturn(self::completion('', ['tool_calls' => [['id' => 'call_1', 'name' => 'get_weather', 'args' => ['city' => 'Berlin']], ['id' => 'call_2', 'name' => 'noop', 'args' => []]], 'finish_reason' => 'tool_calls']));
		$output = $this->createProvider(TextToTextChatWithToolsProvider::class)->process('admin', [
			'system_prompt' => 'sys',
			'input' => 'Weather in Berlin?',
			'tool_message' => '',
			'tools' => json_encode([['type' => 'function', 'function' => ['name' => 'get_weather']]]),
			'history' => [],
		], self::progress());
		$this->assertSame('', $output['output']);
		$this->assertSame('[{"name":"get_weather","id":"call_1","args":{"city":"Berlin"}},{"name":"noop","id":"call_2","args":{}}]', $output['tool_calls']);
	}

	public function testChangeToneUsesTheSelectedTone(): void {
		$this->api->expects($this->once())->method('createChatCompletion')
			->willReturnCallback(function (string $model, array $messages): array {
				$this->assertStringContainsString('sounds friendlier', $messages[0]['content']);
				$this->assertSame('Give me the report.', $messages[1]['content']);
				return self::completion('Could you please share the report?');
			});
		$provider = $this->createProvider(ChangeToneProvider::class);
		$this->assertContains('friendlier', array_map(static fn ($v) => $v->getValue(), $provider->getInputShapeEnumValues()['tone']));
		$output = $provider->process(null, ['input' => 'Give me the report.', 'tone' => 'friendlier'], self::progress());
		$this->assertSame(['output' => 'Could you please share the report?'], $output);
	}

	public function testLongTextsAreProcessedChunkByChunk(): void {
		$this->store['chunk_size'] = 500; // 1500 characters per chunk
		$text = str_repeat('A sentence about something. ', 200); // 5600 characters
		$calls = 0;
		$this->api->method('createChatCompletion')->willReturnCallback(function (string $model, array $messages) use (&$calls): array {
			$calls++;
			$this->assertLessThanOrEqual(1500, mb_strlen($messages[1]['content']));
			return self::completion('chunk ' . $calls);
		});
		$progress = [];
		$output = $this->createProvider(TranslateProvider::class)->process(null, [
			'input' => $text,
			'origin_language' => 'detect_language',
			'target_language' => 'de',
		], static function (float $p) use (&$progress): bool {
			$progress[] = $p;
			return true;
		});
		$this->assertGreaterThanOrEqual(4, $calls);
		$this->assertSame(implode("\n\n", array_map(static fn (int $i): string => 'chunk ' . $i, range(1, $calls))), $output['output']);
		$this->assertSame(1.0, end($progress));
	}

	public function testTranslatePrompt(): void {
		$this->assertStringContainsString('into Deutsch', TranslateProvider::buildSystemPrompt('detect_language', 'de'));
		$this->assertStringNotContainsString('from', TranslateProvider::buildSystemPrompt('detect_language', 'de'));
		$this->assertStringContainsString('from English into Français', TranslateProvider::buildSystemPrompt('en', 'fr'));
		$enumValues = $this->createProvider(TranslateProvider::class)->getInputShapeEnumValues();
		$this->assertSame('detect_language', $enumValues['origin_language'][0]->getValue());
		$this->assertGreaterThan(50, count($enumValues['target_language']));
	}

	public function testImageGeneration(): void {
		$this->api->expects($this->once())->method('generateImages')
			->with('openai/gpt-image-1', 'a cat', 2, ['aspect_ratio' => '16:9'])
			->willReturn([['data' => 'PNG1', 'media_type' => 'image/png'], ['data' => 'PNG2', 'media_type' => 'image/png']]);
		$output = $this->createProvider(TextToImageProvider::class, 'openai/gpt-image-1')->process(null, [
			'input' => 'a cat',
			'numberOfImages' => 2,
			'aspect_ratio' => '16:9',
			'quality' => 'auto',
		], self::progress());
		$this->assertSame(['images' => ['PNG1', 'PNG2']], $output);
	}

	public function testTooManyImagesAreRejected(): void {
		$this->api->expects($this->never())->method('generateImages');
		$this->expectException(UserFacingProcessingException::class);
		$this->createProvider(TextToImageProvider::class, 'img')->process(null, ['input' => 'a cat', 'numberOfImages' => 99], self::progress());
	}

	public function testTranscription(): void {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getSize')->willReturn(1234);
		$file->method('getMimeType')->willReturn('audio/mpeg');
		$file->method('getExtension')->willReturn('mp3');
		$file->method('getContent')->willReturn('mp3-bytes');
		$this->api->expects($this->once())->method('transcribe')
			->with('openai/whisper-1', 'mp3-bytes', 'mp3', 'de')
			->willReturn('Hallo Welt');
		$output = $this->createProvider(AudioToTextProvider::class, 'openai/whisper-1')->process(null, ['input' => $file, 'language' => 'de'], self::progress());
		$this->assertSame(['output' => 'Hallo Welt'], $output);
	}

	public function testAudioFormatDetection(): void {
		$this->assertSame('mp3', AudioToTextProvider::detectFormat('audio/mpeg', 'mp3'));
		$this->assertSame('wav', AudioToTextProvider::detectFormat('audio/x-wav; charset=binary', 'wav'));
		$this->assertSame('m4a', AudioToTextProvider::detectFormat('application/octet-stream', 'mp4'));
		$this->assertSame('ogg', AudioToTextProvider::detectFormat('application/octet-stream', 'OGG'));
		$this->assertNull(AudioToTextProvider::detectFormat('video/x-matroska', 'mkv'));
	}

	public function testSpeechWithWatermarkAndVoiceFallback(): void {
		$this->store['tts_voice'] = 'alloy';
		$this->store['model_metadata'] = ['tts/model' => ['name' => 'TTS', 'input_modalities' => ['text'], 'output_modalities' => ['speech'], 'supported_voices' => ['flux-a', 'flux-b']]];
		$this->api->expects($this->once())->method('createSpeech')
			->willReturnCallback(function (string $model, string $input, ?string $voice, float $speed): array {
				$this->assertSame('tts/model', $model);
				$this->assertStringStartsWith('Read me', $input);
				$this->assertStringContainsString('Artificial Intelligence', $input);
				$this->assertSame('flux-a', $voice, 'the configured default voice is not offered by the model, so its first voice is used');
				$this->assertSame(1.0, $speed);
				return ['body' => 'MP3', 'content-type' => 'audio/mpeg'];
			});
		$provider = $this->createProvider(TextToSpeechProvider::class, 'tts/model');
		$this->assertSame(EShapeType::Enum, $provider->getOptionalInputShape()['voice']->getShapeType());
		$this->assertSame('flux-a', $provider->getOptionalInputShapeDefaults()['voice']);
		$this->assertSame(['speech' => 'MP3'], $provider->process(null, ['input' => 'Read me'], self::progress(), true));
	}

	public function testSpeechWithoutWatermark(): void {
		$this->api->expects($this->once())->method('createSpeech')
			->with('tts/model', 'Read me', 'alloy', 2.0)
			->willReturn(['body' => 'MP3', 'content-type' => 'audio/mpeg']);
		$provider = $this->createProvider(TextToSpeechProvider::class, 'tts/model');
		$this->assertSame(EShapeType::Text, $provider->getOptionalInputShape()['voice']->getShapeType(), 'unknown voices are a free text field');
		$provider->process(null, ['input' => 'Read me', 'speed' => 2], self::progress(), false);
	}
}
