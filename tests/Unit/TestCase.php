<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\Tests\Unit;

use OCA\OpenRouterConnector\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
	/**
	 * An IL10N that returns the English text with its parameters filled in
	 */
	protected function createL10n(): IL10N&MockObject {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static function (string $text, $parameters = []): string {
			$parameters = is_array($parameters) ? $parameters : [$parameters];
			return $parameters === [] ? $text : vsprintf($text, $parameters);
		});
		return $l;
	}

	/**
	 * An IAppConfig backed by an array, so that the SettingsService can be tested for real
	 *
	 * @param array<string, mixed> $store the initial values, by key
	 */
	protected function createAppConfig(array &$store): IAppConfig&MockObject {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$store): string {
			return isset($store[$key]) && is_string($store[$key]) ? $store[$key] : $default;
		});
		$appConfig->method('getValueInt')->willReturnCallback(static function (string $app, string $key, int $default = 0) use (&$store): int {
			return isset($store[$key]) && is_int($store[$key]) ? $store[$key] : $default;
		});
		$appConfig->method('getValueBool')->willReturnCallback(static function (string $app, string $key, bool $default = false) use (&$store): bool {
			return isset($store[$key]) && is_bool($store[$key]) ? $store[$key] : $default;
		});
		$appConfig->method('getValueArray')->willReturnCallback(static function (string $app, string $key, array $default = []) use (&$store): array {
			return isset($store[$key]) && is_array($store[$key]) ? $store[$key] : $default;
		});
		$appConfig->method('setValueString')->willReturnCallback(static function (string $app, string $key, string $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$appConfig->method('setValueInt')->willReturnCallback(static function (string $app, string $key, int $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$appConfig->method('setValueBool')->willReturnCallback(static function (string $app, string $key, bool $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$appConfig->method('setValueArray')->willReturnCallback(static function (string $app, string $key, array $value) use (&$store): bool {
			$store[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$store): void {
			unset($store[$key]);
		});
		return $appConfig;
	}

	/**
	 * @param array<string, mixed> $store
	 */
	protected function createSettings(array &$store): SettingsService {
		return new SettingsService($this->createAppConfig($store));
	}
}
