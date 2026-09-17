<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit\Service;

use OCA\OpenRouterConnector\Service\ChunkService;
use OCA\OpenRouterConnector\Tests\Unit\TestCase;

class ChunkServiceTest extends TestCase {
	public function testShortTextIsOneChunk(): void {
		$store = ['chunk_size' => 1000];
		$service = new ChunkService($this->createSettings($store));
		$this->assertSame(['short text'], $service->split('short text'));
	}

	public function testChunkingCanBeDisabled(): void {
		$store = ['chunk_size' => 0];
		$service = new ChunkService($this->createSettings($store));
		$text = str_repeat('word ', 10000);
		$this->assertSame([$text], $service->split($text));
	}

	public function testNothingIsLostAndBoundariesArePreferred(): void {
		$paragraphs = [];
		for ($i = 0; $i < 40; $i++) {
			$paragraphs[] = 'Paragraph ' . $i . '. ' . str_repeat('Sentence number ' . $i . '. ', 5);
		}
		$text = implode("\n\n", $paragraphs);
		$chunks = ChunkService::splitByCharacters($text, 500);
		$this->assertGreaterThan(1, count($chunks));
		$this->assertSame($text, implode('', $chunks));
		foreach ($chunks as $chunk) {
			$this->assertLessThanOrEqual(500, mb_strlen($chunk));
		}
		// every chunk but the last ends at a paragraph break
		foreach (array_slice($chunks, 0, -1) as $chunk) {
			$this->assertStringEndsWith("\n\n", $chunk);
		}
	}

	public function testMultibyteTextIsSplitByCharacters(): void {
		$text = str_repeat('日本語のテキスト ', 100);
		$chunks = ChunkService::splitByCharacters($text, 50);
		$this->assertSame($text, implode('', $chunks));
		foreach ($chunks as $chunk) {
			$this->assertLessThanOrEqual(50, mb_strlen($chunk));
		}
	}

	public function testOutputChunkingUsesTheTokenLimit(): void {
		$store = ['chunk_size' => 10000, 'max_tokens' => 100];
		$service = new ChunkService($this->createSettings($store));
		$text = str_repeat('word ', 200); // 1000 characters
		$chunks = $service->split($text, true);
		// 100 tokens * 3 characters per chunk
		$this->assertGreaterThanOrEqual(4, count($chunks));
		$this->assertSame($text, implode('', $chunks));
	}
}
