<!--
  - SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<NcSettingsSection :name="t('openrouter_connector', 'OpenRouter Connector')"
		:description="t('openrouter_connector', 'Connect the Assistant to hundreds of AI models through OpenRouter. Every selected model becomes a provider that can be picked per task in the Artificial Intelligence settings.')"
		doc-url="https://github.com/iurFRIEND/openrouter-connector#readme">
		<NcNoteCard v-if="!state.assistant_enabled" type="warning">
			{{ t('openrouter_connector', 'The Assistant app is not enabled. It is needed to use the features provided by this app.') }}
			<a class="external"
				:href="assistantAppUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openrouter_connector', 'Assistant app') }}
			</a>
		</NcNoteCard>

		<h3>{{ t('openrouter_connector', 'API endpoint') }}</h3>
		<p class="hint">
			{{ t('openrouter_connector', 'Where the requests are sent. The EU endpoint decrypts and processes prompts and completions inside the European Union only (in-region routing). It needs an OpenRouter Business or Enterprise plan and offers only the models OpenRouter has onboarded for the EU, so the model lists below are considerably shorter.') }}
			<a class="external"
				href="https://openrouter.ai/docs/guides/features/in-region-routing"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openrouter_connector', 'OpenRouter in-region routing documentation') }}
			</a>
		</p>
		<NcCheckboxRadioSwitch v-for="endpoint in endpoints"
			:key="endpoint.id"
			:model-value="state.api_endpoint"
			:value="endpoint.id"
			:disabled="reloadingCatalog"
			name="openrouter-api-endpoint"
			type="radio"
			@update:model-value="onEndpointChange">
			{{ endpoint.label }}
		</NcCheckboxRadioSwitch>
		<p class="hint">
			{{ t('openrouter_connector', 'All requests go to {url}', { url: state.api_base_url }) }}
		</p>

		<h3>{{ t('openrouter_connector', 'Authentication') }}</h3>
		<p class="hint">
			{{ t('openrouter_connector', 'Create an API key in your OpenRouter account and paste it here. The key is stored encrypted.') }}
			<a class="external"
				href="https://openrouter.ai/settings/keys"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openrouter_connector', 'OpenRouter API keys') }}
			</a>
		</p>
		<div class="line">
			<NcPasswordField id="openrouter-api-key"
				v-model="apiKey"
				class="input"
				:label="t('openrouter_connector', 'API key')"
				:placeholder="state.api_key_set ? '••••••••••••••••' : 'sk-or-v1-…'"
				:disabled="savingKey"
				@keyup.enter="saveApiKey" />
			<NcButton variant="primary" :disabled="savingKey || apiKey.trim() === ''" @click="saveApiKey">
				<template #icon>
					<NcLoadingIcon v-if="savingKey" :size="20" />
				</template>
				{{ t('openrouter_connector', 'Save API key') }}
			</NcButton>
			<NcButton v-if="state.api_key_set"
				variant="tertiary"
				:disabled="savingKey"
				@click="removeApiKey">
				{{ t('openrouter_connector', 'Remove API key') }}
			</NcButton>
		</div>
		<div class="line">
			<NcButton :disabled="!state.api_key_set || checkingKey" @click="checkKey">
				<template #icon>
					<NcLoadingIcon v-if="checkingKey" :size="20" />
				</template>
				{{ t('openrouter_connector', 'Check connection') }}
			</NcButton>
			<span v-if="!state.api_key_set" class="hint">
				{{ t('openrouter_connector', 'No API key is configured yet.') }}
			</span>
		</div>
		<NcNoteCard v-if="keyInfo" type="success">
			<strong>{{ t('openrouter_connector', 'Connected to OpenRouter.') }}</strong>
			<ul class="key-info">
				<li v-if="keyInfo.label">
					{{ t('openrouter_connector', 'Key label: {label}', { label: keyInfo.label }) }}
				</li>
				<li v-if="keyInfo.usage !== undefined && keyInfo.usage !== null">
					{{ t('openrouter_connector', 'Credits used with this key: ${usage}', { usage: formatCredits(keyInfo.usage) }) }}
				</li>
				<li v-if="keyInfo.limit !== undefined && keyInfo.limit !== null">
					{{ t('openrouter_connector', 'Key limit: ${limit} (remaining: ${remaining})', { limit: formatCredits(keyInfo.limit), remaining: formatCredits(keyInfo.limit_remaining) }) }}
				</li>
				<li v-if="keyInfo.is_free_tier !== undefined">
					{{ keyInfo.is_free_tier ? t('openrouter_connector', 'The account is on the free tier (no credits purchased yet).') : t('openrouter_connector', 'The account has purchased credits.') }}
				</li>
			</ul>
		</NcNoteCard>
		<NcNoteCard v-if="keyError" type="error">
			{{ keyError }}
		</NcNoteCard>

		<h3>{{ t('openrouter_connector', 'Models') }}</h3>
		<p class="hint">
			{{ t('openrouter_connector', 'Select the models to expose, per modality. Each selected model is registered as a set of providers named after the model, which you can then assign to tasks in the Artificial Intelligence settings. Models that are missing from the list can be typed in by their ID.') }}
			<a class="external"
				href="https://openrouter.ai/models"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openrouter_connector', 'Browse the OpenRouter models') }}
			</a>
		</p>
		<ul v-if="activeFilterHints.length > 0" class="hint filter-hints">
			<li v-for="hint in activeFilterHints" :key="hint">
				{{ hint }}
			</li>
		</ul>
		<NcNoteCard v-if="catalogError" type="warning">
			{{ catalogError }}
		</NcNoteCard>
		<ModelSelector v-for="modality in modalities"
			:key="modality.key"
			:model-value="state[modality.key]"
			:options="catalog[modality.key]"
			:loading="loadingCatalog[modality.key]"
			:label="modality.label"
			:hint="modality.hint"
			@update:model-value="onModelsChange(modality.key, $event)" />
		<div class="line">
			<NcButton :disabled="anyCatalogLoading" @click="loadCatalog(true)">
				{{ t('openrouter_connector', 'Reload model list') }}
			</NcButton>
			<NcButton :disabled="refreshingMetadata" @click="refreshMetadata">
				<template #icon>
					<NcLoadingIcon v-if="refreshingMetadata" :size="20" />
				</template>
				{{ t('openrouter_connector', 'Refresh model details') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="unavailableModels.length > 0" type="warning">
			{{ n('openrouter_connector',
				'This model is not available with the current settings, tasks using it will fail: {models}',
				'These models are not available with the current settings, tasks using them will fail: {models}',
				unavailableModels.length, { models: unavailableModels.join(', ') }) }}
			<div class="line">
				<NcButton @click="removeUnavailableModels">
					{{ n('openrouter_connector', 'Remove it from the selection', 'Remove them from the selection', unavailableModels.length) }}
				</NcButton>
			</div>
		</NcNoteCard>
		<p v-if="selectedModelCount > 0" class="hint">
			{{ n('openrouter_connector', '%n model selected. The providers are listed in the Artificial Intelligence settings.', '%n models selected. The providers are listed in the Artificial Intelligence settings.', selectedModelCount) }}
			<a class="external"
				:href="aiSettingsUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openrouter_connector', 'Artificial Intelligence settings') }}
			</a>
		</p>

		<h3>{{ t('openrouter_connector', 'Privacy') }}</h3>
		<p class="hint">
			{{ t('openrouter_connector', 'OpenRouter routes every request to one of the providers hosting the selected model. These routing preferences restrict which providers may be used for text tasks.') }}
			<a class="external"
				href="https://openrouter.ai/docs/guides/privacy/provider-logging"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openrouter_connector', 'OpenRouter privacy documentation') }}
			</a>
		</p>
		<NcCheckboxRadioSwitch :model-value="state.data_collection_deny"
			type="switch"
			@update:model-value="onInput({ data_collection_deny: $event })">
			{{ t('openrouter_connector', 'Only use providers that do not store or train on prompts (data_collection: deny)') }}
		</NcCheckboxRadioSwitch>
		<p class="hint">
			{{ t('openrouter_connector', 'OpenRouter does not offer a model list for this option, so the model lists above are not narrowed down by it. A model whose providers all store prompts fails instead.') }}
		</p>
		<NcCheckboxRadioSwitch :model-value="state.zdr"
			:disabled="reloadingCatalog"
			type="switch"
			@update:model-value="onZdrChange">
			{{ t('openrouter_connector', 'Only use zero data retention endpoints') }}
		</NcCheckboxRadioSwitch>
		<p class="hint">
			{{ t('openrouter_connector', 'The model lists above then only offer the models that have such an endpoint. The preference itself is only sent with text tasks; for the other modalities OpenRouter applies what is set in the account.') }}
		</p>
		<NcCheckboxRadioSwitch :model-value="state.send_referer"
			type="switch"
			@update:model-value="onInput({ send_referer: $event })">
			{{ t('openrouter_connector', 'Send the address of this instance ({url}) to OpenRouter for usage attribution', { url: state.instance_url }) }}
		</NcCheckboxRadioSwitch>

		<h3>{{ t('openrouter_connector', 'Advanced') }}</h3>
		<div class="line">
			<NcInputField id="openrouter-max-tokens"
				:model-value="String(state.max_tokens)"
				class="input"
				type="number"
				:label="t('openrouter_connector', 'Maximum output tokens')"
				:helper-text="t('openrouter_connector', 'Default and upper limit of the number of tokens a text model may generate per request')"
				@update:model-value="onInput({ max_tokens: parseInt($event) || 1 })" />
		</div>
		<div class="line">
			<NcInputField id="openrouter-request-timeout"
				:model-value="String(state.request_timeout)"
				class="input"
				type="number"
				:label="t('openrouter_connector', 'Request timeout (seconds)')"
				@update:model-value="onInput({ request_timeout: parseInt($event) || 5 })" />
		</div>
		<div class="line">
			<NcInputField id="openrouter-chunk-size"
				:model-value="String(state.chunk_size)"
				class="input"
				type="number"
				:label="t('openrouter_connector', 'Chunk size (tokens)')"
				:helper-text="t('openrouter_connector', 'Long texts are split into chunks of this size before they are sent to the model. 0 disables chunking.')"
				@update:model-value="onInput({ chunk_size: parseInt($event) || 0 })" />
		</div>
		<div class="line">
			<NcSelect :model-value="ttsVoiceOption"
				:options="voiceOptions"
				:input-label="t('openrouter_connector', 'Default text-to-speech voice')"
				:taggable="true"
				:clearable="false"
				class="input"
				:create-option="text => ({ id: String(text).trim(), label: String(text).trim() })"
				@update:model-value="onVoiceChange" />
		</div>
	</NcSettingsSection>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'

import ModelSelector from './ModelSelector.vue'

const MODALITIES = ['text', 'image', 'stt', 'tts']
const MODEL_KEYS = {
	text: 'text_models',
	image: 'image_models',
	stt: 'stt_models',
	tts: 'tts_models',
}

export default {
	name: 'AdminSettings',

	components: {
		ModelSelector,
		NcButton,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcSettingsSection,
	},

	data() {
		return {
			state: loadState('openrouter_connector', 'admin-config'),
			apiKey: '',
			reloadingCatalog: false,
			savingKey: false,
			checkingKey: false,
			keyInfo: null,
			keyError: null,
			catalog: { text_models: [], image_models: [], stt_models: [], tts_models: [] },
			// what narrowed a list down, per modality, as the backend reports it
			catalogFilters: { text_models: [], image_models: [], stt_models: [], tts_models: [] },
			catalogLoaded: { text_models: false, image_models: false, stt_models: false, tts_models: false },
			loadingCatalog: { text_models: false, image_models: false, stt_models: false, tts_models: false },
			catalogError: null,
			refreshingMetadata: false,
			assistantAppUrl: generateUrl('/settings/apps/integration/assistant'),
			aiSettingsUrl: generateUrl('/settings/admin/ai'),
		}
	},

	computed: {
		endpoints() {
			return [
				{ id: 'global', label: t('openrouter_connector', 'Standard endpoint (openrouter.ai)') },
				{ id: 'eu', label: t('openrouter_connector', 'EU endpoint (eu.openrouter.ai)') },
			]
		},
		modalities() {
			return [
				{
					key: 'text_models',
					label: t('openrouter_connector', 'Text models'),
					hint: t('openrouter_connector', 'Used for free prompts, chat, summaries, translations and all other text tasks. Models with image input also provide image analysis and OCR.'),
				},
				{
					key: 'image_models',
					label: t('openrouter_connector', 'Image generation models'),
					hint: t('openrouter_connector', 'Used to generate images from text prompts.'),
				},
				{
					key: 'stt_models',
					label: t('openrouter_connector', 'Speech-to-text models'),
					hint: t('openrouter_connector', 'Used to transcribe audio files.'),
				},
				{
					key: 'tts_models',
					label: t('openrouter_connector', 'Text-to-speech models'),
					hint: t('openrouter_connector', 'Used to read texts aloud.'),
				},
			]
		},
		anyCatalogLoading() {
			return Object.values(this.loadingCatalog).some(Boolean)
		},
		selectedModelCount() {
			return Object.values(MODEL_KEYS).reduce((count, key) => count + (this.state[key]?.length ?? 0), 0)
		},
		/**
		 * The selected models that the filters of a list rule out. Such a list
		 * is what the settings can actually reach, so a model missing from it
		 * cannot answer. An unfiltered list is not authoritative that way —
		 * model IDs may deliberately be typed in — so nothing is flagged
		 * there, and neither is a list that could not be loaded, so a failed
		 * request does not flag every model.
		 *
		 * @return {string[]} the model IDs
		 */
		unavailableModels() {
			const missing = new Set()
			for (const key of Object.values(MODEL_KEYS)) {
				if (!this.catalogLoaded[key] || !this.catalogFilters[key]?.length) {
					continue
				}
				const available = new Set(this.catalog[key].map(model => model.id))
				for (const id of this.state[key] ?? []) {
					if (!available.has(id)) {
						missing.add(id)
					}
				}
			}
			return [...missing]
		},
		/**
		 * One sentence per filter that narrowed at least one of the lists down
		 *
		 * @return {string[]} the sentences, in the order the filters are applied
		 */
		activeFilterHints() {
			const active = new Set(Object.values(MODEL_KEYS).flatMap(key => this.catalogFilters[key] ?? []))
			const hints = {
				endpoint: t('openrouter_connector', 'Only the models OpenRouter has onboarded for the selected endpoint are listed.'),
				zdr: t('openrouter_connector', 'Only the models that have a zero data retention endpoint are listed, because that is what the privacy option below requires.'),
				key: t('openrouter_connector', 'Only the models the configured API key may use are listed, as OpenRouter narrows them down for the privacy settings and the guardrails of the account.'),
			}
			return ['endpoint', 'zdr', 'key'].filter(filter => active.has(filter)).map(filter => hints[filter])
		},
		/** The voices of the selected text-to-speech models, as far as they are known */
		voiceOptions() {
			const voices = new Set()
			for (const modelId of this.state.tts_models ?? []) {
				for (const voice of this.state.model_metadata?.[modelId]?.supported_voices ?? []) {
					voices.add(voice)
				}
			}
			if (this.state.tts_voice) {
				voices.add(this.state.tts_voice)
			}
			return [...voices].sort().map(voice => ({ id: voice, label: voice }))
		},
		ttsVoiceOption() {
			return this.state.tts_voice ? { id: this.state.tts_voice, label: this.state.tts_voice } : null
		},
	},

	created() {
		this.debouncedSave = debounce(this.saveValues, 1000)
		this.pendingValues = {}
	},

	mounted() {
		this.loadCatalog(false)
	},

	methods: {
		errorMessage(error, fallback) {
			return error?.response?.data?.error ?? error?.message ?? fallback
		},

		async saveApiKey() {
			const apiKey = this.apiKey.trim()
			if (apiKey === '') {
				return
			}
			await this.storeApiKey(apiKey, t('openrouter_connector', 'API key saved'))
		},

		async removeApiKey() {
			if (!window.confirm(t('openrouter_connector', 'Remove the API key? All OpenRouter providers will stop working until a new key is saved.'))) {
				return
			}
			await this.storeApiKey('', t('openrouter_connector', 'API key removed'))
		},

		async storeApiKey(apiKey, successMessage) {
			try {
				await confirmPassword()
			} catch (error) {
				console.debug('Password confirmation was dismissed', error)
				return
			}
			this.savingKey = true
			try {
				const response = await axios.put(generateUrl('/apps/openrouter_connector/admin-config/api-key'), { apiKey })
				this.applyConfig(response.data)
				this.apiKey = ''
				this.keyInfo = null
				this.keyError = null
				showSuccess(successMessage)
				// the guardrails of a key decide which models it may use
				await this.loadCatalog(true)
				if (this.state.api_key_set) {
					await this.checkKey()
				}
			} catch (error) {
				console.error(error)
				showError(t('openrouter_connector', 'Failed to save the API key') + ': ' + this.errorMessage(error, ''))
			} finally {
				this.savingKey = false
			}
		},

		async checkKey() {
			this.checkingKey = true
			this.keyInfo = null
			this.keyError = null
			try {
				const response = await axios.get(generateUrl('/apps/openrouter_connector/key-info'))
				this.keyInfo = response.data
			} catch (error) {
				console.error(error)
				this.keyError = t('openrouter_connector', 'Connection check failed') + ': ' + this.errorMessage(error, '')
			} finally {
				this.checkingKey = false
			}
		},

		formatCredits(value) {
			const number = Number(value)
			return Number.isFinite(number) ? number.toFixed(2) : String(value)
		},

		async loadCatalog(refresh) {
			this.catalogError = null
			await Promise.all(MODALITIES.map(modality => this.loadModality(modality, refresh)))
		},

		async loadModality(modality, refresh) {
			const key = MODEL_KEYS[modality]
			this.loadingCatalog[key] = true
			try {
				const url = generateUrl('/apps/openrouter_connector/models/{modality}', { modality })
				const response = await axios.get(url, { params: refresh ? { refresh: 1 } : {} })
				this.catalog[key] = (response.data?.models ?? []).map(model => ({
					...model,
					label: model.name && model.name !== model.id ? `${model.name} (${model.id})` : model.id,
				}))
				this.catalogFilters[key] = response.data?.filters ?? []
				this.catalogLoaded[key] = true
			} catch (error) {
				console.error(error)
				this.catalogLoaded[key] = false
				this.catalogError = t('openrouter_connector', 'Failed to load the OpenRouter model list. Model IDs can still be typed in.') + ' ' + this.errorMessage(error, '')
			} finally {
				this.loadingCatalog[key] = false
			}
		},

		/**
		 * Stores the endpoint without waiting for the debounce and reloads
		 * everything that depends on it: the catalogs differ per endpoint and
		 * the connection was checked against the previous one
		 *
		 * @param {string} endpoint the ID of the selected endpoint
		 */
		async onEndpointChange(endpoint) {
			if (endpoint === this.state.api_endpoint) {
				return
			}
			this.keyInfo = null
			this.keyError = null
			await this.saveAndReloadCatalog({ api_endpoint: endpoint })
		},

		/**
		 * Zero data retention decides which models can answer at all, so the
		 * lists are reloaded with it, just like an endpoint change
		 *
		 * @param {boolean} zdr whether only zero data retention endpoints may be used
		 */
		async onZdrChange(zdr) {
			if (zdr === this.state.zdr) {
				return
			}
			await this.saveAndReloadCatalog({ zdr })
		},

		/**
		 * Stores settings the model lists depend on without waiting for the
		 * debounce and loads the lists again afterwards
		 *
		 * @param {object} values the changed settings
		 */
		async saveAndReloadCatalog(values) {
			Object.assign(this.state, values)
			Object.assign(this.pendingValues, values)
			this.debouncedSave.clear()
			this.reloadingCatalog = true
			try {
				await this.saveValues()
				await this.loadCatalog(true)
			} finally {
				this.reloadingCatalog = false
			}
		},

		/**
		 * Drops the models the current settings cannot reach from the selection
		 */
		removeUnavailableModels() {
			const unavailable = new Set(this.unavailableModels)
			const values = {}
			for (const key of Object.values(MODEL_KEYS)) {
				const selected = this.state[key] ?? []
				const kept = selected.filter(id => !unavailable.has(id))
				if (kept.length !== selected.length) {
					values[key] = kept
				}
			}
			if (Object.keys(values).length > 0) {
				this.onInput(values)
			}
		},

		onModelsChange(key, ids) {
			this.onInput({ [key]: ids })
		},

		onVoiceChange(option) {
			const voice = (typeof option === 'string' ? option : option?.id ?? '').trim()
			this.onInput({ tts_voice: voice })
		},

		/**
		 * Applies a change locally right away and saves it debounced, so that
		 * typing stays responsive
		 *
		 * @param {object} values the changed settings
		 */
		onInput(values) {
			Object.assign(this.state, values)
			Object.assign(this.pendingValues, values)
			this.debouncedSave()
		},

		async saveValues() {
			const values = this.pendingValues
			this.pendingValues = {}
			if (Object.keys(values).length === 0) {
				return
			}
			try {
				const response = await axios.put(generateUrl('/apps/openrouter_connector/admin-config'), { values })
				this.applyConfig(response.data)
				showSuccess(t('openrouter_connector', 'OpenRouter settings saved'))
			} catch (error) {
				console.error(error)
				showError(t('openrouter_connector', 'Failed to save the OpenRouter settings') + ': ' + this.errorMessage(error, ''))
			}
		},

		async refreshMetadata() {
			this.refreshingMetadata = true
			try {
				const response = await axios.post(generateUrl('/apps/openrouter_connector/admin-config/refresh-metadata'))
				this.applyConfig(response.data)
				showSuccess(t('openrouter_connector', 'Model details refreshed'))
			} catch (error) {
				console.error(error)
				showError(t('openrouter_connector', 'Failed to refresh the model details') + ': ' + this.errorMessage(error, ''))
			} finally {
				this.refreshingMetadata = false
			}
		},

		/**
		 * Takes over what the backend stored, except values that are being
		 * edited and not saved yet
		 *
		 * @param {object} config the admin config as the backend returned it
		 */
		applyConfig(config) {
			for (const [key, value] of Object.entries(config ?? {})) {
				if (!(key in this.pendingValues)) {
					this.state[key] = value
				}
			}
		},
	},
}
</script>

<style scoped>
h3 {
	margin-top: 24px;
	margin-bottom: 8px;
	font-weight: bold;
}

.hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
	max-width: 800px;
}

.line {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 8px;
	margin-bottom: 12px;
	max-width: 800px;
}

.input {
	flex: 1 1 320px;
	max-width: 480px;
}

.key-info,
.filter-hints {
	margin-top: 4px;
	padding-inline-start: 20px;
	list-style: disc;
}

.filter-hints {
	max-width: 800px;
	margin-bottom: 8px;
}
</style>
