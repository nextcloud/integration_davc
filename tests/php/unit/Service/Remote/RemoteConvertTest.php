<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Remote;

use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteConvert;
use PHPUnit\Framework\TestCase;

class RemoteConvertTest extends TestCase {

	public function testExtractSupportedComponentsReturnsComponentNames(): void {
		$value = [
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => ['name' => 'VEVENT']],
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => ['name' => 'VTODO']],
		];

		$this->assertSame(['VEVENT', 'VTODO'], RemoteConvert::extractSupportedComponents($value));
	}

	public function testExtractSupportedComponentsReturnsNullWhenPropertyAbsent(): void {
		$this->assertNull(RemoteConvert::extractSupportedComponents(null));
		$this->assertNull(RemoteConvert::extractSupportedComponents('VEVENT'));
	}

	public function testExtractSupportedComponentsReturnsEmptyListForEmptySet(): void {
		$this->assertSame([], RemoteConvert::extractSupportedComponents([]));
	}

	public function testExtractSupportedComponentsIgnoresMalformedEntries(): void {
		$value = [
			'VEVENT',
			['name' => '{DAV:}href', 'value' => '/calendars/user/', 'attributes' => []],
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => []],
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => ['name' => '']],
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => ['name' => 'VJOURNAL']],
		];

		$this->assertSame(['VJOURNAL'], RemoteConvert::extractSupportedComponents($value));
	}

	public function testExtractSupportedComponentsRemovesDuplicates(): void {
		$value = [
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => ['name' => 'VEVENT']],
			['name' => RemoteClient::CALDAV_COMPONENT, 'value' => null, 'attributes' => ['name' => 'VEVENT']],
		];

		$this->assertSame(['VEVENT'], RemoteConvert::extractSupportedComponents($value));
	}

	private function buildAce(string $principalHref, array $privilegeNames): array {
		return [
			'name' => '{DAV:}ace',
			'value' => [
				[
					'name' => '{DAV:}principal',
					'value' => [
						['name' => RemoteClient::DAV_HREF, 'value' => $principalHref, 'attributes' => []],
					],
					'attributes' => [],
				],
				[
					'name' => '{DAV:}grant',
					'value' => array_map(
						static fn (string $name) => [
							'name' => '{DAV:}privilege',
							'value' => [
								['name' => $name, 'value' => null, 'attributes' => []],
							],
							'attributes' => [],
						],
						$privilegeNames,
					),
					'attributes' => [],
				],
			],
			'attributes' => [],
		];
	}

	public function testExtractPermissionsReturnsPrivilegesKeyedByPrincipal(): void {
		$acl = [
			$this->buildAce('/principals/users/3', ['{DAV:}read', '{DAV:}write']),
			$this->buildAce('/principals/users/55', ['{DAV:}read']),
		];

		$this->assertSame(
			[
				'/principals/users/3' => ['{DAV:}read', '{DAV:}write'],
				'/principals/users/55' => ['{DAV:}read'],
			],
			RemoteConvert::extractPermissions($acl),
		);
	}

	public function testExtractPermissionsNormalizesAbsolutePrincipalHrefToPath(): void {
		$acl = [
			$this->buildAce('https://dav.example.org/principals/users/3', ['{DAV:}read']),
		];

		$this->assertSame(
			['/principals/users/3' => ['{DAV:}read']],
			RemoteConvert::extractPermissions($acl),
		);
	}

	public function testExtractPermissionsIgnoresMalformedEntries(): void {
		$acl = [
			'not-an-array',
			['name' => '{DAV:}ace', 'value' => 'not-an-array', 'attributes' => []],
			['name' => '{DAV:}other', 'value' => [], 'attributes' => []],
		];

		$this->assertSame([], RemoteConvert::extractPermissions($acl));
	}

	public function testExtractPrivilegeNamesParsesCurrentUserPrivilegeSetShape(): void {
		// {DAV:}current-user-privilege-set carries a flat list of {DAV:}privilege
		// elements, the same shape as a {DAV:}grant element inside an {DAV:}ace
		$currentUserPrivilegeSet = [
			['name' => '{DAV:}privilege', 'value' => [['name' => '{DAV:}read', 'value' => null, 'attributes' => []]], 'attributes' => []],
			['name' => '{DAV:}privilege', 'value' => [['name' => '{DAV:}write', 'value' => null, 'attributes' => []]], 'attributes' => []],
		];

		$this->assertSame(
			['{DAV:}read', '{DAV:}write'],
			RemoteConvert::extractPrivilegeNames($currentUserPrivilegeSet),
		);
	}

}
