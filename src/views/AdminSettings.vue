<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AxiosError, AxiosResponse } from '@nextcloud/axios'

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { reactive, ref } from 'vue'

// Temporary replacement for @nextcloud/dialogs until Vue 3 compatibility
function showSuccess(message: string) {
	console.log('Success:', message)
	// Could use a simple notification or alert
}

function showError(message: string) {
	console.error('Error:', message)
	// Could use a simple notification or alert
}

import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AdminServiceTemplates from '../components/AdminServiceTemplates.vue'
import DavIcon from '../icons/DavIcon.vue'

// Types
interface AdminConfigurationState {
	harmonization_interval: string | number
	force_certificate_verification: string | number | boolean
	forbid_insecure_http: string | number | boolean
}

interface SelectOption {
	label: string
	id: number
}

interface SaveRequest {
	values: {
		harmonization_interval: number
		force_certificate_verification: boolean
		forbid_insecure_http: boolean
	}
}

// Reactive data
const state = reactive<AdminConfigurationState>(loadState('integration_davc', 'admin-configuration') as AdminConfigurationState)
const harmonizationInterval = ref<number>(Number(state.harmonization_interval) || 900)
const forceCertificateVerification = ref<boolean>(Boolean(Number(state.force_certificate_verification)))
const forbidInsecureHttp = ref<boolean>(Boolean(Number(state.forbid_insecure_http)))
const saving = ref<boolean>(false)

// Select options for synchronization interval
const synchronizationIntervalOptions: SelectOption[] = [
	{ label: t('integration_davc', 'Every 5 minutes'), id: 300 },
	{ label: t('integration_davc', 'Every 15 minutes'), id: 900 },
	{ label: t('integration_davc', 'Every 30 minutes'), id: 1800 },
	{ label: t('integration_davc', 'Every hour'), id: 3600 },
	{ label: t('integration_davc', 'Every 6 hours'), id: 21600 },
	{ label: t('integration_davc', 'Every 12 hours'), id: 43200 },
	{ label: t('integration_davc', 'Once a day'), id: 86400 },
]

// Methods
async function onSaveClick(): Promise<void> {
	// prevent concurrent saves from rapid clicks
	if (saving.value) {
		return
	}
	saving.value = true

	const req: SaveRequest = {
		values: {
			harmonization_interval: harmonizationInterval.value,
			force_certificate_verification: forceCertificateVerification.value,
			forbid_insecure_http: forbidInsecureHttp.value,
		},
	}

	const url = generateUrl('/apps/integration_davc/admin-configuration')

	try {
		const response: AxiosResponse = await axios.post(url, req)
		showSuccess(t('integration_davc', 'DAV admin configuration saved'))
	} catch (error) {
		const axiosError = error as AxiosError
		const errorMessage = axiosError.response?.data
			? String(axiosError.response.data)
			: axiosError.message || 'Unknown error occurred'

		showError(t('integration_davc', 'Failed to save DAV admin configuration')
			+ ': ' + errorMessage)
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div id="davc_settings" class="section">
		<div class="davc-section-heading">
			<DavIcon :size="32" /><h2> {{ t('integration_davc', 'DAV Connector') }}</h2>
		</div>
		<p class="settings-hint">
			{{ t('integration_davc', 'Select the system settings for DAV Integration') }}
		</p>
		<div class="fields">
			<div>
				<div class="line">
					<label>
						{{ t('integration_davc', 'Synchronization interval') }}
					</label>
					<NcSelect
						v-model="harmonizationInterval"
						:reduce="item => item.id"
						:options="synchronizationIntervalOptions" />
				</div>
				<div class="line">
					<NcCheckboxRadioSwitch v-model="forceCertificateVerification" type="switch">
						{{ t('integration_davc', 'Require certificate verification for all connections') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="line">
					<NcCheckboxRadioSwitch v-model="forbidInsecureHttp" type="switch">
						{{ t('integration_davc', 'Forbid insecure (HTTP) connections') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
			<br>
			<div class="davc-actions">
				<NcButton :disabled="saving" @click="onSaveClick()">
					<template #icon>
						<NcLoadingIcon v-if="saving" />
						<CheckIcon v-else />
					</template>
					{{ t('integration_davc', 'Save') }}
				</NcButton>
			</div>
		</div>
		<AdminServiceTemplates />
	</div>
</template>

<style scoped lang="scss">
#davc_settings {
	.davc-section-heading {
		display:inline-block;
		vertical-align:middle;
	}

	.davc-connected {
		display: flex;
		align-items: center;

		label {
			padding-left: 1em;
			padding-right: 1em;
		}
	}

	.davc-collectionlist-item {
		display: flex;
		align-items: center;

		label {
			padding-left: 1em;
			padding-right: 1em;
		}
	}

	.davc-actions {
		display: flex;
		align-items: center;
	}

	.external-label {
		display: flex;
		//width: 100%;
		margin-top: 1rem;
	}

	.external-label label {
		padding-top: 7px;
		padding-right: 14px;
		white-space: nowrap;
	}
}
</style>
