<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Collection } from '../types/Collection.ts'
import type { Service } from '../types/Service.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ContactIcon from 'vue-material-design-icons/ContactsOutline.vue'
import LinkIcon from 'vue-material-design-icons/Link.vue'
import SwapHorizontalIcon from 'vue-material-design-icons/SwapHorizontal.vue'
import DavIcon from '../icons/DavIcon.vue'
import SettingsMigrationModal from './SettingsMigrationModal.vue'

interface SystemConfiguration {
	system_contacts: boolean
	system_events: boolean
}

const props = defineProps<{
	service: Service
	busy: boolean
	systemConfiguration: SystemConfiguration
	contactsRemoteSupported: boolean
	contactsRemoteCollections: Collection[]
	contactsLocalCollections: Collection[]
	eventsRemoteSupported: boolean
	eventsRemoteCollections: Collection[]
	eventsLocalCollections: Collection[]
	formatDate: (dt: number | undefined) => string
	changeContactCorrelation: (rcid: string | null, enabled: boolean) => void
	changeEventCorrelation: (rcid: string | null, enabled: boolean) => void
}>()

const emit = defineEmits<{
	(event: 'save'): void
	(event: 'harmonize'): void
	(event: 'disconnect'): void
}>()

const showMigrationModal = ref<boolean>(false)

function randomColor(): string {
	return '#' + (Math.random() * 0xFFFFFF << 0).toString(16).padStart(6, '0')
}

const selectedcolor = ref('')
const color = computed({
	get() {
		return selectedcolor.value || randomColor()
	},
	set(value: string) {
		selectedcolor.value = value
	},
})

function establishedContactCorrelation(rcid: string | null): boolean {
	if (!rcid) {
		return false
	}
	const localCollection = props.contactsLocalCollections.find((item) => String(item.ccid) === String(rcid))
	if (typeof localCollection === 'undefined') {
		return false
	}
	if (typeof localCollection.enabled === 'undefined') {
		return true
	}
	return localCollection.enabled
}

function establishedEventCorrelation(rcid: string | null): boolean {
	if (!rcid) {
		return false
	}
	const localCollection = props.eventsLocalCollections.find((item) => String(item.ccid) === String(rcid))
	if (typeof localCollection === 'undefined') {
		return false
	}
	if (typeof localCollection.enabled === 'undefined') {
		return true
	}
	return localCollection.enabled
}

function establishedContactCorrelationColor(ccid: string | null): string {
	if (!ccid) {
		return randomColor()
	}
	const collection = props.contactsLocalCollections.find((item) => String(item.ccid) === String(ccid))
	if (typeof collection !== 'undefined') {
		return collection.color || randomColor()
	}
	return randomColor()
}

function establishedEventCorrelationColor(ccid: string | null): string {
	if (!ccid) {
		return randomColor()
	}
	const collection = props.eventsLocalCollections.find((item) => String(item.ccid) === String(ccid))
	if (typeof collection !== 'undefined') {
		return collection.color || randomColor()
	}
	return randomColor()
}

function establishedContactCorrelationHarmonized(ccid: string | null): number {
	if (!ccid) {
		return 0
	}
	const collection = props.contactsLocalCollections.find((item) => String(item.ccid) === String(ccid))
	if (typeof collection !== 'undefined') {
		return collection.hlockhb || 0
	}
	return 0
}

function establishedEventCorrelationHarmonized(ccid: string | null): number {
	if (!ccid) {
		return 0
	}
	const collection = props.eventsLocalCollections.find((item) => String(item.ccid) === String(ccid))
	if (typeof collection !== 'undefined') {
		return collection.hlockhb || 0
	}
	return 0
}
</script>

<template>
	<div class="davc-section__connected">
		<div class="connection-status">
			<h3 class="connection-status__title">
				{{ t('integration_davc', 'Connection') }}
			</h3>
			<div class="connection-status__overview">
				<DavIcon />
				<span>{{ t('integration_davc', 'Connected as {0} to {1}', {0: service.bauth_id || '', 1: service.location_host || ''}) }}</span>
			</div>
			<div class="connection-status__harmonization">
				{{ t('integration_davc', 'Synchronization was last started on {0} and finished on {1}', {0: formatDate(service.harmonization_start), 1: formatDate(service.harmonization_end)}) }}
			</div>
		</div>
		<div class="connection-correlations-contacts">
			<h3>{{ t('integration_davc', 'Contacts') }}</h3>
			<div v-if="systemConfiguration.system_contacts && contactsRemoteSupported" class="instruction-message">
				{{ t('integration_davc', 'Select the contacts collection(s) you wish to synchronize by using the toggle') }}
			</div>
			<div v-if="!systemConfiguration.system_contacts" class="warning-message">
				{{ t('integration_davc', 'The contacts app is either disabled or not installed. Please contact your administrator to install or enable the app') }}
			</div>
			<div v-if="!contactsRemoteSupported" class="warning-message">
				{{ t('integration_davc', 'The connected service does not support contacts') }}
			</div>
			<div v-if="systemConfiguration.system_contacts && contactsRemoteSupported" class="collections-list">
				<ul v-if="contactsRemoteCollections.length > 0">
					<li v-for="ritem in contactsRemoteCollections" :key="ritem.id ?? ritem.ccid" class="collections-list-item">
						<NcCheckboxRadioSwitch
							type="switch"
							:modelValue="establishedContactCorrelation(ritem.id)"
							@update:modelValue="changeContactCorrelation(ritem.id, $event)" />
						<ContactIcon :inline="true" :style="{ color: establishedContactCorrelationColor(ritem.id) }" />
						<label>
							{{ ritem.label }}
						</label>
						<label v-if="ritem.count && ritem.count > 0">
							({{ ritem.count }} {{ t('integration_davc', 'Contacts') }})
						</label>
						<label v-if="establishedContactCorrelationHarmonized(ritem.id) > 0">
							{{ t('integration_davc', 'Last Harmonized {0}', {0: formatDate(establishedContactCorrelationHarmonized(ritem.id))}) }}
						</label>
						<label v-else>
							{{ t('integration_davc', 'Never harmonized') }}
						</label>
					</li>
				</ul>
				<div v-else-if="contactsRemoteCollections.length === 0" class="empty-message">
					{{ t('integration_davc', 'No contacts collections were found in the connected account') }}
				</div>
				<div v-else class="loading-message">
					{{ t('integration_davc', 'Loading contacts collections from the connected account') }}
				</div>
			</div>
		</div>
		<div class="connection-correlations-events">
			<h3>{{ t('integration_davc', 'Calendars') }}</h3>
			<div v-if="systemConfiguration.system_events && eventsRemoteSupported" class="instruction-message">
				{{ t('integration_davc', 'Select the events collection(s) you wish to synchronize by using the toggle') }}
			</div>
			<div v-if="!systemConfiguration.system_events" class="warning-message">
				{{ t('integration_davc', 'The calendar app is either disabled or not installed. Please contact your administrator to install or enable the app') }}
			</div>
			<div v-if="!eventsRemoteSupported" class="warning-message">
				{{ t('integration_davc', 'The connected service does not support events') }}
			</div>
			<div v-if="systemConfiguration.system_events && eventsRemoteSupported" class="collections-list">
				<ul v-if="eventsRemoteCollections.length > 0">
					<li v-for="ritem in eventsRemoteCollections" :key="ritem.id ?? ritem.ccid" class="collections-list-item">
						<NcCheckboxRadioSwitch
							type="switch"
							:modelValue="establishedEventCorrelation(ritem.id)"
							@update:modelValue="changeEventCorrelation(ritem.id, $event)" />
						<NcColorPicker v-model="color" :advancedFields="true">
							<CalendarIcon :inline="true" :style="{ color: establishedEventCorrelationColor(ritem.id) }" />
						</NcColorPicker>
						<label>
							{{ ritem.label }}
						</label>
						<label v-if="ritem.count && ritem.count > 0">
							({{ ritem.count }} {{ t('integration_davc', 'Events') }})
						</label>
						<label v-if="establishedEventCorrelationHarmonized(ritem.id) > 0">
							{{ t('integration_davc', 'Last Harmonized {0}', {0: formatDate(establishedEventCorrelationHarmonized(ritem.id))}) }}
						</label>
						<label v-else>
							{{ t('integration_davc', 'Never harmonized') }}
						</label>
					</li>
				</ul>
				<div v-else-if="eventsRemoteCollections.length === 0" class="empty-message">
					{{ t('integration_davc', 'No events collections were found in the connected account') }}
				</div>
				<div v-else class="loading-message">
					{{ t('integration_davc', 'Loading events collections from the connected account') }}
				</div>
			</div>
		</div>
		<div class="actions">
			<NcButton :disabled="busy" @click="emit('save')">
				<template #icon>
					<CheckIcon />
				</template>
				{{ t('integration_davc', 'Save') }}
			</NcButton>
			<NcButton :disabled="busy" @click="emit('harmonize')">
				<template #icon>
					<LinkIcon />
				</template>
				{{ t('integration_davc', 'Harmonize') }}
			</NcButton>
			<NcButton :disabled="busy" @click="showMigrationModal = true">
				<template #icon>
					<SwapHorizontalIcon />
				</template>
				{{ t('integration_davc', 'Migrate') }}
			</NcButton>
			<NcButton :disabled="busy" @click="emit('disconnect')">
				<template #icon>
					<CloseIcon />
				</template>
				{{ t('integration_davc', 'Disconnect') }}
			</NcButton>
		</div>

		<SettingsMigrationModal
			v-if="showMigrationModal"
			:service="service"
			:contactsRemoteSupported="contactsRemoteSupported"
			:contactsRemoteCollections="contactsRemoteCollections"
			:eventsRemoteSupported="eventsRemoteSupported"
			:eventsRemoteCollections="eventsRemoteCollections"
			@close="showMigrationModal = false" />
	</div>
</template>

<style scoped lang="scss">
.davc-section__connected {
	margin-top: 20px;
	border-top: 1px solid var(--color-border);
	border-bottom: 1px solid var(--color-border);

	.connection-status {
		margin-bottom: 30px;

		.connection-status__title {
			margin-bottom: 16px;
			font-size: 18px;
			font-weight: bold;
		}

		.connection-status__overview {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 12px;
			padding: 12px;

			span {
				font-weight: 500;
			}
		}

		.connection-status__harmonization {
			font-size: 14px;
			color: var(--color-text-maxcontrast);
			margin-left: 12px;
		}
	}

	.connection-correlations-contacts,
	.connection-correlations-events {
		margin-bottom: 24px;

		h3 {
			margin-bottom: 12px;
			font-size: 18px;
			font-weight: bold;
		}

		ul {
			list-style: none;
			padding: 0;
			margin: 0;

			.collections-list-item {
				display: flex;
				align-items: center;
				padding: 12px;

				label {
					flex: 1;
					font-weight: 500;

					&:last-child {
						font-size: 12px;
						font-weight: normal;
					}
				}
			}
		}
	}

	.actions {
		display: flex;
		gap: 12px;
		margin-top: 24px;
		padding-top: 20px;
	}
}
</style>
