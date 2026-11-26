<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Dav;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Constants;
use OCA\DAVC\Service\CoreService;
use OCA\DAVC\Service\Local\LocalFactory;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Service\ServicesService;
use OCA\DAVC\Store\Local\BaseStore;
use OCA\DAVC\Store\Local\CollectionEntity;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCA\DAVC\Utile\UUID;
use Psr\Container\ContainerInterface;
use Test\TestCase;

/**
 * Connects a service to the DAV server of the integration environment and removes it afterwards
 */
abstract class HarmonizationTestCase extends TestCase {

	protected ContainerInterface $container;
	protected CoreService $coreService;
	protected ServicesService $servicesService;
	protected RemoteFactory $remoteFactory;
	protected LocalFactory $localFactory;
	protected string $uid = 'tester';
	protected ServiceEntity $service;
	protected RemoteClient $remoteClient;
	private bool $initialized = false;

	protected function setUp(): void {
		parent::setUp();

		$davProtocol = $this->requiredEnv('DAV_PROTOCOL');
		$davHost = $this->requiredEnv('DAV_HOST');
		$davPort = (int)$this->requiredEnv('DAV_PORT');
		$davPath = $this->requiredEnv('DAV_PATH');
		$davUsername = $this->requiredEnv('DAV_USERNAME');
		$davPassword = $this->requiredEnv('DAV_PASSWORD');

		$this->container = (new Application())->getContainer();
		$this->coreService = $this->container->get(CoreService::class);
		$this->servicesService = $this->container->get(ServicesService::class);
		$this->remoteFactory = $this->container->get(RemoteFactory::class);
		$this->localFactory = $this->container->get(LocalFactory::class);
		$this->initialized = true;

		$this->cleanupServices();

		$this->service = $this->coreService->connectAccount($this->uid, [
			'label' => 'Harmonization DAV',
			'auth' => Constants::AUTHENTICATION_TYPE_BASIC,
			'bauth_id' => $davUsername,
			'bauth_secret' => $davPassword,
			'location_protocol' => $davProtocol,
			'location_host' => $davHost,
			'location_port' => $davPort,
			'location_path' => $davPath,
			'location_security' => false,
		]);
		$this->remoteClient = $this->remoteFactory->freshClient($this->service);
	}

	protected function tearDown(): void {
		if ($this->initialized) {
			$this->cleanupServices();
		}

		parent::tearDown();
	}

	/**
	 * Create a local collection the way a newly enabled collection is created, without synchronization state
	 */
	protected function createLocalCollection(BaseStore $store, string $remoteCollectionId): CollectionEntity {
		$collection = $store->collectionFresh();
		$collection->setUid($this->uid);
		$collection->setSid((string)$this->service->getId());
		$collection->setCcid($remoteCollectionId);
		$collection->setUuid(UUID::v4());
		$collection->setLabel('DavC: Harmonization');
		$collection->setColor('#0055aa');
		$collection->setVisible(true);
		return $store->collectionCreate($collection);
	}

	private function requiredEnv(string $key): string {
		$value = getenv($key);
		if ($value === false || $value === '') {
			self::markTestSkipped('DAV integration test environment is not configured.');
		}

		self::assertIsString($value);

		return $value;
	}

	private function cleanupServices(): void {
		foreach ($this->servicesService->fetchByUserId($this->uid) as $service) {
			$this->coreService->disconnectAccount($this->uid, (int)$service->getId());
		}
	}
}
