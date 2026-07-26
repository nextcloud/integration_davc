/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type MigrationResource = 'event' | 'contact'
export type MigrationDirection = 'inbound' | 'outbound'
export type MigrationStatus = 'running' | 'completed' | 'failed' | 'cancelled'

export interface MigrationStatistics {
	created: number
	updated: number
	skipped: number
	errors: number
	errorMessages: string[]
}

export interface MigrationState {
	uid: string
	sid: number
	resource: MigrationResource
	direction: MigrationDirection
	source: string
	target: string
	overwrite: boolean
	status: MigrationStatus
	offset: number
	statistics: MigrationStatistics
	startedAt: number
	updatedAt: number
	lastError: string | null
}

export interface NativeCollection {
	id: number
	label: string | null
	readOnly: boolean
}
