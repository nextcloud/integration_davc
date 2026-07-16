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

}
