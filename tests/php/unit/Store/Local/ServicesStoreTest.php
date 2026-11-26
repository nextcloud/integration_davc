<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Store\Local;

use OCA\DAVC\Store\Local\ServiceEntity;
use OCA\DAVC\Store\Local\ServicesStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ServicesStoreTest extends TestCase {
	private IDBConnection&MockObject $db;
	private ICrypto&MockObject $crypto;
	private LoggerInterface&MockObject $logger;
	private ServicesStore $store;
	/** @var array<string, mixed> values assigned to columns */
	private array $assigned = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->crypto->method('encrypt')->willReturnCallback(fn (string $value): string => 'enc:' . $value);
		$this->crypto->method('decrypt')->willReturnCallback(function (string $value): string {
			if (!str_starts_with($value, 'enc:')) {
				throw new \Exception('HMAC does not match.');
			}
			return substr($value, 4);
		});

		$this->store = new ServicesStore($this->db, $this->crypto, $this->logger);
	}

	/**
	 * Prepare the query builder used by the store, executing it as a query yields the given rows
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function prepareBuilder(array $rows = []): void {
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere', 'insert', 'update'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnArgument(0);
		$assign = function (string $column, mixed $value) use ($qb): IQueryBuilder {
			$this->assigned[$column] = $value;
			return $qb;
		};
		$qb->method('setValue')->willReturnCallback($assign);
		$qb->method('set')->willReturnCallback($assign);
		$qb->method('getLastInsertId')->willReturn(1);

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	public function testCreateEncryptsCredentials(): void {
		$this->prepareBuilder();
		$service = new ServiceEntity();
		$service->setLabel('Example');
		$service->setBauthSecret('secret');
		$service->setOauthAccessToken('');

		$this->store->create($service);

		$this->assertSame('enc:secret', $this->assigned['bauth_secret']);
		$this->assertSame('', $this->assigned['oauth_access_token']);
		$this->assertSame('Example', $this->assigned['label']);
		$this->assertSame('secret', $service->getBauthSecret());
	}

	public function testModifyEncryptsCredentials(): void {
		$this->prepareBuilder();
		$service = new ServiceEntity();
		$service->setId(1);
		$service->resetUpdatedFields();
		$service->setOauthRefreshToken('refresh-token');

		$this->store->modify('user1', $service);

		$this->assertSame(['oauth_refresh_token' => 'enc:refresh-token'], $this->assigned);
	}

	public function testFetchDecryptsCredentials(): void {
		$this->prepareBuilder([
			['id' => 1, 'label' => 'Example', 'bauth_secret' => 'enc:secret', 'oauth_access_token' => null, 'oauth_refresh_token' => ''],
		]);

		$service = $this->store->fetch(1);

		$this->assertSame('secret', $service->getBauthSecret());
		$this->assertNull($service->getOauthAccessToken());
		$this->assertSame('', $service->getOauthRefreshToken());
	}

	public function testFetchDiscardsCredentialsThatCannotBeDecrypted(): void {
		$this->prepareBuilder([
			['id' => 1, 'label' => 'Example', 'bauth_secret' => 'corrupt', 'oauth_access_token' => null, 'oauth_refresh_token' => null],
		]);
		$this->logger->expects($this->once())->method('warning');

		$service = $this->store->fetch(1);

		$this->assertSame('Example', $service->getLabel());
		$this->assertNull($service->getBauthSecret());
	}

}
