/**
 * SPDX-FileCopyrightText: 2026 iurFRIEND and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'

import '@nextcloud/dialogs/style.css'
import '@nextcloud/password-confirmation/style.css'

import { n, t } from '@nextcloud/l10n'
import AdminSettings from './components/AdminSettings.vue'

const app = createApp(AdminSettings)
app.mixin({ methods: { t, n } })
app.mount('#openrouter_connector_prefs')
