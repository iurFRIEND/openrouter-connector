<!--
  - SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="model-selector">
		<NcSelect :model-value="selectedOptions"
			:options="options"
			:input-label="label"
			:placeholder="placeholder"
			:multiple="true"
			:taggable="true"
			:close-on-select="false"
			:loading="loading"
			:disabled="disabled"
			:create-option="createOption"
			@update:model-value="onUpdate" />
		<p class="model-selector__hint">
			{{ hint }}
		</p>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { t } from '@nextcloud/l10n'

export default {
	name: 'ModelSelector',

	components: {
		NcSelect,
	},

	props: {
		/** The IDs of the selected models */
		modelValue: {
			type: Array,
			required: true,
		},
		/** The models of the catalog, as { id, label, ... } */
		options: {
			type: Array,
			default: () => [],
		},
		label: {
			type: String,
			required: true,
		},
		hint: {
			type: String,
			default: '',
		},
		loading: {
			type: Boolean,
			default: false,
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/** The selected models as options, so that models missing from the catalog still show up */
		selectedOptions() {
			return this.modelValue.map(id => this.options.find(option => option.id === id) ?? { id, label: id })
		},
		placeholder() {
			return this.options.length === 0
				? t('openrouter_connector', 'Type a model ID, for example openai/gpt-5-mini')
				: t('openrouter_connector', 'Search or type a model ID')
		},
	},

	methods: {
		createOption(text) {
			const id = String(text).trim()
			return { id, label: id }
		},
		onUpdate(options) {
			const ids = (options ?? [])
				.map(option => (typeof option === 'string' ? option : option?.id) ?? '')
				.map(id => id.trim())
				.filter((id, index, all) => id !== '' && all.indexOf(id) === index)
			this.$emit('update:modelValue', ids)
		},
	},
}
</script>

<style scoped>
.model-selector {
	max-width: 800px;
	margin-bottom: 12px;
}

.model-selector__hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
}
</style>
