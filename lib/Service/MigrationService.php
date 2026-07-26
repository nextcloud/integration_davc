<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Models\Calendars\Entity as CalendarEntity;
use OCA\DAVC\Models\Contacts\Entity as ContactEntity;
use OCA\DAVC\Service\Native\NativeContactsService;
use OCA\DAVC\Service\Native\NativeEventsService;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCA\DAVC\Service\Remote\RemoteContactsService;
use OCA\DAVC\Service\Remote\RemoteEventsService;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Utile\UUID;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Throwable;

class MigrationService {

	public const RESOURCE_EVENT = 'event';
	public const RESOURCE_CONTACT = 'contact';

	public const DIRECTION_INBOUND = 'inbound';
	public const DIRECTION_OUTBOUND = 'outbound';

	public const STATUS_RUNNING = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	private const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];
	private const CHUNK_SIZE = 100;
	private const MAX_ERROR_MESSAGES = 20;

	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ITimeFactory $time,
		private readonly ServicesService $servicesService,
		private readonly RemoteFactory $remoteFactory,
		private readonly NativeEventsService $nativeEventsService,
		private readonly NativeContactsService $nativeContactsService,
	) {
	}

	/**
	 * build the initial state for a new migration
	 *
	 * @return array<string, mixed>
	 */
	public function start(
		string $uid,
		int $sid,
		string $resource,
		string $direction,
		string $source,
		string $target,
		bool $overwrite,
	): array {
		return [
			'uid' => $uid,
			'sid' => $sid,
			'resource' => $resource,
			'direction' => $direction,
			'source' => $source,
			'target' => $target,
			'overwrite' => $overwrite,
			'status' => self::STATUS_RUNNING,
			'offset' => 0,
			'statistics' => [
				'created' => 0,
				'updated' => 0,
				'skipped' => 0,
				'errors' => 0,
				'errorMessages' => [],
			],
			'startedAt' => time(),
			'updatedAt' => time(),
			'lastError' => null,
		];
	}

	/**
	 * process a migration based on the current state, returning the updated state
	 *
	 * @param array<string, mixed> $state
	 *
	 * @return array<string, mixed>
	 */
	public function process(array $state, int $budgetSeconds): array {
		if (in_array($state['status'], self::TERMINAL_STATUSES, true)) {
			return $state;
		}

		try {
			$service = $this->servicesService->fetchByUserIdAndServiceId($state['uid'], $state['sid']);
			if ($service === null) {
				throw new \RuntimeException('Service ' . $state['sid'] . ' no longer exists.');
			}

			$remoteClient = $this->remoteFactory->freshClient($service);

			$items = $this->fetchIdentifiers($state, $remoteClient);

			$startTime = $this->time->getTime();
			do {
				$state = match ($state['resource']) {
					self::RESOURCE_EVENT => $this->processEventsSlice($state, $items, $remoteClient),
					self::RESOURCE_CONTACT => $this->processContactsSlice($state, $items, $remoteClient),
					default => throw new \InvalidArgumentException('Unknown resource type ' . $state['resource']),
				};
			} while ($state['status'] === self::STATUS_RUNNING && ($this->time->getTime() - $startTime) < $budgetSeconds);
		} catch (Throwable $e) {
			$this->logger->error(Application::APP_TAG . ' - Migration for service ' . ($state['sid'] ?? '?') . ' failed: ' . $e->getMessage(), ['exception' => $e]);
			$state['status'] = self::STATUS_FAILED;
			$state['lastError'] = $e->getMessage();
		}

		$state['updatedAt'] = time();

		return $state;
	}

	/**
	 * fetch and sort the ordered list of identifiers to migrate
	 *
	 * @param array<string, mixed> $state
	 *
	 * @return array<string>
	 */
	private function fetchIdentifiers(array $state, RemoteClient $remoteClient): array {
		if ($state['direction'] === self::DIRECTION_INBOUND) {
			$remote = match ($state['resource']) {
				self::RESOURCE_EVENT => $this->remoteFactory->eventsService($remoteClient),
				self::RESOURCE_CONTACT => $this->remoteFactory->contactsService($remoteClient),
				default => throw new \InvalidArgumentException('Unknown resource type ' . $state['resource']),
			};
			$identifiers = array_keys($remote->entityList((string)$state['source'], 'basic'));
		} else {
			$native = match ($state['resource']) {
				self::RESOURCE_EVENT => $this->nativeEventsService,
				self::RESOURCE_CONTACT => $this->nativeContactsService,
				default => throw new \InvalidArgumentException('Unknown resource type ' . $state['resource']),
			};
			$identifiers = $native->entityListUris((int)$state['source']);
		}

		sort($identifiers);

		return $identifiers;
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string> $identifiers
	 *
	 * @return array<string, mixed>
	 */
	private function processEventsSlice(array $state, array $identifiers, RemoteClient $remoteClient): array {
		$remote = $this->remoteFactory->eventsService($remoteClient);

		return $state['direction'] === self::DIRECTION_INBOUND
			? $this->processEventsInbound($state, $identifiers, $remote)
			: $this->processEventsOutbound($state, $identifiers, $remote);
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string> $identifiers
	 *
	 * @return array<string, mixed>
	 */
	private function processContactsSlice(array $state, array $identifiers, RemoteClient $remoteClient): array {
		$remote = $this->remoteFactory->contactsService($remoteClient);

		return $state['direction'] === self::DIRECTION_INBOUND
			? $this->processContactsInbound($state, $identifiers, $remote)
			: $this->processContactsOutbound($state, $identifiers, $remote);
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string> $identifiers
	 *
	 * @return array<string, mixed>
	 */
	private function processEventsInbound(array $state, array $identifiers, RemoteEventsService $remote): array {
		$remoteCollectionId = (string)$state['source'];
		$targetCollectionId = (int)$state['target'];

		$offset = (int)$state['offset'];
		$chunk = array_slice($identifiers, $offset, self::CHUNK_SIZE);

		if (empty($chunk)) {
			$state['status'] = self::STATUS_COMPLETED;
			return $state;
		}

		foreach ($remote->entityFetchMultiple($remoteCollectionId, $chunk) as $entity) {
			try {
				$data = $entity->data;
				if ($data === null) {
					$this->recordError($state, 'Remote event had no calendar data.');
					continue;
				}

				$uid = $this->extractEventUid($data);
				$existing = $uid !== null ? $this->nativeEventsService->entityFindByUid($targetCollectionId, $state['uid'], $uid) : null;

				if ($existing !== null) {
					if ($state['overwrite']) {
						$this->nativeEventsService->entityModify($targetCollectionId, $existing['uri'], $data);
						$state['statistics']['updated']++;
					} else {
						$state['statistics']['skipped']++;
					}
					continue;
				}

				$created = $this->nativeEventsService->entityCreate($targetCollectionId, UUID::v4() . '.ics', $data);
				$state['statistics'][$created !== null ? 'created' : 'skipped']++;
			} catch (Throwable $e) {
				$this->recordError($state, $e->getMessage());
			}
		}

		$state['offset'] = $offset + count($chunk);
		if ($state['offset'] >= count($identifiers)) {
			$state['status'] = self::STATUS_COMPLETED;
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string> $identifiers
	 *
	 * @return array<string, mixed>
	 */
	private function processEventsOutbound(array $state, array $identifiers, RemoteEventsService $remote): array {
		$sourceCollectionId = (int)$state['source'];
		$remoteCollectionId = (string)$state['target'];

		$offset = (int)$state['offset'];
		$chunk = array_slice($identifiers, $offset, self::CHUNK_SIZE);

		if (empty($chunk)) {
			$state['status'] = self::STATUS_COMPLETED;
			return $state;
		}

		foreach ($this->nativeEventsService->entityFetchMultiple($sourceCollectionId, $chunk) as $entity) {
			try {
				$remoteEntity = new CalendarEntity();
				$remoteEntity->remoteCollectionId = $remoteCollectionId;
				$remoteEntity->remoteEntityId = $entity['uri'];
				$remoteEntity->data = $entity['data'];

				$result = $remote->entityCreate($remoteEntity);
				$state['statistics'][$result !== null ? 'created' : 'skipped']++;
			} catch (Throwable $e) {
				$this->recordError($state, $e->getMessage());
			}
		}

		$state['offset'] = $offset + count($chunk);
		if ($state['offset'] >= count($identifiers)) {
			$state['status'] = self::STATUS_COMPLETED;
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string> $identifiers
	 *
	 * @return array<string, mixed>
	 */
	private function processContactsInbound(array $state, array $identifiers, RemoteContactsService $remote): array {
		$remoteCollectionId = (string)$state['source'];
		$targetCollectionId = (int)$state['target'];

		$offset = (int)$state['offset'];
		$chunk = array_slice($identifiers, $offset, self::CHUNK_SIZE);

		if (empty($chunk)) {
			$state['status'] = self::STATUS_COMPLETED;
			return $state;
		}

		foreach ($remote->entityFetchMultiple($remoteCollectionId, $chunk) as $entity) {
			try {
				$data = $entity->data;
				if ($data === null) {
					$this->recordError($state, 'Remote contact had no vCard data.');
					continue;
				}

				$uid = $this->extractContactUid($data);
				$existing = $uid !== null ? $this->nativeContactsService->entityFindByUid($targetCollectionId, $uid) : null;

				if ($existing !== null) {
					if ($state['overwrite']) {
						$this->nativeContactsService->entityModify($targetCollectionId, $existing['uri'], $data);
						$state['statistics']['updated']++;
					} else {
						$state['statistics']['skipped']++;
					}
					continue;
				}

				$created = $this->nativeContactsService->entityCreate($targetCollectionId, UUID::v4() . '.vcf', $data);
				$state['statistics'][$created !== null ? 'created' : 'skipped']++;
			} catch (Throwable $e) {
				$this->recordError($state, $e->getMessage());
			}
		}

		$state['offset'] = $offset + count($chunk);
		if ($state['offset'] >= count($identifiers)) {
			$state['status'] = self::STATUS_COMPLETED;
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $state
	 * @param array<string> $identifiers
	 *
	 * @return array<string, mixed>
	 */
	private function processContactsOutbound(array $state, array $identifiers, RemoteContactsService $remote): array {
		$sourceCollectionId = (int)$state['source'];
		$remoteCollectionId = (string)$state['target'];

		$offset = (int)$state['offset'];
		$chunk = array_slice($identifiers, $offset, self::CHUNK_SIZE);

		if (empty($chunk)) {
			$state['status'] = self::STATUS_COMPLETED;
			return $state;
		}

		foreach ($this->nativeContactsService->entityFetchMultiple($sourceCollectionId, $chunk) as $entity) {
			try {
				$remoteEntity = new ContactEntity();
				$remoteEntity->remoteCollectionId = $remoteCollectionId;
				$remoteEntity->remoteEntityId = $entity['uri'];
				$remoteEntity->data = $entity['data'];

				$result = $remote->entityCreate($remoteEntity);
				$state['statistics'][$result !== null ? 'created' : 'skipped']++;
			} catch (Throwable $e) {
				$this->recordError($state, $e->getMessage());
			}
		}

		$state['offset'] = $offset + count($chunk);
		if ($state['offset'] >= count($identifiers)) {
			$state['status'] = self::STATUS_COMPLETED;
		}

		return $state;
	}

	private function extractEventUid(string $data): ?string {
		try {
			$vObject = Reader::read($data);
			if (!$vObject instanceof VCalendar) {
				return null;
			}

			return $vObject->getBaseComponent()?->UID?->getValue();
		} catch (Throwable) {
			return null;
		}
	}

	private function extractContactUid(string $data): ?string {
		try {
			$vObject = Reader::read($data);
			if (!$vObject instanceof VCard) {
				return null;
			}

			return $vObject->UID?->getValue();
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function recordError(array &$state, string $message): void {
		$state['statistics']['errors']++;
		if (count($state['statistics']['errorMessages']) < self::MAX_ERROR_MESSAGES) {
			$state['statistics']['errorMessages'][] = substr($message, 0, 200);
		}
		$state['lastError'] = $message;
	}

}
