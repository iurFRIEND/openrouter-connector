<!--
  - SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# OpenRouter Connector for Nextcloud

[![REUSE status](https://api.reuse.software/badge/github.com/iurFRIEND/openrouter-connector)](https://api.reuse.software/info/github.com/iurFRIEND/openrouter-connector)

Connect the Nextcloud Assistant to hundreds of AI models through [OpenRouter](https://openrouter.ai), a unified API
for large language, image, speech-to-text and text-to-speech models from many providers, with a single API key.

The app registers [task processing providers](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/task_processing.html)
for the models an administrator selects. Every selected model becomes its own set of providers, named after the model,
so different models can be assigned to different tasks in the **Artificial Intelligence** admin settings.

## Features

| Modality of the selected model | Assistant features |
| --- | --- |
| Text | Free prompt, chat, chat with tools (agent), summarize, headline, topics, context write, reformulate, proofread, change tone, formalization, simplification, reformat paragraphs, translate, emoji |
| Text with image input | Additionally: analyze images, optical character recognition (OCR) of images and PDF files |
| Image generation | Generate images (dedicated OpenRouter image API) |
| Speech-to-text | Transcribe audio files (dedicated OpenRouter transcription API) |
| Text-to-speech | Generate speech (dedicated OpenRouter speech API) |

Further features:

* Model catalog with search in the admin settings, narrowed down to the models the current settings can actually
  reach; unknown model IDs can be typed in
* Privacy options: restrict routing to providers that do not store or train on prompts (`data_collection: deny`)
  and to zero data retention endpoints
* Connection check showing the label, usage and limit of the configured key
* Long texts are split into chunks and processed chunk by chunk
* Rate-limit aware: `429`/`503` responses are retried in background workers according to `Retry-After`

## Requirements

* Nextcloud 34
* PHP 8.2 to 8.5
* The [Assistant app](https://apps.nextcloud.com/apps/assistant) to use the features from the user interface
* An [OpenRouter](https://openrouter.ai) account with an API key and credits (a few models are free)

## Installation

### From the App Store

Install **OpenRouter Connector** from the Nextcloud App Store (category *Integration* / *AI*).

### From source

```bash
cd /path/to/nextcloud/custom_apps
git clone https://github.com/iurFRIEND/openrouter-connector.git openrouter_connector
cd openrouter_connector
make build            # npm ci && npm run build
sudo -u www-data php ../../occ app:enable openrouter_connector
```

The app folder must be named `openrouter_connector`, because Nextcloud requires it to match the app ID.

### A note on the two spellings

| Where | Name |
| --- | --- |
| Repository, website, package names | `openrouter-connector` |
| App ID, app folder, app config keys, provider IDs, translation domain | `openrouter_connector` |

The App Store restricts app IDs to lowercase letters, digits and underscores, so the ID cannot carry the hyphen of
the repository name. The ID is also fixed by the issued code signing certificate and cannot be changed afterwards.

## Configuration

1. Open **Administration settings → Artificial Intelligence** and find the **OpenRouter Connector** section.
2. Choose the **API endpoint**. The standard endpoint is used by default; see [API endpoint](#api-endpoint) below.
3. Paste your OpenRouter API key and save it. The key is stored encrypted in the app configuration.
   Use **Check connection** to verify it.
4. Select the models you want to expose, per modality (text, image generation, speech-to-text, text-to-speech).
   The lists are loaded from the OpenRouter catalog and only offer what the current settings can reach; see
   [Which models are offered](#which-models-are-offered) below. Model IDs that are not listed can be typed in.
5. In the **Artificial Intelligence** section above, pick the OpenRouter provider you want for each task type.
   The providers are named after the model, for example *OpenAI: GPT-5 Mini (OpenRouter)*.

### API endpoint

| Option | Base URL |
| --- | --- |
| Standard endpoint (default) | `https://openrouter.ai/api/v1` |
| EU endpoint | `https://eu.openrouter.ai/api/v1` |

The EU endpoint uses OpenRouter's [in-region routing](https://openrouter.ai/docs/guides/features/in-region-routing):
the request is decrypted inside the European Union and is only routed to provider endpoints in that region, so prompts
and completions never leave it. A few things to keep in mind:

* In-region routing requires an OpenRouter **Business or Enterprise** plan.
* Only the models OpenRouter has onboarded for the EU are available, which are far fewer than on the standard
  endpoint — at the time of writing 57 text models instead of 446, one speech-to-text and one text-to-speech model,
  and no image generation models at all. Tasks using a model the region does not carry fail rather than being
  routed out of the region.

Switching the endpoint saves immediately, reloads the model catalogs and looks up the details of the selected models
again.

### Which models are offered

The model lists only contain the models the current settings can actually use, so that a model cannot be selected
that every request would fail on:

| Setting | How the lists are narrowed down |
| --- | --- |
| EU endpoint | The lists come from `eu.openrouter.ai`, which only answers with the models onboarded for the region |
| Only use zero data retention endpoints | `models?zdr=true`, which leaves the models that have at least one such endpoint (318 of 446 text models at the time of writing) |
| API key | `models/user`, the catalog as OpenRouter narrows it down for the privacy settings of the account and the [guardrails](https://openrouter.ai/docs/guides/features/guardrails) of the key |

A few details worth knowing:

* The dedicated `images/models` list of the OpenRouter API carries neither the region nor the filters, so the app
  intersects it with the general list itself when either applies.
* `data_collection: deny` has no counterpart in the model API, so the lists are not narrowed down by it. A model
  whose providers all store prompts fails the request instead.
* A lookup that fails — an unreachable API, a key that may not read `models/user` — leaves the lists as they are
  rather than emptying them, and the settings then do not claim that a model is unavailable.
* Selected models that the current settings rule out are flagged with a warning and can be removed in one click.
  They are kept otherwise, because a model ID typed in by hand is a deliberate choice.
* The lists are cached for an hour per combination of endpoint, filters and key. **Reload model list** fetches
  them again; changing the endpoint, the zero data retention option or the key does so by itself.

### Privacy options

OpenRouter routes each request to one of the providers hosting a model. In the **Privacy** section you can:

* only use providers that do not store or train on prompts (`data_collection: deny`),
* only use zero data retention endpoints (`zdr`), which also narrows the model lists down to the models that have
  such an endpoint,
* opt in to sending the address of your instance as `HTTP-Referer` for OpenRouter's usage attribution
  (off by default; the app always identifies itself with the `X-Title` header).

These routing preferences are sent with text tasks (chat completions); the dedicated image, transcription and
speech APIs do not accept them, so for those modalities only what is configured in the OpenRouter account applies.
Please review the
[OpenRouter privacy documentation](https://openrouter.ai/docs/guides/privacy/provider-logging) and the terms of
the model providers you select.

### Advanced options

* **Maximum output tokens**: default and upper limit of the tokens a text model may generate per request.
* **Request timeout**: in seconds.
* **Chunk size**: long texts are split into chunks of this many tokens (roughly three characters per token). `0` disables chunking.
* **Default text-to-speech voice**: used when a task does not specify a voice. The voices of the selected
  text-to-speech models are offered where the catalog knows them.

### Task pickup speed

Task processing providers run in background jobs. To avoid delays, set up dedicated workers as described in the
[Nextcloud AI documentation](https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html#improve-ai-task-pickup-speed):

```bash
sudo -u www-data php occ background-job:worker 'OC\TaskProcessing\SynchronousBackgroundJob'
```

## How it works

* `lib/TaskProcessing/ProviderFactory.php` builds one provider per (selected model, task type) from the app
  configuration alone; `TaskProcessingProviderListener` hands them to the server through the
  `GetTaskProcessingProvidersEvent`.
* Provider IDs are derived from the model ID and the task type (`openrouter_connector-<model>-<task type>`), so the
  assignments in the AI settings survive restarts and updates.
* `lib/Service/OpenRouterApiService.php` talks to the OpenRouter API (`chat/completions`, `images`,
  `audio/transcriptions`, `audio/speech`, `models`, `models/user`, `key`) using Nextcloud's HTTP client.
* `lib/Service/ModelCatalogService.php` loads and caches the model catalog per endpoint, filter and key, and stores
  the names and capabilities of the selected models, so no network request is needed to build the providers.

## Development

```bash
composer install       # PHP dev tools (php-cs-fixer, psalm, phpunit) and the OCP stubs
npm ci                 # frontend dependencies
npm run dev            # build the frontend once (npm run watch to rebuild on changes)
make lint              # php -l, php-cs-fixer, psalm, eslint, stylelint
make test              # PHPUnit unit tests
```

The frontend is built with Vite (`@nextcloud/vite-config`) into `js/`, which is not committed.

### Releasing to the App Store

1. Bump `<version>` in `appinfo/info.xml` and `package.json`, add the release to `CHANGELOG.md`.
2. Create a git tag `vX.Y.Z` and a GitHub release. The *Build and publish app release* workflow builds the frontend,
   packages the app with [krankerl](https://github.com/ChristophWurst/krankerl), signs it with the app store
   certificate (secret `APP_PRIVATE_KEY`; the certificate is fetched from
   [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)), attaches the archive
   to the release and uploads it to the App Store (secret `APPSTORE_TOKEN`).
3. Alternatively build and sign locally with `make appstore` and upload the archive URL and signature at
   <https://apps.nextcloud.com/developer/apps/releases/new>.

Before the first release the app has to be registered: generate a key and certificate request

```bash
mkdir -p ~/.nextcloud/certificates && cd ~/.nextcloud/certificates
openssl req -nodes -newkey rsa:4096 -keyout openrouter_connector.key -out openrouter_connector.csr -subj "/CN=openrouter_connector"
```

open a pull request adding `openrouter_connector/openrouter_connector.csr` to
[nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests), store the issued
certificate as `openrouter_connector.crt` and register the app at <https://apps.nextcloud.com/developer/apps/new>
with the certificate and the signature of the app ID:

```bash
echo -n "openrouter_connector" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/openrouter_connector.key | openssl base64
```

## License

[AGPL-3.0-or-later](LICENSES/AGPL-3.0-or-later.txt). This project follows the [REUSE](https://reuse.software) specification.

OpenRouter is a trademark of its owner; this app is an independent integration and not affiliated with OpenRouter, Inc.
