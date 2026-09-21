<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\Service;

use OCA\OpenRouterConnector\AppInfo\Application;
use OCA\OpenRouterConnector\Exception\OpenRouterApiException;
use OCA\OpenRouterConnector\Service\ModelCatalogService;
use OCA\OpenRouterConnector\Service\OpenRouterApiService;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

class ModelCatalogServiceTest extends TestCase {
	/** @var array<string, mixed> */
	private array $store;
	/** @var array<string, mixed> the entries the catalog cached, by key */
	private array $cached;
	private OpenRouterApiService&MockObject $api;
	private ModelCatalogService $catalog;

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->cached = [];
		$this->api = $this->createMock(OpenRouterApiService::class);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->cached[$key] = $value;
			return true;
		});
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->catalog = new ModelCatalogService($this->api, $this->createSettings($this->store), $cacheFactory, new NullLogger());
	}

	/**
	 * @param list<string> $ids
	 * @return list<array<string, mixed>>
	 */
	private static function rawModels(array $ids, string $outputModality = 'text'): array {
		return array_map(static fn (string $id): array => [
			'id' => $id,
			'name' => strtoupper($id),
			'architecture' => ['input_modalities' => ['text'], 'output_modalities' => [$outputModality]],
		], $ids);
	}

	public function testEachEndpointIsCachedOnItsOwn(): void {
		$this->api->method('listModels')->willReturnOnConsecutiveCalls(
			self::rawModels(['a/one', 'b/two']),
			self::rawModels(['a/one']),
		);

		$this->assertSame(['a/one', 'b/two'], array_column($this->catalog->getModels(Application::MODALITY_TEXT), 'id'));
		$this->store['api_endpoint'] = Application::API_ENDPOINT_EU;
		$this->assertSame(['a/one'], array_column($this->catalog->getModels(Application::MODALITY_TEXT), 'id'), 'the EU catalog is not served from the global cache');

		// both are cached now, so switching back does not ask the API again
		$this->store['api_endpoint'] = Application::API_ENDPOINT_GLOBAL;
		$this->assertSame(['a/one', 'b/two'], array_column($this->catalog->getModels(Application::MODALITY_TEXT), 'id'));
		$this->assertCount(2, $this->cached);
	}

	public function testZeroDataRetentionIsAskedOfTheApiAndCachedOnItsOwn(): void {
		$this->api->expects($this->exactly(2))->method('listModels')
			->willReturnCallback(fn (array $query): array => $query === ['zdr' => 'true']
				? self::rawModels(['a/one'])
				: self::rawModels(['a/one', 'b/two']));

		$this->assertSame(['a/one', 'b/two'], array_column($this->catalog->getModels(Application::MODALITY_TEXT), 'id'));
		$this->store['zdr'] = true;
		$this->assertSame(['a/one'], array_column($this->catalog->getModels(Application::MODALITY_TEXT), 'id'));
		$this->assertSame([ModelCatalogService::FILTER_ZDR], $this->catalog->getCatalog(Application::MODALITY_TEXT)['filters']);
	}

	public function testZeroDataRetentionNarrowsDownTheImageModelsToo(): void {
		$this->store['zdr'] = true;
		$this->api->method('listImageModels')->willReturn(self::rawModels(['a/draw', 'b/paint'], 'image'));
		$this->api->expects($this->once())->method('listModels')
			->with(['output_modalities' => 'image', 'zdr' => 'true'])
			->willReturn(self::rawModels(['b/paint'], 'image'));

		$this->assertSame(['b/paint'], array_column($this->catalog->getModels(Application::MODALITY_IMAGE), 'id'));
	}

	public function testTheCatalogIsNarrowedDownToWhatTheKeyMayUse(): void {
		$this->store['api_key'] = 'sk-or-v1-test';
		$this->api->method('listModels')->willReturn(self::rawModels(['a/one', 'b/two']));
		$this->api->expects($this->once())->method('listUserModels')
			->with(['output_modalities' => 'all'])
			->willReturn(self::rawModels(['b/two', 'c/three']));

		$catalog = $this->catalog->getCatalog(Application::MODALITY_TEXT);
		$this->assertSame(['b/two'], array_column($catalog['models'], 'id'));
		$this->assertSame([ModelCatalogService::FILTER_KEY], $catalog['filters']);
	}

	public function testAFailedKeyLookupLeavesTheCatalogAlone(): void {
		$this->store['api_key'] = 'sk-or-v1-test';
		$this->api->method('listModels')->willReturn(self::rawModels(['a/one', 'b/two']));
		$this->api->method('listUserModels')->willThrowException(new OpenRouterApiException('nope'));

		$catalog = $this->catalog->getCatalog(Application::MODALITY_TEXT);
		$this->assertSame(['a/one', 'b/two'], array_column($catalog['models'], 'id'));
		$this->assertSame([], $catalog['filters'], 'a filter that could not be applied is not reported');
	}

	public function testAnEmptyKeyCatalogIsTreatedAsUnknown(): void {
		$this->store['api_key'] = 'sk-or-v1-test';
		$this->api->method('listModels')->willReturn(self::rawModels(['a/one']));
		$this->api->method('listUserModels')->willReturn([]);

		$catalog = $this->catalog->getCatalog(Application::MODALITY_TEXT);
		$this->assertSame(['a/one'], array_column($catalog['models'], 'id'));
		$this->assertSame([], $catalog['filters']);
	}

	public function testWithoutAKeyTheListIsNotNarrowedDown(): void {
		$this->api->method('listModels')->willReturn(self::rawModels(['a/one']));
		$this->api->expects($this->never())->method('listUserModels');

		$this->assertSame([], $this->catalog->getCatalog(Application::MODALITY_TEXT)['filters']);
	}

	public function testTheRegionalEndpointIsReportedAsAFilter(): void {
		$this->store['api_endpoint'] = Application::API_ENDPOINT_EU;
		$this->api->method('listModels')->willReturn(self::rawModels(['a/one']));

		$this->assertSame([ModelCatalogService::FILTER_ENDPOINT], $this->catalog->getCatalog(Application::MODALITY_TEXT)['filters']);
	}

	public function testImageModelsAreKeptWholeOnTheGlobalEndpoint(): void {
		$this->api->method('listImageModels')->willReturn(self::rawModels(['a/draw', 'b/paint'], 'image'));
		$this->api->expects($this->never())->method('listModels');

		$this->assertSame(['a/draw', 'b/paint'], array_column($this->catalog->getModels(Application::MODALITY_IMAGE), 'id'));
	}

	public function testImageModelsAreNarrowedDownToTheRegionalCatalog(): void {
		$this->store['api_endpoint'] = Application::API_ENDPOINT_EU;
		// "images/models" answers the same on every endpoint, "models" does not
		$this->api->method('listImageModels')->willReturn(self::rawModels(['a/draw', 'b/paint'], 'image'));
		$this->api->method('listModels')->with(['output_modalities' => 'image'])->willReturn(self::rawModels(['b/paint'], 'image'));

		$this->assertSame(['b/paint'], array_column($this->catalog->getModels(Application::MODALITY_IMAGE), 'id'));
	}
}
