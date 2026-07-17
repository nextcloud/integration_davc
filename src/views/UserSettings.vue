<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Collection } from '../types/Collection.ts'
import type { Service } from '../types/Service.ts'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { nextTick, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AccountRemoveIcon from 'vue-material-design-icons/AccountMinus.vue'
import AccountAddIcon from 'vue-material-design-icons/AccountPlus.vue'
import SettingsConnectedService from '../components/SettingsConnectedService.vue'
import SettingsEmptyState from '../components/SettingsEmptyState.vue'
import SettingsFreshService from '../components/SettingsFreshService.vue'
import DavIcon from '../icons/DavIcon.vue'

// Types
interface SystemConfiguration {
	system_contacts: boolean
	system_events: boolean
	force_certificate_verification: boolean
	forbid_insecure_http: boolean
}

// Reactive data
const systemConfiguration = reactive<SystemConfiguration>(loadState('integration_davc', 'system-configuration') as SystemConfiguration)

// Services
const configuredServices = ref<Service[]>([])
const selectedService = ref<Service | null>(null)

// Whether an action (save/harmonize/disconnect) is currently in progress
const busy = ref<boolean>(false)

// Runs an action while preventing any other action from starting until it completes
async function runExclusive(action: () => void | Promise<void>): Promise<void> {
	if (busy.value) {
		return
	}
	busy.value = true
	try {
		await action()
	} finally {
		busy.value = false
	}
}

// Contacts
const contactsRemoteSupported = ref<boolean>(false)
const contactsRemoteCollections = ref<Collection[]>([])
const contactsLocalCollections = ref<Collection[]>([])

// Events/Calendars
const eventsRemoteSupported = ref<boolean>(false)
const eventsRemoteCollections = ref<Collection[]>([])
const eventsLocalCollections = ref<Collection[]>([])

// Lifecycle
onMounted(async () => {
	await serviceList()
	if (selectedService.value === null && configuredServices.value.length === 1) {
		await nextTick()
		await serviceSelect(configuredServices.value[0])
	}
})

// Methods
function formatDate(dt: number | undefined): string {
	if (dt) {
		return window.OC.Util.formatDate(dt * 1000)
	} else {
		return t('integration_davc', 'never')
	}
}

function getErrorResponseText(error: unknown): string {
	if (typeof error !== 'object' || error === null || !('response' in error)) {
		return ''
	}

	const { response } = error as { response?: { request?: { responseText?: string } } }
	return response?.request?.responseText ?? ''
}

function freshService(): void {
	selectedService.value = { label: t('integration_davc', 'New connection') } as Service
}
async function connectService(service: Service): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/service/connect')
	const data = {
		service,
	}
	try {
		const response = await axios.post(uri, data)
		showSuccess(t('integration_davc', 'Successfully connected to account'))
		selectedService.value = response.data
		serviceList()
		remoteCollectionsFetch()
		localCollectionsFetch()
	} catch (error: unknown) {
		showError(t('integration_davc', 'Failed to authenticate with server')
			+ ': ' + getErrorResponseText(error))
	}
}

async function disconnectService(): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/service/disconnect')
	const data = {
		sid: selectedService.value?.id,
	}
	try {
		await axios.post(uri, data)
		showSuccess(t('integration_davc', 'Disconnected from account'))
		// Reset state
		selectedService.value = null
		// contacts
		contactsRemoteSupported.value = false
		contactsRemoteCollections.value = []
		contactsLocalCollections.value = []
		// events
		eventsRemoteSupported.value = false
		eventsRemoteCollections.value = []
		eventsLocalCollections.value = []
		// refresh service list
		serviceList()
	} catch (error: unknown) {
		showError(t('integration_davc', 'Failed to disconnect from account')
			+ ': ' + getErrorResponseText(error))
	}
}

function modifyService(): Promise<void> {
	return localCollectionsDeposit()
}

async function harmonizeService(): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/service/harmonize')
	const data = {
		sid: selectedService.value?.id,
	}
	try {
		await axios.post(uri, data)
		showSuccess(t('integration_davc', 'Synchronized'))
	} catch (error: unknown) {
		showError(t('integration_davc', 'Synchronization failed')
			+ ': ' + getErrorResponseText(error))
	}
}

async function serviceList(): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/service/list')
	try {
		const response = await axios.get(uri)
		if (response.data) {
			configuredServices.value = Object.values(response.data)
			showSuccess(n('integration_davc', 'Found {count} configured service', 'Found {count} configured services', configuredServices.value.length, { count: configuredServices.value.length }))
		}
	} catch (error: unknown) {
		showError(t('integration_davc', 'Failed to load service list')
			+ ': ' + getErrorResponseText(error))
	}
}

async function serviceSelect(option: Service | null): Promise<void> {
	if (!option) {
		return
	}
	selectedService.value = option
	// reset collections state
	contactsRemoteSupported.value = false
	contactsRemoteCollections.value = []
	contactsLocalCollections.value = []
	eventsRemoteSupported.value = false
	eventsRemoteCollections.value = []
	eventsLocalCollections.value = []

	busy.value = true
	try {
		await Promise.all([remoteCollectionsFetch(), localCollectionsFetch()])
	} finally {
		busy.value = false
	}
}
async function remoteCollectionsFetch(): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/remote/collections/fetch')
	const params = {
		sid: selectedService.value?.id,
	}
	try {
		const response = await axios.get(uri, { params })
		if (response.data.ContactsSupported) {
			contactsRemoteSupported.value = response.data.ContactsSupported
			contactsRemoteCollections.value = response.data.ContactsCollections
			showSuccess(n('integration_davc', 'Found {count} remote contacts collection', 'Found {count} remote contacts collections', contactsRemoteCollections.value.length, { count: contactsRemoteCollections.value.length }))
		}
		if (response.data.EventsSupported) {
			eventsRemoteSupported.value = response.data.EventsSupported
			eventsRemoteCollections.value = response.data.EventsCollections
			showSuccess(n('integration_davc', 'Found {count} remote events collection', 'Found {count} remote events collections', eventsRemoteCollections.value.length, { count: eventsRemoteCollections.value.length }))
		}
	} catch (error: unknown) {
		showError(t('integration_davc', 'Failed to load remote collections')
			+ ': ' + getErrorResponseText(error))
	}
}

async function localCollectionsFetch(): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/local/collections/fetch')
	const params = {
		sid: selectedService.value?.id,
	}
	try {
		const response = await axios.get(uri, { params })
		if (response.data.ContactCollections) {
			contactsLocalCollections.value = response.data.ContactCollections
			showSuccess(n('integration_davc', 'Found {count} local contact collection', 'Found {count} local contact collections', contactsLocalCollections.value.length, { count: contactsLocalCollections.value.length }))
		}
		if (response.data.EventCollections) {
			eventsLocalCollections.value = response.data.EventCollections
			showSuccess(n('integration_davc', 'Found {count} local event collection', 'Found {count} local event collections', eventsLocalCollections.value.length, { count: eventsLocalCollections.value.length }))
		}
	} catch (error: unknown) {
		showError(t('integration_davc', 'Failed to load remote collections')
			+ ': ' + getErrorResponseText(error))
	}
}

async function localCollectionsDeposit(): Promise<void> {
	const uri = generateUrl('/apps/integration_davc/local/collections/deposit')
	const data = {
		sid: selectedService.value?.id,
		ContactCorrelations: contactsLocalCollections.value,
		EventCorrelations: eventsLocalCollections.value,
	}
	try {
		const response = await axios.post(uri, data)
		if (response.data.ContactCollections) {
			contactsLocalCollections.value = response.data.ContactCollections
		}
		if (response.data.EventCollections) {
			eventsLocalCollections.value = response.data.EventCollections
		}
		showSuccess(t('integration_davc', 'Saved correlations'))
	} catch (error: unknown) {
		showError(t('integration_davc', 'Failed to save correlations')
			+ ': ' + getErrorResponseText(error))
	}
}
function changeContactCorrelation(rcid: string | null, e: boolean): void {
	if (!rcid) {
		return
	}
	const lCollection = contactsLocalCollections.value.find((i) => String(i.ccid) === String(rcid))

	if (lCollection === undefined) {
		const rCollection = contactsRemoteCollections.value.find((i) => String(i.id) === String(rcid))
		if (rCollection && rCollection.id) {
			contactsLocalCollections.value.push({
				id: null,
				ccid: rCollection.id,
				label: rCollection.label,
				enabled: e,
			})
		}
	} else {
		lCollection.enabled = e
	}
}

function changeEventCorrelation(rcid: string | null, e: boolean): void {
	if (!rcid) {
		return
	}
	const lid = eventsLocalCollections.value.findIndex((i) => String(i.ccid) === String(rcid))

	if (lid === -1) {
		const rCollection = eventsRemoteCollections.value.find((i) => String(i.id) === String(rcid))
		if (rCollection && rCollection.id) {
			eventsLocalCollections.value.push({
				id: null,
				ccid: rCollection.id,
				label: rCollection.label,
				enabled: e,
			})
		}
	} else {
		eventsLocalCollections.value[lid].enabled = e
	}
}

</script>

<template>
	<div class="davc-settings">
		<div class="davc-section__title">
			<DavIcon class="logo" />
			<span class="label">
				{{ t('integration_davc', 'DAV Connector') }}
			</span>
		</div>
		<div class="davc-section__selector">
			<label>
				{{ t('integration_davc', 'Services') }}
			</label>
			<NcSelect
				v-model="selectedService"
				:clearable="false"
				:searchable="false"
				:options="configuredServices"
				@option:selected="serviceSelect" />
			<NcButton @click="disconnectService()">
				<template #icon>
					<AccountRemoveIcon :size="20" />
				</template>
			</NcButton>
			<NcButton @click="freshService()">
				<template #icon>
					<AccountAddIcon :size="20" />
				</template>
			</NcButton>
		</div>

		<SettingsEmptyState v-if="selectedService === null" @addService="freshService()" />

		<SettingsFreshService
			v-if="selectedService !== null && !Boolean(selectedService.connected)"
			:service="selectedService"
			:busy="busy"
			:forceCertificateVerification="systemConfiguration.force_certificate_verification"
			:forbidInsecureHttp="systemConfiguration.forbid_insecure_http"
			@connect="runExclusive(() => connectService($event))" />

		<SettingsConnectedService
			v-if="selectedService !== null && Boolean(selectedService.connected)"
			:service="selectedService"
			:busy="busy"
			:systemConfiguration="systemConfiguration"
			:contactsRemoteSupported="contactsRemoteSupported"
			:contactsRemoteCollections="contactsRemoteCollections"
			:contactsLocalCollections="contactsLocalCollections"
			:eventsRemoteSupported="eventsRemoteSupported"
			:eventsRemoteCollections="eventsRemoteCollections"
			:eventsLocalCollections="eventsLocalCollections"
			:formatDate="formatDate"
			:changeContactCorrelation="changeContactCorrelation"
			:changeEventCorrelation="changeEventCorrelation"
			@save="runExclusive(modifyService)"
			@harmonize="runExclusive(harmonizeService)"
			@disconnect="runExclusive(disconnectService)" />
	</div>
</template>

<style scoped lang="scss">
.davc-settings {
	padding: 30px;
	max-width: 100%;
	width: 100%;
}

.davc-section__title {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;

	.logo {
		flex-shrink: 0;
		display: flex;
		align-items: center;
		justify-content: center;
	}

	.label {
		font-size: 24px;
		font-weight: bold;
		line-height: 1;
		display: flex;
		align-items: center;
	}
}

.davc-section__selector {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;

	label {
		font-weight: bold;
		white-space: nowrap;
	}
}

</style>
