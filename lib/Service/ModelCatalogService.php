<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Service;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Exception\OpenRouterApiException;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * The OpenRouter model catalog, per modality, cached
 *
 * Only the models that the current settings can actually reach are listed:
 * a regional endpoint offers its region, the zero data retention option
 * narrows the lists down to the models that have such an endpoint, and the
 * API key adds whatever its account settings and guardrails allow.
 *
 * @psalm-type CatalogEntry = array{id: string, name: string, input_modalities: list<string>, output_modalities: list<string>, context_length: int|null, pricing: array{prompt: string|null, completion: string|null}, supported_voices: list<string>, free: bool}
 * @psalm-type Catalog = array{models: list<CatalogEntry>, filters: list<string>}
 */
class ModelCatalogService {
	private const CACHE_TTL = 3600;

	/** What narrowed a catalog down, reported to the settings so it can say why a model is missing */
	public const FILTER_ENDPOINT = 'endpoint';
	public const FILTER_ZDR = 'zdr';
	public const FILTER_KEY = 'key';

	/** @var array<string, true>|null the models the key may use, looked up once per request */
	private ?array $keyScopedModelIds = null;
	private bool $keyScopedModelIdsLoaded = false;

	public function __construct(
		private OpenRouterApiService $api,
		private SettingsService $settings,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The models available for a modality
	 *
	 * @return list<CatalogEntry>
	 * @throws OpenRouterApiException
	 */
	public function getModels(string $modality, bool $refresh = false): array {
		return $this->getCatalog($modality, $refresh)['models'];
	}

	/**
	 * The models available for a modality, with the filters that were applied
	 *
	 * @return Catalog
	 * @throws OpenRouterApiException
	 */
	public function getCatalog(string $modality, bool $refresh = false): array {
		if (!in_array($modality, Application::MODALITIES, true)) {
			throw new \InvalidArgumentException('Unknown modality ' . $modality);
		}
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-catalog');
		// the endpoint, the routing options and the key each change what is
		// offered, so every combination of them is cached on its own
		$cacheKey = 'models-' . $modality . '-' . $this->cacheSignature();
		if (!$refresh) {
			$cached = $cache->get($cacheKey);
			if (is_array($cached) && isset($cached['models'], $cached['filters'])) {
				/** @var Catalog $cached */
				return $cached;
			}
		}
		$models = match ($modality) {
			Application::MODALITY_TEXT => $this->fetchTextModels(),
			Application::MODALITY_IMAGE => $this->fetchImageModels(),
			Application::MODALITY_STT => $this->fetchByOutputModality('transcription'),
			Application::MODALITY_TTS => $this->fetchByOutputModality('speech'),
		};
		$filters = [];
		if ($this->settings->getApiEndpoint() !== Application::API_ENDPOINT_GLOBAL) {
			$filters[] = self::FILTER_ENDPOINT;
		}
		if ($this->settings->isZdrOnly()) {
			$filters[] = self::FILTER_ZDR;
		}
		$allowed = $this->keyScopedModelIds($refresh);
		if ($allowed !== null) {
			$models = array_values(array_filter($models, static fn (array $entry): bool => isset($allowed[$entry['id']])));
			$filters[] = self::FILTER_KEY;
		}
		usort($models, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		$catalog = ['models' => $models, 'filters' => $filters];
		$cache->set($cacheKey, $catalog, self::CACHE_TTL);
		return $catalog;
	}

	/**
	 * Tells the catalogs of one set of settings from another. The key is part
	 * of it because its guardrails decide what it may use, but only as a
	 * digest, so the secret itself is not spread over the cache keys.
	 */
	private function cacheSignature(): string {
		$parts = [$this->settings->getApiEndpoint()];
		if ($this->settings->isZdrOnly()) {
			$parts[] = 'zdr';
		}
		$apiKey = $this->settings->getApiKey();
		if ($apiKey !== '') {
			$parts[] = 'key' . substr(hash('sha256', $apiKey), 0, 12);
		}
		return implode('-', $parts);
	}

	/**
	 * The IDs of the models the configured key may use, or null when they
	 * could not be determined, in which case the catalog stays as the
	 * endpoint returned it. Requests without a key, keys that cannot read the
	 * list and empty answers all end up as null: leaving a model in the list
	 * that a guardrail happens to block is much less disruptive than emptying
	 * every list over a failed request.
	 *
	 * @return array<string, true>|null
	 */
	private function keyScopedModelIds(bool $refresh): ?array {
		if (!$this->settings->hasApiKey()) {
			return null;
		}
		if ($this->keyScopedModelIdsLoaded && !$refresh) {
			return $this->keyScopedModelIds;
		}
		$this->keyScopedModelIdsLoaded = true;
		$this->keyScopedModelIds = null;
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-catalog');
		$cacheKey = 'key-models-' . $this->cacheSignature();
		$ids = $refresh ? null : $cache->get($cacheKey);
		if (!is_array($ids)) {
			try {
				$ids = [];
				// the default of this list is text only, so every modality is asked for
				foreach ($this->api->listUserModels(['output_modalities' => 'all']) as $raw) {
					if (is_string($raw['id'] ?? null) && $raw['id'] !== '') {
						$ids[] = $raw['id'];
					}
				}
			} catch (\Throwable $e) {
				$this->logger->info('Could not load the models the OpenRouter key may use: ' . $e->getMessage());
				return null;
			}
			$cache->set($cacheKey, $ids, self::CACHE_TTL);
		}
		if ($ids === []) {
			$this->logger->warning('OpenRouter reported no model at all for the configured key, so the model lists are not narrowed down by it');
			return null;
		}
		/** @var list<string> $ids */
		$this->keyScopedModelIds = array_fill_keys($ids, true);
		return $this->keyScopedModelIds;
	}

	/**
	 * Adds the filters OpenRouter can apply to a model list itself
	 *
	 * @param array<string, string> $query
	 * @return array<string, string>
	 */
	private function listQuery(array $query = []): array {
		if ($this->settings->isZdrOnly()) {
			// leaves only the models that have at least one zero data
			// retention endpoint; the others cannot answer such a request
			$query['zdr'] = 'true';
		}
		return $query;
	}

	/**
	 * Looks up the display names, modalities and voices of the selected
	 * models and stores them, so that providers can be built without any
	 * network request
	 */
	public function refreshSelectedModelMetadata(): void {
		$metadata = $this->settings->getModelMetadata();
		$known = [];
		foreach (Application::MODALITIES as $modality) {
			$selected = $this->settings->getModels($modality);
			if ($selected === []) {
				continue;
			}
			$catalog = [];
			try {
				foreach ($this->getModels($modality) as $entry) {
					$catalog[$entry['id']] = $entry;
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Could not load the OpenRouter ' . $modality . ' model catalog: ' . $e->getMessage(), ['exception' => $e]);
			}
			foreach ($selected as $modelId) {
				$known[$modelId] = true;
				$entry = $catalog[$modelId] ?? $this->lookupModel($modelId);
				if ($entry !== null) {
					$metadata[$modelId] = [
						'name' => $entry['name'],
						'input_modalities' => $entry['input_modalities'],
						'output_modalities' => $entry['output_modalities'],
						'supported_voices' => $entry['supported_voices'],
					];
				} elseif (!isset($metadata[$modelId])) {
					$metadata[$modelId] = [
						'name' => $modelId,
						'input_modalities' => ['text'],
						'output_modalities' => [],
						'supported_voices' => [],
					];
				}
			}
		}
		// forget models that are not selected anymore
		$metadata = array_filter($metadata, static fn (string $modelId): bool => isset($known[$modelId]), ARRAY_FILTER_USE_KEY);
		$this->settings->setModelMetadata($metadata);
	}

	/**
	 * @return CatalogEntry|null
	 */
	private function lookupModel(string $modelId): ?array {
		try {
			$raw = $this->api->getModel($modelId);
		} catch (\Throwable $e) {
			$this->logger->info('Could not look up OpenRouter model ' . $modelId . ': ' . $e->getMessage());
			return null;
		}
		return $raw === [] ? null : self::normalize($raw);
	}

	/**
	 * @return list<CatalogEntry>
	 * @throws OpenRouterApiException
	 */
	private function fetchTextModels(): array {
		$models = [];
		foreach ($this->api->listModels($this->listQuery()) as $raw) {
			$entry = self::normalize($raw);
			if ($entry === null) {
				continue;
			}
			if (!in_array('text', $entry['output_modalities'], true) || !in_array('text', $entry['input_modalities'], true)) {
				continue;
			}
			$models[] = $entry;
		}
		return $models;
	}

	/**
	 * @return list<CatalogEntry>
	 * @throws OpenRouterApiException
	 */
	private function fetchImageModels(): array {
		$models = [];
		foreach ($this->api->listImageModels() as $raw) {
			$entry = self::normalize($raw);
			if ($entry !== null) {
				$models[] = $entry;
			}
		}
		if ($this->settings->getApiEndpoint() === Application::API_ENDPOINT_GLOBAL && !$this->settings->isZdrOnly()) {
			return $models;
		}
		// Unlike "models", the dedicated "images/models" list carries neither
		// the region of the endpoint nor the routing filters, so the two are
		// intersected here. A regional endpoint fails a request rather than
		// routing it out of its region, and a model without a zero data
		// retention endpoint fails such a request as well, so the models the
		// general list leaves out would only end up as providers that cannot
		// answer.
		$available = [];
		foreach ($this->api->listModels($this->listQuery(['output_modalities' => 'image'])) as $raw) {
			if (is_string($raw['id'] ?? null)) {
				$available[$raw['id']] = true;
			}
		}
		return array_values(array_filter($models, static fn (array $entry): bool => isset($available[$entry['id']])));
	}

	/**
	 * @return list<CatalogEntry>
	 * @throws OpenRouterApiException
	 */
	private function fetchByOutputModality(string $outputModality): array {
		$models = [];
		foreach ($this->api->listModels($this->listQuery(['output_modalities' => $outputModality])) as $raw) {
			$entry = self::normalize($raw);
			if ($entry !== null && in_array($outputModality, $entry['output_modalities'], true)) {
				$models[] = $entry;
			}
		}
		return $models;
	}

	/**
	 * Keeps only what the settings need from a model of the API
	 *
	 * @param array<string, mixed> $raw
	 * @return CatalogEntry|null
	 */
	public static function normalize(array $raw): ?array {
		$id = $raw['id'] ?? null;
		if (!is_string($id) || $id === '') {
			return null;
		}
		$architecture = is_array($raw['architecture'] ?? null) ? $raw['architecture'] : [];
		$pricing = is_array($raw['pricing'] ?? null) ? $raw['pricing'] : [];
		$name = is_string($raw['name'] ?? null) && $raw['name'] !== '' ? $raw['name'] : $id;
		$contextLength = $raw['context_length'] ?? null;
		return [
			'id' => $id,
			'name' => $name,
			'input_modalities' => self::stringList($architecture['input_modalities'] ?? ['text']),
			'output_modalities' => self::stringList($architecture['output_modalities'] ?? []),
			'context_length' => is_int($contextLength) ? $contextLength : null,
			'pricing' => [
				'prompt' => is_scalar($pricing['prompt'] ?? null) ? (string)$pricing['prompt'] : null,
				'completion' => is_scalar($pricing['completion'] ?? null) ? (string)$pricing['completion'] : null,
			],
			'supported_voices' => self::stringList($raw['supported_voices'] ?? []),
			'free' => str_ends_with($id, ':free'),
		];
	}

	/**
	 * @return list<string>
	 */
	private static function stringList(mixed $values): array {
		if (!is_array($values)) {
			return [];
		}
		$list = [];
		foreach ($values as $value) {
			if (is_string($value) && $value !== '') {
				$list[] = $value;
			}
		}
		return $list;
	}
}
