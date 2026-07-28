<!--
 - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Collection } from '../types/Collection.ts'
import type { MigrationDirection, MigrationResource, MigrationState, NativeCollection } from '../types/Migration.ts'
import type { Service } from '../types/Service.ts'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { migrationService } from '../services/migrationService.ts'

const props = defineProps<{
	service: Service
	contactsRemoteSupported: boolean
	contactsRemoteCollections: Collection[]
	eventsRemoteSupported: boolean
	eventsRemoteCollections: Collection[]
}>()

const emit = defineEmits<{
	(event: 'close'): void
}>()

const resourceOptions = computed(() => {
	const options: { value: MigrationResource, label: string }[] = []
	if (props.eventsRemoteSupported) {
		options.push({ value: 'event', label: t('integration_davc', 'Calendar') })
	}
	if (props.contactsRemoteSupported) {
		options.push({ value: 'contact', label: t('integration_davc', 'Contacts') })
	}
	return options
})

const directionOptions = [
	{ value: 'inbound' as MigrationDirection, label: t('integration_davc', 'External → Internal') },
	{ value: 'outbound' as MigrationDirection, label: t('integration_davc', 'Internal → External') },
]

const migration = ref<MigrationState | null>(null)
const loadingStatus = ref<boolean>(true)
const resource = ref<MigrationResource>(resourceOptions.value[0]?.value ?? 'event')
const direction = ref<MigrationDirection>('inbound')
const remoteCollection = ref<string | null>(null)
const nativeCollection = ref<number | null>(null)
const overwrite = ref<boolean>(false)
const starting = ref<boolean>(false)
const remoteCollections = computed<Collection[]>(() => resource.value === 'contact' ? props.contactsRemoteCollections : props.eventsRemoteCollections)
const nativeCollections = ref<NativeCollection[]>([])

watch(resource, listCollections, { immediate: true })

onMounted(fetchStatus)

function closeDialog(): void {
	emit('close')
}

async function fetchStatus(): Promise<void> {
	loadingStatus.value = true
	try {
		migration.value = await migrationService.fetchStatus(props.service.id)
	} catch (error) {
		showError(t('integration_davc', 'Failed to fetch migration status') + ': ' + (error as Error).message)
	} finally {
		loadingStatus.value = false
	}
}

async function listCollections(): Promise<void> {
	try {
		nativeCollections.value = await migrationService.listCollections(resource.value)
	} catch (error) {
		showError(t('integration_davc', 'Failed to load local calendars/address books') + ': ' + (error as Error).message)
	}
}

async function startMigration(): Promise<void> {
	if (!remoteCollection.value || nativeCollection.value === null) {
		return
	}
	starting.value = true
	try {
		migration.value = await migrationService.start({
			sid: props.service.id,
			resource: resource.value,
			direction: direction.value,
			source: direction.value === 'inbound' ? remoteCollection.value : String(nativeCollection.value),
			target: direction.value === 'inbound' ? String(nativeCollection.value) : remoteCollection.value,
			overwrite: overwrite.value,
		})
		showSuccess(t('integration_davc', 'Migration started, it will run in the background'))
	} catch (error) {
		showError(t('integration_davc', 'Failed to start migration') + ': ' + (error as Error).message)
		await fetchStatus()
	} finally {
		starting.value = false
	}
}

async function cancelMigration(): Promise<void> {
	if (!migration.value) {
		return
	}
	try {
		migration.value = await migrationService.cancel(props.service.id)
	} catch (error) {
		showError(t('integration_davc', 'Failed to cancel migration')
			+ ': ' + (error as Error).message)
	}
}

async function dismissMigration(): Promise<void> {
	try {
		await migrationService.dismiss(props.service.id)
	} catch (error) {
		showError(t('integration_davc', 'Failed to dismiss migration') + ': ' + (error as Error).message)
		return
	}
	migration.value = null
}
</script>

<template>
	<NcDialog
		:open="true"
		:name="t('integration_davc', 'Migrate')"
		size="normal"
		@update:open="closeDialog()">
		<div class="davc-migration">
			<NcLoadingIcon v-if="loadingStatus" :size="32" />

			<div v-else-if="!migration" class="davc-migration__form">
				<div class="davc-migration__field">
					<label for="davc-migration-resource">{{ t('integration_davc', 'What to migrate') }}</label>
					<NcSelect
						v-model="resource"
						inputId="davc-migration-resource"
						:reduce="(option) => option.value"
						:options="resourceOptions"
						:clearable="false" />
				</div>
				<div class="davc-migration__field">
					<label for="davc-migration-direction">{{ t('integration_davc', 'Direction') }}</label>
					<NcSelect
						v-model="direction"
						inputId="davc-migration-direction"
						:reduce="(option) => option.value"
						:options="directionOptions"
						:clearable="false" />
				</div>
				<div class="davc-migration__field">
					<label for="davc-migration-remote">{{ t('integration_davc', 'External collection') }}</label>
					<NcSelect
						v-model="remoteCollection"
						inputId="davc-migration-remote"
						:reduce="(option) => option.id"
						label="label"
						:options="remoteCollections"
						:clearable="false" />
				</div>
				<div class="davc-migration__field">
					<label for="davc-migration-native">{{ t('integration_davc', 'Internal collection') }}</label>
					<NcSelect
						v-model="nativeCollection"
						inputId="davc-migration-native"
						:reduce="(option) => option.id"
						label="label"
						:options="nativeCollections"
						:clearable="false" />
				</div>
				<NcCheckboxRadioSwitch type="switch" :modelValue="overwrite" @update:modelValue="overwrite = $event">
					{{ t('integration_davc', 'Overwrite existing objects with a matching id instead of skipping them') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-else class="davc-migration__status">
				<p v-if="migration.status === 'running'" class="instruction-message">
					{{ t('integration_davc', 'This service only runs one migration at a time. It advances in the background roughly once per cron cycle, which can take several minutes — feel free to close this dialog and check back later.') }}
				</p>
				<p>
					{{ t('integration_davc', 'Status: {status}', { status: migration.status }) }}
				</p>
				<p>
					{{ t('integration_davc', 'As of last check: processed {offset} (created: {created}, updated: {updated}, skipped: {skipped}, errors: {errors})', {
						offset: migration.offset,
						created: migration.statistics.created,
						updated: migration.statistics.updated,
						skipped: migration.statistics.skipped,
						errors: migration.statistics.errors,
					}) }}
				</p>
				<p v-if="migration.status === 'failed' && migration.lastError" class="warning-message">
					{{ migration.lastError }}
				</p>
			</div>
		</div>
		<template #actions>
			<NcButton
				v-if="!loadingStatus && !migration"
				:disabled="starting || !remoteCollection || nativeCollection === null"
				variant="primary"
				@click="startMigration()">
				<template #icon>
					<CheckIcon />
				</template>
				{{ t('integration_davc', 'Start') }}
			</NcButton>
			<NcButton v-if="migration && migration.status === 'running'" @click="fetchStatus()">
				<template #icon>
					<RefreshIcon />
				</template>
				{{ t('integration_davc', 'Refresh') }}
			</NcButton>
			<NcButton v-if="migration && migration.status === 'running'" @click="cancelMigration()">
				<template #icon>
					<CloseIcon />
				</template>
				{{ t('integration_davc', 'Cancel') }}
			</NcButton>
			<NcButton v-if="migration && migration.status !== 'running'" @click="dismissMigration()">
				<template #icon>
					<CheckIcon />
				</template>
				{{ t('integration_davc', 'Dismiss') }}
			</NcButton>
			<NcButton @click="closeDialog()">
				<template #icon>
					<CloseIcon />
				</template>
				{{ t('integration_davc', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped lang="scss">
.davc-migration {
	&__form,
	&__status {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__field {
		display: flex;
		flex-direction: column;
		gap: 4px;

		label {
			font-weight: bold;
		}
	}

	.warning-message {
		color: var(--color-error);
	}
}
</style>
