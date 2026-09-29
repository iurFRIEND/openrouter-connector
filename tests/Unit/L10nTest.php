<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The translations in l10n/ are maintained by hand, so this checks what a slip
 * in them would break at runtime. A string that is missing in a language is not
 * a problem, Nextcloud falls back to the English text.
 */
class L10nTest extends TestCase {
	private const L10N_DIR = __DIR__ . '/../../l10n';
	private const PLACEHOLDER = '/%[sdn]|\{[^{}]*\}/';

	/**
	 * @return array<string, array{string}>
	 */
	public static function languages(): array {
		$languages = [];
		foreach (glob(self::L10N_DIR . '/*.json') ?: [] as $file) {
			$language = basename($file, '.json');
			$languages[$language] = [$language];
		}
		return $languages;
	}

	/**
	 * The server reads the .json (PHP), the browser the .js
	 */
	#[DataProvider('languages')]
	public function testJsAndJsonAreTheSame(string $language): void {
		$json = $this->readJson($language);
		$js = (string)file_get_contents(self::L10N_DIR . '/' . $language . '.js');
		$this->assertSame(1, preg_match('/^OC\.L10N\.register\(\s*"openrouter_connector"\s*,\s*(\{.*\})\s*,\s*"(nplurals=[^"]*)"\s*\);\s*$/s', $js, $matches), $language . '.js is not in the format of Nextcloud\'s translation files');
		$this->assertSame($json['translations'], json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR));
		$this->assertSame($json['pluralForm'], $matches[2]);
	}

	#[DataProvider('languages')]
	public function testPlaceholdersAreKept(string $language): void {
		foreach ($this->readJson($language)['translations'] as $source => $translation) {
			if (preg_match('/^_(.*)_::_(.*)_$/s', $source, $forms) === 1) {
				// a plural entry: the key is `_singular_::_plural_`, the first form translates the singular
				foreach ((array)$translation as $index => $form) {
					$this->assertSame($this->placeholders($forms[$index === 0 ? 1 : 2]), $this->placeholders($form), $language . ': ' . $form);
				}
				continue;
			}
			$this->assertIsString($translation, $language . ': ' . $source);
			$this->assertSame($this->placeholders($source), $this->placeholders($translation), $language . ': ' . $translation);
		}
	}

	#[DataProvider('languages')]
	public function testPluralFormsMatchTheHeader(string $language): void {
		$json = $this->readJson($language);
		$this->assertSame(1, preg_match('/^nplurals=(\d+);/', $json['pluralForm'], $matches));
		foreach ($json['translations'] as $source => $translation) {
			$isPluralKey = preg_match('/^_.*_::_.*_$/s', $source) === 1;
			$this->assertSame($isPluralKey, is_array($translation), $language . ': ' . $source);
			if (is_array($translation)) {
				$this->assertCount((int)$matches[1], $translation, $language . ': ' . $source);
			}
		}
	}

	/**
	 * @nextcloud/l10n sends every translated text through DOMPurify, which turns
	 * a no-break space, & and angle brackets into entities that Vue then prints literally
	 */
	#[DataProvider('languages')]
	public function testNoCharactersTheFrontendWouldEscape(string $language): void {
		foreach ($this->readJson($language)['translations'] as $translation) {
			foreach ((array)$translation as $text) {
				$this->assertSame(0, preg_match('/[\x{00A0}&<>]/u', $text), $language . ': ' . $text);
			}
		}
	}

	/**
	 * @return array{translations: array<string, string|list<string>>, pluralForm: string}
	 */
	private function readJson(string $language): array {
		$json = json_decode((string)file_get_contents(self::L10N_DIR . '/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($json);
		$this->assertIsArray($json['translations'] ?? null);
		$this->assertIsString($json['pluralForm'] ?? null);
		return $json;
	}

	/**
	 * @return list<string>
	 */
	private function placeholders(string $text): array {
		preg_match_all(self::PLACEHOLDER, $text, $matches);
		sort($matches[0]);
		return $matches[0];
	}
}
