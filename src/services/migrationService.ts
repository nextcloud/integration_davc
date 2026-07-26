/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MigrationDirection, MigrationResource, MigrationState, NativeCollection } from '../types/Migration.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export interface StartMigrationRequest {
	sid: string
	resource: MigrationResource
	direction: MigrationDirection
	source: string
	target: string
	overwrite: boolean
}

/**
 * Extract the raw server response body from a failed request
 */
function getErrorResponseText(error: unknown): string {
	if (typeof error !== 'object' || error === null || !('response' in error)) {
		return ''
	}

	const { response } = error as { response?: { request?: { responseText?: string } } }
	return response?.request?.responseText ?? ''
}

async function request<T>(action: () => Promise<T>): Promise<T> {
	try {
		return await action()
	} catch (error: unknown) {
		throw new Error(getErrorResponseText(error), { cause: error })
	}
}

export const migrationService = {
	fetchStatus(sid: string): Promise<MigrationState | null> {
		return request(async () => {
			const uri = generateUrl('/apps/integration_davc/migration/status')
			const response = await axios.post(uri, { sid })
			return response.data
		})
	},

	listCollections(resource: MigrationResource): Promise<NativeCollection[]> {
		return request(async () => {
			const uri = generateUrl('/apps/integration_davc/migration/native/collections')
			const response = await axios.post(uri, { resource })
			return response.data
		})
	},

	start(payload: StartMigrationRequest): Promise<MigrationState> {
		return request(async () => {
			const uri = generateUrl('/apps/integration_davc/migration/start')
			const response = await axios.post(uri, payload)
			return response.data
		})
	},

	cancel(sid: string): Promise<MigrationState> {
		return request(async () => {
			const uri = generateUrl('/apps/integration_davc/migration/cancel')
			const response = await axios.post(uri, { sid })
			return response.data
		})
	},

	dismiss(sid: string): Promise<void> {
		return request(async () => {
			const uri = generateUrl('/apps/integration_davc/migration/dismiss')
			await axios.post(uri, { sid })
		})
	},
}

export default migrationService
