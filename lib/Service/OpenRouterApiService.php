<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Service;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Exception\OpenRouterApiException;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * The HTTP client of the OpenRouter API
 *
 * @psalm-type ChatCompletion = array{content: string, tool_calls: list<array{id: string, name: string, args: array<array-key, mixed>}>, reasoning: string, finish_reason: string, usage: array<string, mixed>}
 */
class OpenRouterApiService {
	private const MAX_RETRIES = 2;
	/** Longer waits than this are not worth blocking a worker for, in seconds */
	private const MAX_RETRY_WAIT = 60;

	public function __construct(
		private IClientService $clientService,
		private SettingsService $settings,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A chat completion with the messages already built
	 *
	 * @param list<array<string, mixed>> $messages
	 * @param int|null $maxTokens the output token limit, capped at the configured maximum
	 * @param array<string, mixed> $extraParams additional request parameters, for example response_format or tools
	 * @return ChatCompletion
	 * @throws OpenRouterApiException
	 */
	public function createChatCompletion(string $model, array $messages, ?int $maxTokens = null, array $extraParams = []): array {
		$limit = $this->settings->getMaxTokens();
		if ($maxTokens === null || $maxTokens <= 0 || $maxTokens > $limit) {
			$maxTokens = $limit;
		}
		$params = [
			'model' => $model,
			'messages' => $messages,
			'max_tokens' => $maxTokens,
		];
		$providerPreferences = $this->getProviderPreferences();
		if ($providerPreferences !== []) {
			$params['provider'] = $providerPreferences;
		}
		$params = array_merge($params, $extraParams);
		return $this->normalizeChatCompletion($this->request('chat/completions', $params));
	}

	/**
	 * Generates images with the dedicated image API
	 *
	 * @param array<string, mixed> $options for example aspect_ratio, quality or output_format
	 * @return non-empty-list<array{data: string, media_type: string}> the decoded images
	 * @throws OpenRouterApiException
	 */
	public function generateImages(string $model, string $prompt, int $n = 1, array $options = []): array {
		$params = array_merge([
			'model' => $model,
			'prompt' => $prompt,
			'n' => max(1, min($n, Application::MAX_IMAGES_PER_REQUEST)),
		], $options);
		$response = $this->request('images', $params);
		$data = $response['data'] ?? null;
		$images = [];
		if (is_array($data)) {
			foreach ($data as $item) {
				if (!is_array($item) || !is_string($item['b64_json'] ?? null)) {
					continue;
				}
				$decoded = base64_decode($item['b64_json'], true);
				if ($decoded === false || $decoded === '') {
					continue;
				}
				$mediaType = is_string($item['media_type'] ?? null) ? $item['media_type'] : 'image/png';
				$images[] = ['data' => $decoded, 'media_type' => $mediaType];
			}
		}
		if ($images === []) {
			$this->logger->warning('OpenRouter image generation returned no image', ['response_keys' => array_keys($response)]);
			throw new OpenRouterApiException('No image in OpenRouter response', 0, null, $this->l->t('OpenRouter did not return an image'));
		}
		return $images;
	}

	/**
	 * Transcribes audio with the dedicated speech-to-text API
	 *
	 * @param string $audio the raw audio bytes
	 * @param string $format the audio format, for example mp3 or wav
	 * @param string|null $language an ISO 639-1 code, null to let the model detect the language
	 * @throws OpenRouterApiException
	 */
	public function transcribe(string $model, string $audio, string $format, ?string $language = null): string {
		$params = [
			'model' => $model,
			'input_audio' => [
				'data' => base64_encode($audio),
				'format' => $format,
			],
		];
		if ($language !== null && $language !== '') {
			$params['language'] = $language;
		}
		$response = $this->request('audio/transcriptions', $params);
		if (!is_string($response['text'] ?? null)) {
			$this->logger->warning('OpenRouter transcription returned no text', ['response_keys' => array_keys($response)]);
			throw new OpenRouterApiException('No text in OpenRouter transcription response', 0, null, $this->l->t('OpenRouter did not return a transcription'));
		}
		return $response['text'];
	}

	/**
	 * Generates speech with the dedicated text-to-speech API
	 *
	 * @param string|null $voice null to use the provider's default voice
	 * @return array{body: string, content-type: string} the MP3 audio
	 * @throws OpenRouterApiException
	 */
	public function createSpeech(string $model, string $input, ?string $voice = null, float $speed = 1.0): array {
		$params = [
			'model' => $model,
			'input' => $input,
			'response_format' => 'mp3',
		];
		if ($voice !== null && $voice !== '') {
			$params['voice'] = $voice;
		}
		if ($speed !== 1.0) {
			$params['speed'] = $speed;
		}
		$response = $this->request('audio/speech', $params, 'POST', true, true);
		$body = $response['body'] ?? null;
		if (!is_string($body) || $body === '') {
			throw new OpenRouterApiException('No audio in OpenRouter speech response', 0, null, $this->l->t('OpenRouter did not return any audio'));
		}
		$contentType = is_string($response['content-type'] ?? null) ? $response['content-type'] : 'audio/mpeg';
		return ['body' => $body, 'content-type' => $contentType];
	}

	/**
	 * The models of the general catalog; the list is public, so no API key is needed
	 *
	 * @param array<string, string> $query for example ['output_modalities' => 'speech']
	 * @return list<array<string, mixed>>
	 * @throws OpenRouterApiException
	 */
	public function listModels(array $query = []): array {
		$response = $this->request('models', $query, 'GET', false);
		return $this->extractList($response);
	}

	/**
	 * The models the configured API key may actually use: the catalog as
	 * OpenRouter narrows it down for the account's provider preferences and
	 * privacy settings, for the guardrails of the key, and for the region of
	 * a regional endpoint
	 *
	 * @param array<string, string> $query for example ['output_modalities' => 'all']
	 * @return list<array<string, mixed>>
	 * @throws OpenRouterApiException
	 */
	public function listUserModels(array $query = []): array {
		$response = $this->request('models/user', $query, 'GET');
		return $this->extractList($response);
	}

	/**
	 * The models of the dedicated image API
	 *
	 * @return list<array<string, mixed>>
	 * @throws OpenRouterApiException
	 */
	public function listImageModels(): array {
		$response = $this->request('images/models', [], 'GET', false);
		return $this->extractList($response);
	}

	/**
	 * One model by its ID (author/slug, optionally with a variant suffix)
	 *
	 * @return array<string, mixed>
	 * @throws OpenRouterApiException
	 */
	public function getModel(string $modelId): array {
		$response = $this->request('models/' . str_replace('%2F', '/', rawurlencode($modelId)), [], 'GET', false);
		$data = $response['data'] ?? $response;
		return is_array($data) ? $data : [];
	}

	/**
	 * Label, limits and usage of the configured API key
	 *
	 * @return array<string, mixed>
	 * @throws OpenRouterApiException
	 */
	public function getKeyInfo(): array {
		$response = $this->request('key', [], 'GET');
		$data = $response['data'] ?? $response;
		return is_array($data) ? $data : [];
	}

	/**
	 * Sends a request to the OpenRouter API
	 *
	 * @param array<string, mixed> $params the JSON body (POST) or the query parameters (GET)
	 * @param bool $binary whether the response is not JSON but raw bytes (audio)
	 * @return array<string, mixed> the decoded JSON, or ['body' => string, 'content-type' => string] for binary responses
	 * @throws OpenRouterApiException
	 */
	public function request(string $endpoint, array $params = [], string $method = 'POST', bool $requiresApiKey = true, bool $binary = false): array {
		$apiKey = $this->settings->getApiKey();
		if ($requiresApiKey && $apiKey === '') {
			throw new OpenRouterApiException(
				'No OpenRouter API key configured',
				0,
				null,
				$this->l->t('No OpenRouter API key is configured. Contact your administrator.'),
			);
		}

		$url = $this->settings->getApiBaseUrl() . '/' . ltrim($endpoint, '/');
		$headers = [
			'User-Agent' => Application::USER_AGENT,
			'X-Title' => Application::X_TITLE,
			'Accept' => $binary ? '*/*' : 'application/json',
		];
		if ($apiKey !== '') {
			$headers['Authorization'] = 'Bearer ' . $apiKey;
		}
		if ($this->settings->isSendReferer()) {
			$headers['HTTP-Referer'] = $this->urlGenerator->getAbsoluteURL('/');
		}
		$options = [
			'timeout' => $this->settings->getRequestTimeout(),
			'headers' => $headers,
		];
		if ($method === 'GET') {
			if ($params !== []) {
				$url .= '?' . http_build_query($params);
			}
		} else {
			$options['headers']['Content-Type'] = 'application/json';
			try {
				$options['body'] = json_encode($params, JSON_THROW_ON_ERROR);
			} catch (\JsonException $e) {
				throw new OpenRouterApiException('Could not encode the request: ' . $e->getMessage(), 0, $e, $this->l->t('The request could not be encoded'));
			}
		}

		$attempt = 0;
		while (true) {
			try {
				$client = $this->clientService->newClient();
				$response = $method === 'GET'
					? $client->get($url, $options)
					: $client->post($url, $options);
				$body = $response->getBody();
				if (is_resource($body)) {
					$body = stream_get_contents($body);
				}
				if (!is_string($body)) {
					throw new OpenRouterApiException('Unreadable OpenRouter response body', 0, null, $this->l->t('OpenRouter returned an unreadable response'));
				}
				$contentType = $response->getHeader('Content-Type');
				if ($binary) {
					if (str_starts_with(strtolower($contentType), 'application/json')) {
						$decoded = json_decode($body, true);
						if (is_array($decoded) && isset($decoded['error'])) {
							throw $this->exceptionFromBody($response->getStatusCode(), $decoded, $endpoint);
						}
					}
					return ['body' => $body, 'content-type' => $contentType];
				}
				$decoded = json_decode($body, true);
				if (!is_array($decoded)) {
					$this->logger->warning('OpenRouter returned invalid JSON for ' . $endpoint, ['body' => mb_substr($body, 0, 500)]);
					throw new OpenRouterApiException('Invalid JSON in OpenRouter response', 0, null, $this->l->t('OpenRouter returned an invalid response'));
				}
				if (isset($decoded['error']) && is_array($decoded['error'])) {
					// Some errors are reported with a 200 status, among them the
					// upstream rate limits of a model
					$status = self::toInt($decoded['error']['code'] ?? 0);
					$wait = $this->retryWait($status, $attempt, '', $endpoint);
					if ($wait !== null) {
						$attempt++;
						$this->wait($wait);
						continue;
					}
					throw $this->exceptionFromBody($status, $decoded, $endpoint);
				}
				return $decoded;
			} catch (OpenRouterApiException $e) {
				throw $e;
			} catch (\Throwable $e) {
				$errorResponse = self::extractErrorResponse($e);
				if ($errorResponse === null) {
					$this->logger->warning('OpenRouter connection error for ' . $endpoint . ': ' . $e->getMessage(), ['exception' => $e]);
					throw new OpenRouterApiException(
						'OpenRouter connection error: ' . $e->getMessage(),
						0,
						$e,
						$this->l->t('OpenRouter is currently not reachable. Contact your administrator.'),
					);
				}
				[$status, $errorBody, $retryAfter] = $errorResponse;
				$wait = $this->retryWait($status, $attempt, $retryAfter, $endpoint);
				if ($wait !== null) {
					$attempt++;
					$this->wait($wait);
					continue;
				}
				$decoded = json_decode($errorBody, true);
				$this->logger->warning('OpenRouter API error ' . $status . ' for ' . $endpoint, ['response_body' => mb_substr($errorBody, 0, 2000)]);
				throw $this->exceptionFromBody($status, is_array($decoded) ? $decoded : [], $endpoint, $e);
			}
		}
	}

	/**
	 * The routing preferences derived from the privacy settings
	 *
	 * @return array<string, mixed>
	 */
	private function getProviderPreferences(): array {
		$preferences = [];
		if ($this->settings->isDataCollectionDenied()) {
			$preferences['data_collection'] = 'deny';
		}
		if ($this->settings->isZdrOnly()) {
			$preferences['zdr'] = true;
		}
		return $preferences;
	}

	/**
	 * @param array<string, mixed> $response
	 * @return ChatCompletion
	 * @throws OpenRouterApiException
	 */
	private function normalizeChatCompletion(array $response): array {
		$choices = $response['choices'] ?? null;
		if (!is_array($choices) || !is_array($choices[0] ?? null)) {
			$this->logger->warning('OpenRouter chat completion without choices', ['response_keys' => array_keys($response)]);
			throw new OpenRouterApiException('No choices in OpenRouter response', 0, null, $this->l->t('OpenRouter returned an empty response'));
		}
		$choice = $choices[0];
		if (isset($choice['error']) && is_array($choice['error'])) {
			throw $this->exceptionFromBody(self::toInt($choice['error']['code'] ?? 0), $choice, 'chat/completions');
		}
		$message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

		$content = '';
		if (is_string($message['content'] ?? null)) {
			$content = $message['content'];
		} elseif (is_array($message['content'] ?? null)) {
			$texts = [];
			foreach ($message['content'] as $part) {
				if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
					$texts[] = $part['text'];
				}
			}
			$content = implode('', $texts);
		}

		$toolCalls = [];
		if (is_array($message['tool_calls'] ?? null)) {
			foreach ($message['tool_calls'] as $toolCall) {
				if (!is_array($toolCall) || !is_array($toolCall['function'] ?? null)) {
					continue;
				}
				$function = $toolCall['function'];
				$arguments = $function['arguments'] ?? '{}';
				$args = is_string($arguments) ? json_decode($arguments, true) : $arguments;
				$toolCalls[] = [
					'id' => is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '',
					'name' => is_string($function['name'] ?? null) ? $function['name'] : '',
					'args' => is_array($args) ? $args : [],
				];
			}
		}

		return [
			'content' => $content,
			'tool_calls' => $toolCalls,
			'reasoning' => is_string($message['reasoning'] ?? null) ? $message['reasoning'] : '',
			'finish_reason' => is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : '',
			'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
		];
	}

	/**
	 * @param array<string, mixed> $response
	 * @return list<array<string, mixed>>
	 */
	private function extractList(array $response): array {
		$data = $response['data'] ?? null;
		if (!is_array($data)) {
			return [];
		}
		$list = [];
		foreach ($data as $item) {
			if (is_array($item)) {
				$list[] = $item;
			}
		}
		return $list;
	}

	/**
	 * Builds the exception for an error response, with a message the user may see
	 *
	 * @param array<string, mixed> $decoded the decoded error body, if any
	 */
	private function exceptionFromBody(int $status, array $decoded, string $endpoint, ?\Throwable $previous = null): OpenRouterApiException {
		$error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
		$apiMessage = is_string($error['message'] ?? null) ? $error['message'] : '';
		if ($status === 0) {
			$status = self::toInt($error['code'] ?? 0);
		}
		$metadata = is_array($error['metadata'] ?? null) ? $error['metadata'] : [];
		$providerName = is_string($metadata['provider_name'] ?? null) ? $metadata['provider_name'] : '';
		$logMessage = 'OpenRouter API error ' . $status . ' for ' . $endpoint
			. ($apiMessage !== '' ? ': ' . $apiMessage : '')
			. ($providerName !== '' ? ' (provider: ' . $providerName . ')' : '');

		$userFacingMessage = match (true) {
			$status === 401 => $this->l->t('The OpenRouter API key is invalid. Contact your administrator.'),
			$status === 402 => $this->l->t('The OpenRouter account has insufficient credits. Contact your administrator.'),
			$status === 403 => $this->l->t('OpenRouter refused the request: %s', [$apiMessage !== '' ? $apiMessage : $this->l->t('forbidden or flagged by moderation')]),
			$status === 404 => $this->l->t('The selected model is not available on OpenRouter. Contact your administrator.'),
			$status === 408 => $this->l->t('The request to OpenRouter timed out. Please try again.'),
			$status === 429 => $this->l->t('The OpenRouter rate limit was reached. Please try again later.'),
			$status >= 500 => $this->l->t('OpenRouter or the model provider is currently unavailable. Please try again later.'),
			$apiMessage !== '' => $this->l->t('OpenRouter error: %s', [$apiMessage]),
			default => $this->l->t('The request to OpenRouter failed'),
		};
		return new OpenRouterApiException($logMessage, $status, $previous, $userFacingMessage, $status);
	}

	/**
	 * The status, body and Retry-After header of the response an HTTP client
	 * exception carries. The Guzzle exception classes are not part of the
	 * public API, so the response is read without depending on them.
	 *
	 * @return array{int, string, string}|null
	 */
	private static function extractErrorResponse(\Throwable $e): ?array {
		if (!method_exists($e, 'getResponse')) {
			return null;
		}
		$response = $e->getResponse();
		if (!is_object($response) || !method_exists($response, 'getStatusCode') || !method_exists($response, 'getBody')) {
			return null;
		}
		$status = self::toInt($response->getStatusCode());
		$body = $response->getBody();
		if (is_object($body) && method_exists($body, '__toString')) {
			$body = (string)$body;
		} elseif (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		$retryAfter = '';
		if (method_exists($response, 'getHeaderLine')) {
			$retryAfter = (string)$response->getHeaderLine('Retry-After');
		}
		return [$status, is_string($body) ? $body : '', $retryAfter];
	}

	/**
	 * Whether a failed request should be retried and how many seconds to wait
	 * first. Only rate limits and unavailable providers are retried, and only
	 * in background jobs, where a delay does not hold up a web request.
	 *
	 * @return int|null the seconds to wait, null if the request should not be retried
	 */
	private function retryWait(int $status, int $attempt, string $retryAfter, string $endpoint): ?int {
		if (!in_array($status, [429, 503], true) || $attempt >= self::MAX_RETRIES || PHP_SAPI !== 'cli') {
			return null;
		}
		$wait = self::retryDelay($retryAfter);
		if ($wait === null) {
			return null;
		}
		$this->logger->info('OpenRouter answered ' . $status . ' for ' . $endpoint . ', retrying in ' . $wait . ' seconds', ['attempt' => $attempt + 1]);
		return $wait;
	}

	/**
	 * Blocks before a retry; overridable so tests do not have to wait
	 */
	protected function wait(int $seconds): void {
		sleep($seconds);
	}

	/**
	 * How long to wait before retrying, null if it is not worth waiting
	 */
	private static function retryDelay(string $retryAfter): ?int {
		$retryAfter = trim($retryAfter);
		if ($retryAfter === '') {
			$delay = random_int(5, 20);
		} elseif (ctype_digit($retryAfter)) {
			$delay = (int)$retryAfter;
		} else {
			$timestamp = strtotime($retryAfter);
			$delay = $timestamp === false ? random_int(5, 20) : max(1, $timestamp - time());
		}
		if ($delay > self::MAX_RETRY_WAIT) {
			return null;
		}
		return max(1, $delay);
	}

	private static function toInt(mixed $value): int {
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && is_numeric($value)) {
			return (int)$value;
		}
		return 0;
	}
}
