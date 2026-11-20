<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Providers\DAV\Calendar\Hybrid;

use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use OCA\DAV\CalDAV\Integration\ICalendarProvider as ICalendarProvider2;
use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Models\Calendars\Collection;
use OCA\DAVC\Service\Local\LocalEventsService;
use OCA\DAVC\Service\Local\LocalFactory;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\ServicesStore;
use OCP\Calendar\ICalendarProvider as ICalendarProvider1;

/**
 * @psalm-api
 */
class Provider implements ICalendarProvider1, ICalendarProvider2 {
	protected array $_CollectionCache = [];
	protected LocalEventsService $localService;

	public function __construct(
		private readonly ServicesStore $servicesStore,
		private readonly LocalFactory $localFactory,
		private readonly RemoteFactory $remoteFactory,
	) {
		$this->localService = $this->localFactory->eventsService();
	}

	protected function extractUserId(string $principalUri): string {
		return substr($principalUri, 17);
	}

	/**
	 * @inheritDoc
	 */
	public function getAppId(): string {
		return Application::APP_ID;
	}

	/**
	 * @inheritDoc
	 */
	public function getCalendars(string $principalUri, array $calendarUris = []): array {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function fetchAllForCalendarHome(string $principalUri): array {
		$userId = $this->extractUserId($principalUri);
		$listFilter = $this->localService->collectionListFilter();
		$listFilter->condition('uid', $userId);
		$collections = $this->localService->collectionList($listFilter);
		$list = [];
		foreach ($collections as $entry) {
			$collection = $this->collectionFromModel($entry);
			$this->cacheStoreCollection($entry->userId, $entry->uuid, $collection);
			$list[] = $collection;
		}
		return $list;
	}

	/**
	 * @inheritDoc
	 */
	public function hasCalendarInCalendarHome(string $principalUri, string $calendarUri): bool {
		$userId = $this->extractUserId($principalUri);
		$collection = $this->cacheRetrieveCollection($userId, $calendarUri);
		if ($collection) {
			return true;
		}
		$listFilter = $this->localService->collectionListFilter();
		$listFilter->condition('uid', $userId);
		$listFilter->condition('uuid', $calendarUri);
		$collections = $this->localService->collectionList($listFilter);
		if ($collections !== []) {
			$collection = $this->collectionFromModel(reset($collections));
			$this->cacheStoreCollection($userId, $calendarUri, $collection);
			return true;
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getCalendarInCalendarHome(string $principalUri, string $calendarUri): ?ExternalCalendar {
		$userId = $this->extractUserId($principalUri);
		$collection = $this->cacheRetrieveCollection($userId, $calendarUri);
		if ($collection) {
			return $collection;
		}
		$listFilter = $this->localService->collectionListFilter();
		$listFilter->condition('uid', $userId);
		$listFilter->condition('uuid', $calendarUri);
		$collections = $this->localService->collectionList($listFilter);
		if (count($collections) > 0) {
			$collection = $this->collectionFromModel(reset($collections));
			$this->cacheStoreCollection($userId, $calendarUri, $collection);
			return $collection;
		}
		return null;
	}

	protected function cacheRetrieveCollection(string $uid, string $cid): ?EventCollection {
		if (isset($this->_CollectionCache[$uid][$cid])) {
			return $this->_CollectionCache[$uid][$cid];
		}
		return null;
	}

	protected function cacheStoreCollection(string $uid, string $cid, EventCollection $collection): void {
		if (!isset($this->_CollectionCache[$uid])) {
			$this->_CollectionCache[$uid] = [];
		}
		$this->_CollectionCache[$uid][$cid] = $collection;
	}

	protected function collectionFromModel(Collection $entity): EventCollection {
		return new EventCollection($this->servicesStore, $this->localService, $this->remoteFactory, $entity);
	}

}
