<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRouterConnector\AppInfo;

use OCA\OpenRouterConnector\Listener\TaskProcessingProviderListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Events\GetTaskProcessingProvidersEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'openrouter_connector';

	public const API_BASE_URL = 'https://openrouter.ai/api/v1';
	public const USER_AGENT = 'Nextcloud OpenRouter Connector';

	/**
	 * The OpenRouter endpoints the admin can choose between. The regional
	 * endpoint decrypts and processes prompts and completions inside its
	 * region only ("in-region routing"), which needs a Business or
	 * Enterprise plan and offers only the models onboarded for that region.
	 */
	public const API_ENDPOINT_GLOBAL = 'global';
	public const API_ENDPOINT_EU = 'eu';
	public const API_BASE_URLS = [
		self::API_ENDPOINT_GLOBAL => self::API_BASE_URL,
		self::API_ENDPOINT_EU => 'https://eu.openrouter.ai/api/v1',
	];
	public const DEFAULT_API_ENDPOINT = self::API_ENDPOINT_GLOBAL;
	/** Sent as X-Title so the app shows up by name in the OpenRouter activity view */
	public const X_TITLE = 'Nextcloud OpenRouter Connector';

	/**
	 * The modalities the admin can select models for. Every selected model of
	 * a modality is exposed as one task processing provider per task type of
	 * that modality.
	 */
	public const MODALITY_TEXT = 'text';
	public const MODALITY_IMAGE = 'image';
	public const MODALITY_STT = 'stt';
	public const MODALITY_TTS = 'tts';
	public const MODALITIES = [self::MODALITY_TEXT, self::MODALITY_IMAGE, self::MODALITY_STT, self::MODALITY_TTS];

	public const DEFAULT_MAX_TOKENS = 4096;
	public const MAX_MAX_TOKENS = 1000000;
	public const DEFAULT_REQUEST_TIMEOUT = 240;
	public const MIN_REQUEST_TIMEOUT = 5;
	public const MAX_REQUEST_TIMEOUT = 3600;
	/** In tokens; the text sent per request is roughly three characters per token */
	public const DEFAULT_CHUNK_SIZE = 10000;
	public const MIN_CHUNK_SIZE = 500;
	public const DEFAULT_TTS_VOICE = 'alloy';
	public const MAX_SELECTED_MODELS = 100;
	public const MAX_IMAGES_PER_REQUEST = 10;
	/** Total size of the files attached to one request, in bytes */
	public const MAX_INPUT_FILE_SIZE = 50 * 1000 * 1000;

	/** Initial runtime estimates per modality, in seconds */
	public const DEFAULT_RUNTIMES = [
		self::MODALITY_TEXT => 15,
		self::MODALITY_IMAGE => 45,
		self::MODALITY_STT => 30,
		self::MODALITY_TTS => 20,
	];
	public const RUNTIME_LOWPASS_FACTOR = 0.1;

	public const IMAGE_ASPECT_RATIOS = ['auto', '1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'];
	public const IMAGE_QUALITIES = ['auto', 'low', 'medium', 'high'];

	/** ISO 639-1 codes and endonyms of the languages offered for translation and transcription */
	public const LANGUAGE_CODES_AND_ENDONYMS = [
		['en', 'English'], ['zh', '中文'], ['de', 'Deutsch'], ['es', 'Español'], ['ru', 'Русский'], ['ko', '한국어'],
		['fr', 'Français'], ['ja', '日本語'], ['pt', 'Português'], ['tr', 'Türkçe'], ['pl', 'Polski'], ['ca', 'Català'],
		['nl', 'Nederlands'], ['ar', 'العربية'], ['sv', 'Svenska'], ['it', 'Italiano'], ['id', 'Bahasa Indonesia'],
		['hi', 'हिन्दी'], ['fi', 'Suomi'], ['vi', 'Tiếng Việt'], ['he', 'עברית'], ['uk', 'Українська'], ['el', 'Ελληνικά'],
		['ms', 'Bahasa Melayu'], ['cs', 'Česky'], ['ro', 'Română'], ['da', 'Dansk'], ['hu', 'Magyar'], ['ta', 'தமிழ்'],
		['no', 'Norsk (bokmål)'], ['th', 'ไทย'], ['ur', 'اردو'], ['hr', 'Hrvatski'], ['bg', 'Български'], ['lt', 'Lietuvių'],
		['la', 'Latina'], ['mi', 'Māori'], ['ml', 'മലയാളം'], ['cy', 'Cymraeg'], ['sk', 'Slovenčina'], ['te', 'తెలుగు'],
		['fa', 'فارسی'], ['lv', 'Latviešu'], ['bn', 'বাংলা'], ['sr', 'Српски'], ['az', 'Azərbaycanca'], ['sl', 'Slovenščina'],
		['kn', 'ಕನ್ನಡ'], ['et', 'Eesti'], ['mk', 'Македонски'], ['br', 'Brezhoneg'], ['eu', 'Euskara'], ['is', 'Íslenska'],
		['hy', 'Հայերեն'], ['ne', 'नेपाली'], ['mn', 'Монгол'], ['bs', 'Bosanski'], ['kk', 'Қазақша'], ['sq', 'Shqip'],
		['sw', 'Kiswahili'], ['gl', 'Galego'], ['mr', 'मराठी'], ['pa', 'ਪੰਜਾਬੀ'], ['si', 'සිංහල'], ['km', 'ភាសាខ្មែរ'],
		['sn', 'chiShona'], ['yo', 'Yorùbá'], ['so', 'Soomaaliga'], ['af', 'Afrikaans'], ['oc', 'Occitan'], ['ka', 'ქართული'],
		['be', 'Беларуская'], ['tg', 'Тоҷикӣ'], ['sd', 'سنڌي'], ['gu', 'ગુજરાતી'], ['am', 'አማርኛ'], ['yi', 'ייִדיש'],
		['lo', 'ລາວ'], ['uz', 'Oʻzbek'], ['fo', 'Føroyskt'], ['ht', 'Kreyòl ayisyen'], ['ps', 'پښتو'], ['tk', 'Türkmen'],
		['nn', 'Norsk (nynorsk)'], ['mt', 'Malti'], ['sa', 'संस्कृतम्'], ['lb', 'Lëtzebuergesch'], ['my', 'မြန်မာစာ'],
		['bo', 'བོད་ཡིག'], ['tl', 'Tagalog'], ['mg', 'Malagasy'], ['as', 'অসমীয়া'], ['tt', 'Татарча'], ['haw', 'ʻŌlelo Hawaiʻi'],
		['ln', 'Lingála'], ['ha', 'Hausa'], ['ba', 'Башҡортса'], ['jw', 'Basa Jawa'], ['su', 'Basa Sunda'], ['yue', '粵語'],
	];

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// The providers of this app depend on the models the admin selected,
		// so they cannot be registered as classes. They are built per
		// (model, task type) by the ProviderFactory and handed to the server
		// through this event listener instead.
		$context->registerEventListener(GetTaskProcessingProvidersEvent::class, TaskProcessingProviderListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
