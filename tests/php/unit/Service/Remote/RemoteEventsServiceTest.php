<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Remote;

use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Xml\Property\ResourceType;

class RemoteEventsServiceTest extends TestCase {
	private const COLLECTION = '/calendars/user/personal/';

	private RemoteClient&MockObject $client;

	private RemoteEventsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->client = $this->createMock(RemoteClient::class);
		$this->client->method('getCalendarHome')->willReturn('/calendars/user/');
		$this->service = new RemoteEventsService();
		$this->service->initialize($this->client);
	}

	public function testEntityListSkipsCollectionsAndKeepsResourcesWithEmptyResourceType(): void {
		$this->client->expects($this->once())
			->method('propFind')
			->with(self::COLLECTION, 1, $this->anything())
			->willReturn($this->members());

		$list = $this->service->entityList(self::COLLECTION, 'basic');

		$this->assertSame([self::COLLECTION . 'empty.ics', self::COLLECTION . 'absent.ics'], array_keys($list));
	}

	public function testEntityDeltaSkipsCollectionsAndKeepsResourcesWithEmptyResourceType(): void {
		$this->client->method('capabilities')->willReturnMap([
			['dav', ['sync-collection']],
			['allow', ['REPORT']],
		]);
		$this->client->expects($this->once())
			->method('report')
			->with(self::COLLECTION, RemoteClient::DAV_SYNC_COLLECTION, 0, $this->anything())
			->willReturn($this->members() + ['token' => 'sync-token-2']);

		$delta = $this->service->entityDelta(self::COLLECTION, 'sync-token-1');

		$this->assertSame('sync-token-2', $delta->signature);
		$this->assertSame([self::COLLECTION . 'empty.ics', self::COLLECTION . 'absent.ics'], $delta->modifications->getArrayCopy());
		$this->assertSame([], $delta->deletions->getArrayCopy());
	}

	private function members(): array {
		return [
			self::COLLECTION => [200 => [
				RemoteClient::DAV_RESOURCE_TYPE => new ResourceType([RemoteClient::DAV_COLLECTION]),
				RemoteClient::DAV_ETAG => '"collection"',
			]],
			self::COLLECTION . 'empty.ics' => [200 => [
				RemoteClient::DAV_RESOURCE_TYPE => new ResourceType([]),
				RemoteClient::DAV_ETAG => '"empty"',
			]],
			self::COLLECTION . 'absent.ics' => [200 => [
				RemoteClient::DAV_RESOURCE_TYPE => null,
				RemoteClient::DAV_ETAG => '"absent"',
			]],
		];
	}
}
