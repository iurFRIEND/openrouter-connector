<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\Service;

use OCA\OpenRouterConnector\Exception\OpenRouterApiException;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Mimics the exception the server's HTTP client throws for error responses
 */
class FakeHttpException extends \RuntimeException {
	public function __construct(
		private object $response,
	) {
		parent::__construct('HTTP error');
	}

	public function getResponse(): object {
		return $this->response;
	}
}

class FakeErrorResponse {
	public function __construct(
		private int $status,
		private string $body,
		private string $retryAfter = '',
	) {
	}

	public function getStatusCode(): int {
		return $this->status;
	}

	public function getBody(): string {
		return $this->body;
	}

	public function getHeaderLine(string $name): string {
		return $name === 'Retry-After' ? $this->retryAfter : '';
	}
}

class OpenRouterApiServiceTest extends TestCase {
	/** @var array<string, mixed> */
	private array $store;
	private IClient&MockObject $client;
	private OpenRouterApiService&MockObject $api;
	/** @var list<array{method: string, url: string, options: array<string, mixed>}> */
	private array $requests = [];
	/** @var list<int> the seconds the service wanted to wait before retrying */
	private array $waits = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = ['api_key' => 'sk-or-v1-test'];
		$this->client = $this->createMock(IClient::class);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->client);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');
		// Retries wait between attempts; the waiting is recorded instead of blocking the test
		$this->api = $this->getMockBuilder(OpenRouterApiService::class)
			->setConstructorArgs([$clientService, $this->createSettings($this->store), $urlGenerator, $this->createL10n(), new NullLogger()])
			->onlyMethods(['wait'])
			->getMock();
		$this->api->method('wait')->willReturnCallback(function (int $seconds): void {
			$this->waits[] = $seconds;
		});
	}

	/**
	 * Answers consecutive requests with consecutive bodies, all with status 200
	 *
	 * @param list<array<string, mixed>> $bodies
	 */
	private function respondInSequence(array $bodies): void {
		$responses = [];
		foreach ($bodies as $body) {
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn(json_encode($body));
			$response->method('getStatusCode')->willReturn(200);
			$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Content-Type' ? 'application/json' : '');
			$responses[] = $response;
		}
		$this->client->method('post')->willReturnCallback(function (string $url, array $options) use (&$responses): IResponse {
			$this->requests[] = ['method' => 'x', 'url' => $url, 'options' => $options];
			$response = array_shift($responses);
			$this->assertNotNull($response, 'more requests than prepared responses');
			return $response;
		});
	}

	/**
	 * @param array<string, mixed>|string $body
	 */
	private function respondWith(array|string $body, int $status = 200, string $contentType = 'application/json'): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(is_array($body) ? json_encode($body) : $body);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === 'Content-Type' ? $contentType : '');
		$record = function (string $url, array $options) use ($response): IResponse {
			$this->requests[] = ['method' => 'x', 'url' => $url, 'options' => $options];
			return $response;
		};
		$this->client->method('post')->willReturnCallback($record);
		$this->client->method('get')->willReturnCallback($record);
	}

	public function testChatCompletionRequestAndNormalization(): void {
		$this->respondWith([
			'id' => 'gen-1',
			'choices' => [[
				'finish_reason' => 'tool_calls',
				'message' => [
					'role' => 'assistant',
					'content' => 'Let me check.',
					'reasoning' => 'thinking',
					'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}']]],
				],
			]],
			'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0001],
		]);
		$this->store['data_collection_deny'] = true;
		$this->store['max_tokens'] = 500;

		$result = $this->api->createChatCompletion('openai/gpt-5-mini', [['role' => 'user', 'content' => 'Weather?']], 9999, ['tools' => [['type' => 'function']]]);

		$this->assertSame('Let me check.', $result['content']);
		$this->assertSame([['id' => 'call_1', 'name' => 'get_weather', 'args' => ['city' => 'Berlin']]], $result['tool_calls']);
		$this->assertSame('thinking', $result['reasoning']);
		$this->assertSame('tool_calls', $result['finish_reason']);
		$this->assertSame(0.0001, $result['usage']['cost']);

		$this->assertCount(1, $this->requests);
		$request = $this->requests[0];
		$this->assertSame('https://openrouter.ai/api/v1/chat/completions', $request['url']);
		$this->assertSame('Bearer sk-or-v1-test', $request['options']['headers']['Authorization']);
		$this->assertSame('application/json', $request['options']['headers']['Content-Type']);
		$this->assertArrayHasKey('X-Title', $request['options']['headers']);
		$this->assertArrayNotHasKey('HTTP-Referer', $request['options']['headers'], 'the referer is opt-in');
		$body = json_decode($request['options']['body'], true);
		$this->assertSame('openai/gpt-5-mini', $body['model']);
		$this->assertSame(500, $body['max_tokens'], 'max_tokens is capped at the configured limit');
		$this->assertSame(['data_collection' => 'deny'], $body['provider']);
		$this->assertSame([['type' => 'function']], $body['tools']);
	}

	public function testRefererIsSentWhenEnabled(): void {
		$this->store['send_referer'] = true;
		$this->respondWith(['choices' => [['message' => ['content' => 'ok']]]]);
		$this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
		$this->assertSame('https://cloud.example.org/', $this->requests[0]['options']['headers']['HTTP-Referer']);
	}

	public function testContentPartsAreJoined(): void {
		$this->respondWith(['choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'Hel'], ['type' => 'text', 'text' => 'lo']]]]]]);
		$this->assertSame('Hello', $this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']])['content']);
	}

	public function testMissingApiKeyIsReported(): void {
		unset($this->store['api_key']);
		$this->client->expects($this->never())->method('post');
		try {
			$this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
			$this->fail('expected an exception');
		} catch (OpenRouterApiException $e) {
			$this->assertStringContainsString('No OpenRouter API key', $e->getUserFacingMessage());
		}
	}

	public function testModelListDoesNotNeedAnApiKey(): void {
		unset($this->store['api_key']);
		$this->respondWith(['data' => [['id' => 'a/b', 'name' => 'B'], 'garbage']]);
		$models = $this->api->listModels(['output_modalities' => 'speech']);
		$this->assertSame([['id' => 'a/b', 'name' => 'B']], $models);
		$this->assertSame('https://openrouter.ai/api/v1/models?output_modalities=speech', $this->requests[0]['url']);
		$this->assertArrayNotHasKey('Authorization', $this->requests[0]['options']['headers']);
	}

	public function testHttpErrorsAreMappedToUserFacingMessages(): void {
		$this->client->method('post')->willThrowException(new FakeHttpException(new FakeErrorResponse(401, json_encode(['error' => ['code' => 401, 'message' => 'User not found.']]))));
		try {
			$this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
			$this->fail('expected an exception');
		} catch (OpenRouterApiException $e) {
			$this->assertSame(401, $e->getStatusCode());
			$this->assertStringContainsString('User not found.', $e->getMessage());
			$this->assertStringContainsString('API key is invalid', $e->getUserFacingMessage());
		}
	}

	public function testInsufficientCredits(): void {
		$this->client->method('post')->willThrowException(new FakeHttpException(new FakeErrorResponse(402, '{"error":{"code":402,"message":"Insufficient credits"}}')));
		try {
			$this->api->generateImages('img/model', 'a cat');
			$this->fail('expected an exception');
		} catch (OpenRouterApiException $e) {
			$this->assertSame(402, $e->getStatusCode());
			$this->assertStringContainsString('insufficient credits', $e->getUserFacingMessage());
		}
	}

	public function testErrorReportedWithStatus200(): void {
		$this->respondWith(['error' => ['code' => 429, 'message' => 'Rate limited', 'metadata' => ['provider_name' => 'X']]]);
		try {
			$this->api->transcribe('stt/model', 'bytes', 'mp3');
			$this->fail('expected an exception');
		} catch (OpenRouterApiException $e) {
			$this->assertSame(429, $e->getStatusCode());
			$this->assertStringContainsString('provider: X', $e->getMessage());
			$this->assertStringContainsString('rate limit', $e->getUserFacingMessage());
		}
		// a rate limit is retried twice before giving up, also when it comes with a 200 status
		$this->assertCount(3, $this->requests);
		$this->assertCount(2, $this->waits);
	}

	public function testRateLimitReportedWithStatus200IsRetried(): void {
		$this->respondInSequence([
			['error' => ['code' => 429, 'message' => 'openai/gpt-5.6-luna is temporarily rate-limited upstream. Please retry shortly']],
			['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hallo']]]],
		]);
		$result = $this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
		$this->assertSame('Hallo', $result['content']);
		$this->assertCount(2, $this->requests);
		$this->assertCount(1, $this->waits);
		$this->assertGreaterThanOrEqual(1, $this->waits[0]);
	}

	public function testHttp429IsRetriedAfterRetryAfterHeader(): void {
		$responses = [
			new FakeHttpException(new FakeErrorResponse(429, '{"error":{"code":429,"message":"Rate limited"}}', '3')),
			null,
		];
		$success = $this->createMock(IResponse::class);
		$success->method('getBody')->willReturn(json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'ok']]]]));
		$success->method('getStatusCode')->willReturn(200);
		$success->method('getHeader')->willReturn('application/json');
		$this->client->method('post')->willReturnCallback(function () use (&$responses, $success): IResponse {
			$next = array_shift($responses);
			if ($next instanceof \Throwable) {
				throw $next;
			}
			return $success;
		});
		$result = $this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
		$this->assertSame('ok', $result['content']);
		$this->assertSame([3], $this->waits);
	}

	public function testOtherErrorsAreNotRetried(): void {
		$this->respondWith(['error' => ['code' => 402, 'message' => 'Insufficient credits']]);
		try {
			$this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
			$this->fail('expected an exception');
		} catch (OpenRouterApiException $e) {
			$this->assertSame(402, $e->getStatusCode());
		}
		$this->assertCount(1, $this->requests);
		$this->assertSame([], $this->waits);
	}

	public function testConnectionErrorsAreUserFacing(): void {
		$this->client->method('post')->willThrowException(new \RuntimeException('Could not resolve host'));
		try {
			$this->api->createChatCompletion('m', [['role' => 'user', 'content' => 'x']]);
			$this->fail('expected an exception');
		} catch (OpenRouterApiException $e) {
			$this->assertSame(0, $e->getStatusCode());
			$this->assertStringContainsString('not reachable', $e->getUserFacingMessage());
		}
	}

	public function testImagesAreDecoded(): void {
		$this->respondWith(['data' => [['b64_json' => base64_encode('PNG1'), 'media_type' => 'image/png'], ['b64_json' => '!!!invalid']]]);
		$images = $this->api->generateImages('img/model', 'a cat', 2, ['aspect_ratio' => '16:9']);
		$this->assertSame([['data' => 'PNG1', 'media_type' => 'image/png']], $images);
		$body = json_decode($this->requests[0]['options']['body'], true);
		$this->assertSame(['model' => 'img/model', 'prompt' => 'a cat', 'n' => 2, 'aspect_ratio' => '16:9'], $body);
	}

	public function testTranscription(): void {
		$this->respondWith(['text' => 'hello world', 'usage' => ['seconds' => 2]]);
		$this->assertSame('hello world', $this->api->transcribe('openai/whisper-1', 'audio-bytes', 'mp3', 'de'));
		$body = json_decode($this->requests[0]['options']['body'], true);
		$this->assertSame('https://openrouter.ai/api/v1/audio/transcriptions', $this->requests[0]['url']);
		$this->assertSame(['data' => base64_encode('audio-bytes'), 'format' => 'mp3'], $body['input_audio']);
		$this->assertSame('de', $body['language']);
	}

	public function testSpeechIsReturnedRaw(): void {
		$this->respondWith('ID3-mp3-bytes', 200, 'audio/mpeg');
		$speech = $this->api->createSpeech('tts/model', 'Read this', 'alloy', 1.5);
		$this->assertSame(['body' => 'ID3-mp3-bytes', 'content-type' => 'audio/mpeg'], $speech);
		$body = json_decode($this->requests[0]['options']['body'], true);
		$this->assertSame(['model' => 'tts/model', 'input' => 'Read this', 'response_format' => 'mp3', 'voice' => 'alloy', 'speed' => 1.5], $body);
	}

	public function testKeyInfo(): void {
		$this->respondWith(['data' => ['label' => 'nextcloud', 'usage' => 1.5, 'limit' => null]]);
		$this->assertSame(['label' => 'nextcloud', 'usage' => 1.5, 'limit' => null], $this->api->getKeyInfo());
		$this->assertSame('https://openrouter.ai/api/v1/key', $this->requests[0]['url']);
	}
}
