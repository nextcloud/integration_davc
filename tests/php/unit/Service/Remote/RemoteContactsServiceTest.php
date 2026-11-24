<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Remote;

use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteContactsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Xml\Property\ResourceType;

class RemoteContactsServiceTest extends TestCase {
	private const COLLECTION = '/addressbooks/user/contacts/';

	private RemoteClient&MockObject $client;

	private RemoteContactsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->client = $this->createMock(RemoteClient::class);
		$this->client->method('getAddressbookHome')->willReturn('/addressbooks/user/');
		$this->service = new RemoteContactsService();
		$this->service->initialize($this->client);
	}

	public function testEntityListSkipsCollectionsAndKeepsResourcesWithEmptyResourceType(): void {
		$this->client->expects($this->once())
			->method('propFind')
			->with(self::COLLECTION, 1, $this->anything())
			->willReturn($this->members());

		$list = $this->service->entityList(self::COLLECTION, 'basic');

		$this->assertSame([self::COLLECTION . 'empty.vcf', self::COLLECTION . 'absent.vcf'], array_keys($list));
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
		$this->assertSame([self::COLLECTION . 'empty.vcf', self::COLLECTION . 'absent.vcf'], $delta->modifications->getArrayCopy());
		$this->assertSame([], $delta->deletions->getArrayCopy());
	}

	public function testEntityDeltaClassifiesUnsupportedPropertiesAndDeletions(): void {
		$this->client->method('capabilities')->willReturnMap([
			['dav', ['sync-collection']],
			['allow', ['REPORT']],
		]);
		$this->client->method('report')->willReturn([
			self::COLLECTION . 'changed.vcf' => [
				200 => [RemoteClient::DAV_ETAG => '"changed"'],
				404 => [RemoteClient::DAV_RESOURCE_TYPE => null],
			],
			self::COLLECTION . 'deleted-status.vcf' => [],
			self::COLLECTION . 'deleted-props.vcf' => [
				404 => [RemoteClient::DAV_ETAG => null],
			],
			'token' => 'sync-token-2',
		]);

		$delta = $this->service->entityDelta(self::COLLECTION, 'sync-token-1');

		$this->assertSame([self::COLLECTION . 'changed.vcf'], $delta->modifications->getArrayCopy());
		$this->assertSame([self::COLLECTION . 'deleted-status.vcf', self::COLLECTION . 'deleted-props.vcf'], $delta->deletions->getArrayCopy());
	}

	private function members(): array {
		return [
			self::COLLECTION => [200 => [
				RemoteClient::DAV_RESOURCE_TYPE => new ResourceType([RemoteClient::DAV_COLLECTION]),
				RemoteClient::DAV_ETAG => '"collection"',
			]],
			self::COLLECTION . 'empty.vcf' => [200 => [
				RemoteClient::DAV_RESOURCE_TYPE => new ResourceType([]),
				RemoteClient::DAV_ETAG => '"empty"',
			]],
			self::COLLECTION . 'absent.vcf' => [200 => [
				RemoteClient::DAV_RESOURCE_TYPE => null,
				RemoteClient::DAV_ETAG => '"absent"',
			]],
		];
	}
}
