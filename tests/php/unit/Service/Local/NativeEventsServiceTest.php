<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Local;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\DAV\Sharing\Plugin as SharingPlugin;
use OCA\DAVC\Service\Native\NativeEventsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\BadRequest;

class NativeEventsServiceTest extends TestCase {
	private CalDavBackend&MockObject $backend;

	private IDBConnection&MockObject $db;

	private NativeEventsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->backend = $this->createMock(CalDavBackend::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new NativeEventsService($this->backend, $this->db);
	}

	public function testCollectionListConvertsRows(): void {
		$this->backend->expects($this->once())
			->method('getCalendarsForUser')
			->with('principals/users/user-1')
			->willReturn([
				[
					'id' => 5,
					'uri' => 'personal',
					'principaluri' => 'principals/users/user-1',
					'{DAV:}displayname' => 'Personal',
					'{' . SharingPlugin::NS_OWNCLOUD . '}read-only' => true,
				],
			]);

		$collections = $this->service->collectionList('user-1');

		$this->assertCount(1, $collections);
		$this->assertSame(5, $collections[0]['id']);
		$this->assertSame('personal', $collections[0]['uri']);
		$this->assertSame('Personal', $collections[0]['label']);
		$this->assertTrue($collections[0]['readOnly']);
	}

	public function testCollectionFetchReturnsNullWhenMissing(): void {
		$this->backend->expects($this->once())
			->method('getCalendarById')
			->with(9)
			->willReturn(null);

		$this->assertNull($this->service->collectionFetch(9));
	}

	public function testEntityListUrisQueriesCalendarObjectsTableDirectly(): void {
		$capturedParams = [];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $col, string $val) => $col . ' = ' . $val);
		$expr->method('isNull')->willReturnCallback(static fn (string $col) => $col . ' IS NULL');

		$result = $this->createMock(IResult::class);
		$result->expects($this->once())
			->method('fetchFirstColumn')
			->willReturn(['event-1.ics', 'event-2.ics']);

		$query = $this->createMock(IQueryBuilder::class);
		$query->expects($this->once())->method('select')->with('uri')->willReturnSelf();
		$query->expects($this->once())->method('from')->with('calendarobjects')->willReturnSelf();
		$query->method('expr')->willReturn($expr);
		$query->method('createNamedParameter')->willReturnCallback(static function ($value) use (&$capturedParams) {
			$capturedParams[] = $value;
			return ':param' . count($capturedParams);
		});
		$query->expects($this->once())
			->method('where')
			->with('calendarid = :param1')
			->willReturnSelf();
		$query->expects($this->exactly(2))
			->method('andWhere')
			->willReturnSelf();
		$query->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($query);

		$this->assertSame(['event-1.ics', 'event-2.ics'], $this->service->entityListUris(5));
		$this->assertSame([5, CalDavBackend::CALENDAR_TYPE_CALENDAR], $capturedParams);
	}

	public function testEntityFetchMultipleConvertsRows(): void {
		$this->backend->expects($this->once())
			->method('getMultipleCalendarObjects')
			->with(5, ['event-1.ics'])
			->willReturn([
				[
					'uri' => 'event-1.ics',
					'uid' => 'uid-1',
					'etag' => '"abc"',
					'calendardata' => 'BEGIN:VCALENDAR...',
				],
			]);

		$entities = $this->service->entityFetchMultiple(5, ['event-1.ics']);

		$this->assertCount(1, $entities);
		$this->assertSame('event-1.ics', $entities[0]['uri']);
		$this->assertSame('BEGIN:VCALENDAR...', $entities[0]['data']);
	}

	public function testEntityFindByUidReturnsNullWhenCollectionMissing(): void {
		$this->backend->expects($this->once())
			->method('getCalendarById')
			->with(5)
			->willReturn(null);
		$this->backend->expects($this->never())
			->method('getCalendarObjectByUID');

		$this->assertNull($this->service->entityFindByUid(5, 'user-1', 'uid-1'));
	}

	public function testEntityFindByUidReturnsNullWhenNotFound(): void {
		$this->backend->method('getCalendarById')->willReturn([
			'id' => 5,
			'uri' => 'personal',
			'principaluri' => 'principals/users/user-1',
		]);
		$this->backend->expects($this->once())
			->method('getCalendarObjectByUID')
			->with('principals/users/user-1', 'uid-1', 'personal')
			->willReturn(null);

		$this->assertNull($this->service->entityFindByUid(5, 'user-1', 'uid-1'));
	}

	public function testEntityFindByUidResolvesExistingObject(): void {
		$this->backend->method('getCalendarById')->willReturn([
			'id' => 5,
			'uri' => 'personal',
			'principaluri' => 'principals/users/user-1',
		]);
		$this->backend->method('getCalendarObjectByUID')
			->with('principals/users/user-1', 'uid-1', 'personal')
			->willReturn('personal/event-1.ics');
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(5, 'event-1.ics')
			->willReturn([
				'uri' => 'event-1.ics',
				'uid' => 'uid-1',
				'etag' => '"abc"',
				'calendardata' => 'BEGIN:VCALENDAR...',
			]);

		$entity = $this->service->entityFindByUid(5, 'user-1', 'uid-1');

		$this->assertSame('event-1.ics', $entity['uri']);
	}

	public function testEntityCreateReturnsNullOnUidConflict(): void {
		$this->backend->expects($this->once())
			->method('createCalendarObject')
			->with(5, 'event-1.ics', 'BEGIN:VCALENDAR...')
			->willThrowException(new BadRequest('duplicate uid'));
		$this->backend->expects($this->never())
			->method('getCalendarObject');

		$this->assertNull($this->service->entityCreate(5, 'event-1.ics', 'BEGIN:VCALENDAR...'));
	}

	public function testEntityCreateReturnsCreatedEntity(): void {
		$this->backend->expects($this->once())
			->method('createCalendarObject')
			->with(5, 'event-1.ics', 'BEGIN:VCALENDAR...');
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(5, 'event-1.ics')
			->willReturn([
				'uri' => 'event-1.ics',
				'uid' => 'uid-1',
				'etag' => '"abc"',
				'calendardata' => 'BEGIN:VCALENDAR...',
			]);

		$entity = $this->service->entityCreate(5, 'event-1.ics', 'BEGIN:VCALENDAR...');

		$this->assertSame('event-1.ics', $entity['uri']);
	}

	public function testEntityModifyReturnsUpdatedEntity(): void {
		$this->backend->expects($this->once())
			->method('updateCalendarObject')
			->with(5, 'event-1.ics', 'BEGIN:VCALENDAR...');
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(5, 'event-1.ics')
			->willReturn([
				'uri' => 'event-1.ics',
				'uid' => 'uid-1',
				'etag' => '"def"',
				'calendardata' => 'BEGIN:VCALENDAR...',
			]);

		$entity = $this->service->entityModify(5, 'event-1.ics', 'BEGIN:VCALENDAR...');

		$this->assertSame('BEGIN:VCALENDAR...', $entity['data']);
	}

	public function testEntityDeleteDelegatesToBackend(): void {
		$this->backend->expects($this->once())
			->method('deleteCalendarObject')
			->with(5, 'event-1.ics');

		$this->assertTrue($this->service->entityDelete(5, 'event-1.ics'));
	}
}
