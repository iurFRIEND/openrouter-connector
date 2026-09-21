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
 * @psalm-type CatalogEntry = array{id: string, name: string, input_modalities: list<string>, output_modalities: list<string>, context_length: int|null, pricing: array{prompt: string|null, completion: string|null}, supported_voices: list<string>, free: bool}
 */
class ModelCatalogService {
	private const CACHE_TTL = 3600;

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
		if (!in_array($modality, Application::MODALITIES, true)) {
			throw new \InvalidArgumentException('Unknown modality ' . $modality);
		}
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '-catalog');
		// each endpoint offers its own set of models, so they are cached apart
		$cacheKey = 'models-' . $modality . '-' . $this->settings->getApiEndpoint();
		if (!$refresh) {
			$cached = $cache->get($cacheKey);
			if (is_array($cached)) {
				/** @var list<CatalogEntry> $cached */
				return $cached;
			}
		}
		$models = match ($modality) {
			Application::MODALITY_TEXT => $this->fetchTextModels(),
			Application::MODALITY_IMAGE => $this->fetchImageModels(),
			Application::MODALITY_STT => $this->fetchByOutputModality('transcription'),
			Application::MODALITY_TTS => $this->fetchByOutputModality('speech'),
		};
		usort($models, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		$cache->set($cacheKey, $models, self::CACHE_TTL);
		return $models;
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
		foreach ($this->api->listModels() as $raw) {
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
		if ($this->settings->getApiEndpoint() === Application::API_ENDPOINT_GLOBAL) {
			return $models;
		}
		// Unlike "models", the dedicated "images/models" list is not narrowed
		// down to what a regional endpoint has onboarded, so the two are
		// intersected here. A regional endpoint fails a request rather than
		// routing it out of its region, so the models it does not carry would
		// only end up as providers that cannot answer.
		$inRegion = [];
		foreach ($this->api->listModels(['output_modalities' => 'image']) as $raw) {
			if (is_string($raw['id'] ?? null)) {
				$inRegion[$raw['id']] = true;
			}
		}
		return array_values(array_filter($models, static fn (array $entry): bool => isset($inRegion[$entry['id']])));
	}

	/**
	 * @return list<CatalogEntry>
	 * @throws OpenRouterApiException
	 */
	private function fetchByOutputModality(string $outputModality): array {
		$models = [];
		foreach ($this->api->listModels(['output_modalities' => $outputModality]) as $raw) {
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
