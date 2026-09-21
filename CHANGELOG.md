<!--
  - SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## 0.2.0 - 2026-09-21

### Added

- Choice between OpenRouter's standard endpoint (`openrouter.ai`, the default) and its EU endpoint
  (`eu.openrouter.ai`), which keeps prompts and completions inside the European Union (in-region routing).
  The model catalog is loaded and cached per endpoint, and selected models that the current endpoint does not
  offer are flagged in the admin settings.
- The model lists only offer the models the current settings can actually use: those of the selected endpoint,
  those with a zero data retention endpoint while that privacy option is on (`models?zdr=true`), and those the
  configured API key may use according to the privacy settings and guardrails of the account (`models/user`).
  The settings say which of these narrowed a list down, and selected models that they rule out are flagged and
  can be removed in one click. A lookup that fails leaves the lists as they are instead of emptying them.

### Changed

- The model catalog is cached per combination of endpoint, filters and API key instead of per endpoint only, and
  is reloaded when the zero data retention option or the API key changes.

## 0.1.0 - 2026-09-21

### Added

- Task processing providers for every model selected in the admin settings: free prompt, chat, chat with tools,
  summary, headline, topics, context write, reformulate, proofread, change tone, formalization, simplification,
  reformat paragraphs, translate and emoji for text models; image analysis and OCR for text models with image input;
  image generation, transcription and speech generation through the dedicated OpenRouter APIs.
- Admin settings with encrypted API key storage, connection check, searchable model catalog per modality,
  privacy routing options (`data_collection: deny`, zero data retention, optional referer) and advanced options
  (maximum output tokens, request timeout, chunk size, default voice).
- Rate limits and unavailable providers are retried in background workers, honouring the `Retry-After` header.
  This also covers the errors that OpenRouter reports with a 200 status and an `error` object in the body, for
  example a model that is temporarily rate-limited upstream.
