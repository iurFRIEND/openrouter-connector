<!--
  - SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Rate limits and unavailable providers that OpenRouter reports with a 200 status and an `error` object in the body
  (for example a model that is temporarily rate-limited upstream) are now retried like HTTP 429/503 responses instead
  of failing the task immediately.

## 0.1.0 - 2026-09-17

### Added

- Task processing providers for every model selected in the admin settings: free prompt, chat, chat with tools,
  summary, headline, topics, context write, reformulate, proofread, change tone, formalization, simplification,
  reformat paragraphs, translate and emoji for text models; image analysis and OCR for text models with image input;
  image generation, transcription and speech generation through the dedicated OpenRouter APIs.
- Admin settings with encrypted API key storage, connection check, searchable model catalog per modality,
  privacy routing options (`data_collection: deny`, zero data retention, optional referer) and advanced options
  (maximum output tokens, request timeout, chunk size, default voice).
