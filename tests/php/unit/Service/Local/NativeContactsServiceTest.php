<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Local;

use OCA\DAV\CardDAV\CardDavBackend;
use OCA\DAV\DAV\Sharing\Plugin as SharingPlugin;
use OCA\DAVC\Service\Native\NativeContactsService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\BadRequest;

class NativeContactsServiceTest extends TestCase {
	private CardDavBackend&MockObject $backend;

	private IDBConnection&MockObject $db;

	private NativeContactsService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->backend = $this->createMock(CardDavBackend::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new NativeContactsService($this->backend, $this->db);
	}

	public function testCollectionListConvertsRows(): void {
		$this->backend->expects($this->once())
			->method('getAddressBooksForUser')
			->with('principals/users/user-1')
			->willReturn([
				[
					'id' => 3,
					'uri' => 'contacts',
					'principaluri' => 'principals/users/user-1',
					'{DAV:}displayname' => 'Contacts',
					'{' . SharingPlugin::NS_OWNCLOUD . '}read-only' => false,
				],
			]);

		$collections = $this->service->collectionList('user-1');

		$this->assertCount(1, $collections);
		$this->assertSame(3, $collections[0]['id']);
		$this->assertSame('contacts', $collections[0]['uri']);
		$this->assertSame('Contacts', $collections[0]['label']);
		$this->assertFalse($collections[0]['readOnly']);
	}

	public function testCollectionFetchReturnsNullWhenMissing(): void {
		$this->backend->expects($this->once())
			->method('getAddressBookById')
			->with(9)
			->willReturn(null);

		$this->assertNull($this->service->collectionFetch(9));
	}

	public function testEntityListUrisQueriesCardsTableDirectly(): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->expects($this->once())
			->method('eq')
			->with('addressbookid', ':addressbookid')
			->willReturn('addressbookid = :addressbookid');

		$result = $this->createMock(IResult::class);
		$result->expects($this->once())
			->method('fetchFirstColumn')
			->willReturn(['contact-1.vcf', 'contact-2.vcf']);

		$query = $this->createMock(IQueryBuilder::class);
		$query->expects($this->once())->method('select')->with('uri')->willReturnSelf();
		$query->expects($this->once())->method('from')->with('cards')->willReturnSelf();
		$query->method('expr')->willReturn($expr);
		$query->expects($this->once())
			->method('createNamedParameter')
			->with(3)
			->willReturn(':addressbookid');
		$query->expects($this->once())
			->method('where')
			->with('addressbookid = :addressbookid')
			->willReturnSelf();
		$query->expects($this->once())
			->method('executeQuery')
			->willReturn($result);

		$this->db->expects($this->once())
			->method('getQueryBuilder')
			->willReturn($query);

		$this->assertSame(['contact-1.vcf', 'contact-2.vcf'], $this->service->entityListUris(3));
	}

	public function testEntityFetchMultipleConvertsRows(): void {
		$this->backend->expects($this->once())
			->method('getMultipleCards')
			->with(3, ['contact-1.vcf'])
			->willReturn([
				[
					'uri' => 'contact-1.vcf',
					'uid' => 'uid-1',
					'etag' => '"abc"',
					'carddata' => 'BEGIN:VCARD...',
				],
			]);

		$entities = $this->service->entityFetchMultiple(3, ['contact-1.vcf']);

		$this->assertCount(1, $entities);
		$this->assertSame('contact-1.vcf', $entities[0]['uri']);
		$this->assertSame('BEGIN:VCARD...', $entities[0]['data']);
	}

	public function testEntityFindByUidReturnsNullWhenNotFound(): void {
		$this->backend->expects($this->once())
			->method('getCardByUid')
			->with(3, 'uid-1')
			->willReturn(null);

		$this->assertNull($this->service->entityFindByUid(3, 'uid-1'));
	}

	public function testEntityFindByUidResolvesExistingCard(): void {
		$this->backend->expects($this->once())
			->method('getCardByUid')
			->with(3, 'uid-1')
			->willReturn([
				'uri' => 'contact-1.vcf',
				'uid' => 'uid-1',
				'etag' => '"abc"',
				'carddata' => 'BEGIN:VCARD...',
			]);

		$entity = $this->service->entityFindByUid(3, 'uid-1');

		$this->assertSame('contact-1.vcf', $entity['uri']);
	}

	public function testEntityCreateReturnsNullOnUidConflict(): void {
		$this->backend->expects($this->once())
			->method('createCard')
			->with(3, 'contact-1.vcf', 'BEGIN:VCARD...')
			->willThrowException(new BadRequest('duplicate uid'));
		$this->backend->expects($this->never())
			->method('getCard');

		$this->assertNull($this->service->entityCreate(3, 'contact-1.vcf', 'BEGIN:VCARD...'));
	}

	public function testEntityCreateReturnsCreatedEntity(): void {
		$this->backend->expects($this->once())
			->method('createCard')
			->with(3, 'contact-1.vcf', 'BEGIN:VCARD...');
		$this->backend->expects($this->once())
			->method('getCard')
			->with(3, 'contact-1.vcf')
			->willReturn([
				'uri' => 'contact-1.vcf',
				'uid' => 'uid-1',
				'etag' => '"abc"',
				'carddata' => 'BEGIN:VCARD...',
			]);

		$entity = $this->service->entityCreate(3, 'contact-1.vcf', 'BEGIN:VCARD...');

		$this->assertSame('contact-1.vcf', $entity['uri']);
	}

	public function testEntityCreateReturnsNullWhenCardMissingAfterCreate(): void {
		$this->backend->method('createCard');
		$this->backend->method('getCard')->willReturn(false);

		$this->assertNull($this->service->entityCreate(3, 'contact-1.vcf', 'BEGIN:VCARD...'));
	}

	public function testEntityModifyReturnsUpdatedEntity(): void {
		$this->backend->expects($this->once())
			->method('updateCard')
			->with(3, 'contact-1.vcf', 'BEGIN:VCARD...');
		$this->backend->expects($this->once())
			->method('getCard')
			->with(3, 'contact-1.vcf')
			->willReturn([
				'uri' => 'contact-1.vcf',
				'uid' => 'uid-1',
				'etag' => '"def"',
				'carddata' => 'BEGIN:VCARD...',
			]);

		$entity = $this->service->entityModify(3, 'contact-1.vcf', 'BEGIN:VCARD...');

		$this->assertSame('BEGIN:VCARD...', $entity['data']);
	}

	public function testEntityDeleteDelegatesToBackend(): void {
		$this->backend->expects($this->once())
			->method('deleteCard')
			->with(3, 'contact-1.vcf')
			->willReturn(1);

		$this->assertTrue($this->service->entityDelete(3, 'contact-1.vcf'));
	}
}
