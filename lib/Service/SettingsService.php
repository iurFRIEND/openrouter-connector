<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Service;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Typed access to the app configuration
 *
 * @psalm-type ModelMetadata = array{name: string, input_modalities: list<string>, output_modalities: list<string>, supported_voices: list<string>}
 */
class SettingsService {
	public const KEY_API_KEY = 'api_key';
	public const KEY_MODEL_METADATA = 'model_metadata';
	public const KEY_TTS_VOICE = 'tts_voice';
	public const KEY_MAX_TOKENS = 'max_tokens';
	public const KEY_REQUEST_TIMEOUT = 'request_timeout';
	public const KEY_CHUNK_SIZE = 'chunk_size';
	public const KEY_DATA_COLLECTION_DENY = 'data_collection_deny';
	public const KEY_ZDR = 'zdr';
	public const KEY_SEND_REFERER = 'send_referer';
	public const KEY_RUNTIME_PREFIX = 'runtime_';

	/** The admin config keys holding the selected models, per modality */
	public const MODEL_KEYS = [
		Application::MODALITY_TEXT => 'text_models',
		Application::MODALITY_IMAGE => 'image_models',
		Application::MODALITY_STT => 'stt_models',
		Application::MODALITY_TTS => 'tts_models',
	];

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_API_KEY, '', lazy: true);
	}

	/**
	 * Stores the key encrypted (sensitive app config value); an empty key removes it
	 */
	public function setApiKey(string $apiKey): void {
		$apiKey = trim($apiKey);
		if ($apiKey === '') {
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_API_KEY);
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_API_KEY, $apiKey, lazy: true, sensitive: true);
	}

	public function hasApiKey(): bool {
		return $this->getApiKey() !== '';
	}

	/**
	 * The IDs of the models selected for a modality
	 *
	 * @return list<string>
	 */
	public function getModels(string $modality): array {
		$key = self::MODEL_KEYS[$modality] ?? null;
		if ($key === null) {
			return [];
		}
		return self::sanitizeModelList($this->appConfig->getValueArray(Application::APP_ID, $key, []));
	}

	/**
	 * @param array<mixed> $models
	 */
	public function setModels(string $modality, array $models): void {
		$key = self::MODEL_KEYS[$modality] ?? null;
		if ($key === null) {
			throw new \InvalidArgumentException('Unknown modality ' . $modality);
		}
		$this->appConfig->setValueArray(Application::APP_ID, $key, self::sanitizeModelList($models));
	}

	/**
	 * All selected models, of every modality, without duplicates
	 *
	 * @return list<string>
	 */
	public function getAllSelectedModels(): array {
		$models = [];
		foreach (array_keys(self::MODEL_KEYS) as $modality) {
			array_push($models, ...$this->getModels($modality));
		}
		return array_values(array_unique($models));
	}

	/**
	 * What is known about the selected models: their display name, modalities and voices
	 *
	 * @return array<string, ModelMetadata>
	 */
	public function getModelMetadata(): array {
		$stored = $this->appConfig->getValueArray(Application::APP_ID, self::KEY_MODEL_METADATA, []);
		$metadata = [];
		foreach ($stored as $modelId => $entry) {
			if (!is_string($modelId) || !is_array($entry)) {
				continue;
			}
			$metadata[$modelId] = self::sanitizeMetadataEntry($entry, $modelId);
		}
		return $metadata;
	}

	/**
	 * @param array<string, array<string, mixed>> $metadata
	 */
	public function setModelMetadata(array $metadata): void {
		$sanitized = [];
		foreach ($metadata as $modelId => $entry) {
			if (!is_string($modelId) || $modelId === '' || !is_array($entry)) {
				continue;
			}
			$sanitized[$modelId] = self::sanitizeMetadataEntry($entry, $modelId);
		}
		$this->appConfig->setValueArray(Application::APP_ID, self::KEY_MODEL_METADATA, $sanitized);
	}

	/**
	 * The display name of a model, falling back to its ID
	 */
	public function getModelName(string $modelId): string {
		$name = $this->getModelMetadata()[$modelId]['name'] ?? '';
		return $name !== '' ? $name : $modelId;
	}

	/**
	 * @return list<string>
	 */
	public function getModelInputModalities(string $modelId): array {
		return $this->getModelMetadata()[$modelId]['input_modalities'] ?? ['text'];
	}

	/**
	 * @return list<string>
	 */
	public function getModelVoices(string $modelId): array {
		return $this->getModelMetadata()[$modelId]['supported_voices'] ?? [];
	}

	public function getTtsVoice(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_TTS_VOICE, Application::DEFAULT_TTS_VOICE);
	}

	/**
	 * The default and upper limit of the number of tokens a text model may generate
	 */
	public function getMaxTokens(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_MAX_TOKENS, Application::DEFAULT_MAX_TOKENS);
		return max(1, min($value, Application::MAX_MAX_TOKENS));
	}

	/**
	 * In seconds
	 */
	public function getRequestTimeout(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_REQUEST_TIMEOUT, Application::DEFAULT_REQUEST_TIMEOUT);
		return max(Application::MIN_REQUEST_TIMEOUT, min($value, Application::MAX_REQUEST_TIMEOUT));
	}

	/**
	 * In tokens, 0 disables chunking
	 */
	public function getChunkSize(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_CHUNK_SIZE, Application::DEFAULT_CHUNK_SIZE);
		if ($value <= 0) {
			return 0;
		}
		return max(Application::MIN_CHUNK_SIZE, $value);
	}

	/**
	 * Whether requests may only be routed to providers that do not store or train on the data
	 */
	public function isDataCollectionDenied(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_DATA_COLLECTION_DENY, false);
	}

	/**
	 * Whether requests may only be routed to zero data retention endpoints
	 */
	public function isZdrOnly(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_ZDR, false);
	}

	/**
	 * Whether the URL of this instance is sent as HTTP-Referer for OpenRouter's usage attribution
	 */
	public function isSendReferer(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_SEND_REFERER, false);
	}

	/**
	 * The measured processing time of a modality, in seconds
	 */
	public function getExpectedRuntime(string $modality): int {
		$default = Application::DEFAULT_RUNTIMES[$modality] ?? Application::DEFAULT_RUNTIMES[Application::MODALITY_TEXT];
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_RUNTIME_PREFIX . $modality, $default, lazy: true);
	}

	/**
	 * Feeds a measured runtime into the low-pass filtered estimate of a modality
	 */
	public function updateExpectedRuntime(string $modality, int $runtime): void {
		if ($runtime <= 0) {
			return;
		}
		$old = $this->getExpectedRuntime($modality);
		$factor = Application::RUNTIME_LOWPASS_FACTOR;
		$new = (int)round((1.0 - $factor) * (float)$old + $factor * (float)$runtime);
		if ($new !== $old) {
			$this->appConfig->setValueInt(Application::APP_ID, self::KEY_RUNTIME_PREFIX . $modality, max(1, $new), lazy: true);
		}
	}

	/**
	 * The configuration as sent to the admin settings frontend, without the secret
	 *
	 * @return array<string, mixed>
	 */
	public function getAdminConfig(): array {
		$config = [
			'api_key_set' => $this->hasApiKey(),
			'model_metadata' => $this->getModelMetadata(),
			self::KEY_TTS_VOICE => $this->getTtsVoice(),
			self::KEY_MAX_TOKENS => $this->getMaxTokens(),
			self::KEY_REQUEST_TIMEOUT => $this->getRequestTimeout(),
			self::KEY_CHUNK_SIZE => $this->getChunkSize(),
			self::KEY_DATA_COLLECTION_DENY => $this->isDataCollectionDenied(),
			self::KEY_ZDR => $this->isZdrOnly(),
			self::KEY_SEND_REFERER => $this->isSendReferer(),
		];
		foreach (self::MODEL_KEYS as $modality => $key) {
			$config[$key] = $this->getModels($modality);
		}
		return $config;
	}

	/**
	 * Applies the non-secret values the admin settings frontend sends
	 *
	 * Unknown keys are ignored, values are validated and clamped.
	 *
	 * @param array<string, mixed> $values
	 * @return bool whether the model selection changed
	 */
	public function setAdminConfig(array $values): bool {
		$selectionChanged = false;
		foreach (self::MODEL_KEYS as $modality => $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}
			$models = is_array($values[$key]) ? self::sanitizeModelList($values[$key]) : [];
			if ($models !== $this->getModels($modality)) {
				$this->setModels($modality, $models);
				$selectionChanged = true;
			}
		}
		if (array_key_exists(self::KEY_TTS_VOICE, $values)) {
			$voice = is_string($values[self::KEY_TTS_VOICE]) ? trim($values[self::KEY_TTS_VOICE]) : '';
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_TTS_VOICE, mb_substr($voice, 0, 200));
		}
		if (array_key_exists(self::KEY_MAX_TOKENS, $values)) {
			$maxTokens = self::toInt($values[self::KEY_MAX_TOKENS], Application::DEFAULT_MAX_TOKENS);
			$this->appConfig->setValueInt(Application::APP_ID, self::KEY_MAX_TOKENS, max(1, min($maxTokens, Application::MAX_MAX_TOKENS)));
		}
		if (array_key_exists(self::KEY_REQUEST_TIMEOUT, $values)) {
			$timeout = self::toInt($values[self::KEY_REQUEST_TIMEOUT], Application::DEFAULT_REQUEST_TIMEOUT);
			$this->appConfig->setValueInt(Application::APP_ID, self::KEY_REQUEST_TIMEOUT, max(Application::MIN_REQUEST_TIMEOUT, min($timeout, Application::MAX_REQUEST_TIMEOUT)));
		}
		if (array_key_exists(self::KEY_CHUNK_SIZE, $values)) {
			$chunkSize = self::toInt($values[self::KEY_CHUNK_SIZE], Application::DEFAULT_CHUNK_SIZE);
			$this->appConfig->setValueInt(Application::APP_ID, self::KEY_CHUNK_SIZE, $chunkSize <= 0 ? 0 : max(Application::MIN_CHUNK_SIZE, $chunkSize));
		}
		foreach ([self::KEY_DATA_COLLECTION_DENY, self::KEY_ZDR, self::KEY_SEND_REFERER] as $boolKey) {
			if (array_key_exists($boolKey, $values)) {
				$this->appConfig->setValueBool(Application::APP_ID, $boolKey, filter_var($values[$boolKey], FILTER_VALIDATE_BOOLEAN));
			}
		}
		return $selectionChanged;
	}

	/**
	 * @param array<mixed> $models
	 * @return list<string>
	 */
	public static function sanitizeModelList(array $models): array {
		$sanitized = [];
		foreach ($models as $model) {
			if (!is_string($model)) {
				continue;
			}
			$model = trim($model);
			if ($model === '' || mb_strlen($model) > 200 || in_array($model, $sanitized, true)) {
				continue;
			}
			$sanitized[] = $model;
			if (count($sanitized) >= Application::MAX_SELECTED_MODELS) {
				break;
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return ModelMetadata
	 */
	private static function sanitizeMetadataEntry(array $entry, string $modelId): array {
		$name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
		return [
			'name' => $name !== '' ? mb_substr($name, 0, 200) : $modelId,
			'input_modalities' => self::sanitizeStringList($entry['input_modalities'] ?? ['text']),
			'output_modalities' => self::sanitizeStringList($entry['output_modalities'] ?? []),
			'supported_voices' => self::sanitizeStringList($entry['supported_voices'] ?? []),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function sanitizeStringList(mixed $values): array {
		if (!is_array($values)) {
			return [];
		}
		$list = [];
		foreach ($values as $value) {
			if (is_string($value) && $value !== '' && !in_array($value, $list, true)) {
				$list[] = $value;
			}
		}
		return $list;
	}

	private static function toInt(mixed $value, int $default): int {
		if (is_int($value)) {
			return $value;
		}
		if (is_float($value)) {
			return (int)$value;
		}
		if (is_string($value) && is_numeric($value)) {
			return (int)$value;
		}
		return $default;
	}
}
