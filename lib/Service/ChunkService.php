<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Service;

/**
 * Splits long texts into chunks a model can process in one request
 */
class ChunkService {
	/**
	 * Rough approximation of the number of characters per token. It is lower
	 * than the four characters usually assumed for English to leave room for
	 * multibyte characters and other languages.
	 */
	private const CHARS_PER_TOKEN = 3;

	public function __construct(
		private SettingsService $settings,
	) {
	}

	/**
	 * @param bool $outputChunking whether the output is about as long as the input (for example a translation), so that the output token limit matters too
	 * @param int|null $maxTokens the output token limit of the task, if the user set one
	 * @return non-empty-list<string>
	 */
	public function split(string $text, bool $outputChunking = false, ?int $maxTokens = null): array {
		$chunkSize = $this->settings->getChunkSize();
		if ($outputChunking) {
			$maxTokens = $maxTokens !== null && $maxTokens > 0 ? $maxTokens : $this->settings->getMaxTokens();
			$chunkSize = $chunkSize > 0 ? min($chunkSize, $maxTokens) : $maxTokens;
		}
		if ($chunkSize <= 0) {
			return [$text];
		}
		return self::splitByCharacters($text, $chunkSize * self::CHARS_PER_TOKEN);
	}

	/**
	 * Splits at paragraph, sentence or word boundaries where possible, never
	 * losing any part of the text
	 *
	 * @return non-empty-list<string>
	 */
	public static function splitByCharacters(string $text, int $maxChars): array {
		$maxChars = max(1, $maxChars);
		$chunks = [];
		$remaining = $text;
		while (mb_strlen($remaining) > $maxChars) {
			$window = mb_substr($remaining, 0, $maxChars);
			$cut = self::findCut($window);
			$chunks[] = mb_substr($remaining, 0, $cut);
			$remaining = mb_substr($remaining, $cut);
		}
		$chunks[] = $remaining;
		return $chunks;
	}

	/**
	 * The length of the prefix of the window to cut off, preferring paragraph,
	 * then sentence, then word boundaries in the second half of the window
	 */
	private static function findCut(string $window): int {
		$length = mb_strlen($window);
		$minimum = intdiv($length, 2);
		foreach (['/\n\s*\n/u', '/\n/u', '/[.!?]\s/u', '/\s/u'] as $pattern) {
			if (preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE) === false) {
				continue;
			}
			$best = null;
			foreach ($matches[0] as [$match, $byteOffset]) {
				$end = mb_strlen(substr($window, 0, $byteOffset)) + mb_strlen($match);
				if ($end >= $minimum && $end < $length) {
					$best = $end;
				}
			}
			if ($best !== null) {
				return $best;
			}
		}
		return $length;
	}
}
