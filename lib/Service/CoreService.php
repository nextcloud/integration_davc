<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service;

use OCA\DAVC\Constants;
use OCA\DAVC\Service\Local\LocalFactory;
use OCA\DAVC\Service\Remote\RemoteFactory;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/*
use OCA\DAVC\Tasks\HarmonizationLauncher;
*/

class CoreService {
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly IJobList $TaskService,
		private readonly ConfigurationService $ConfigurationService,
		private readonly ServicesService $ServicesService,
		private readonly ServicesTemplateService $ServicesTemplateService,
		private readonly RemoteFactory $remoteFactory,
		private readonly LocalFactory $localFactory,
	) {
	}

	/**
	 * locates connection point using users login details
	 *
	 * @param string $uid user id
	 * @param string $account_bauth_id account username
	 * @param string $account_bauth_secret account secret
	 *
	 * @return (mixed|string)[]|null
	 *
	 * @psalm-return array{location_host: mixed|string, location_path: '.well-known/caldav'|mixed,...}|null
	 */
	public function locateAccount(array $configuration): ?array {
		$dnsTarget = null;
		$dnsPort = null;

		// determine account and host from identity
		$identity = $configuration['bauth_id'] ?? $configuration['oauth_id'];
		if (strpos($identity, '@') === false) {
			return null;
		}
		[$identityAccount, $identityDomain] = explode('@', $identity);
		// find template for identity domain
		$template = $this->ServicesTemplateService->findByDomain($identityDomain);
		if (isset($template[0]['connection'])) {
			$settings = json_decode($template[0]['connection'], true, 512, JSON_THROW_ON_ERROR);
			foreach ($settings as $property => $value) {
				$configuration[$property] = $value;
			}
		}
		// find dns service records
		if (empty($configuration['location_host'])) {
			// attempt to locate service host and port using dns srv records
			// example: _caldavs._tcp.example.com. IN SRV 0 5 443 dav.example.com.
			$dnsService = '_caldavs._tcp.' . $identityDomain;
			$dns = dns_get_record($dnsService, DNS_SRV);
			if (empty($dns)) {
				$dnsService = '_caldav._tcp.' . $identityDomain;
				$dns = dns_get_record($dnsService, DNS_SRV);
			}
			if (empty($dns)) {
				$dnsService = '_carddavs._tcp.' . $identityDomain;
				$dns = dns_get_record($dnsService, DNS_SRV);
			}
			if (empty($dns)) {
				$dnsService = '_carddav._tcp.' . $identityDomain;
				$dns = dns_get_record($dnsService, DNS_SRV);
			}
			if (!empty($dns) && ($dns[0]['type'] ?? null) === 'SRV') {
				$dnsTarget = $dns[0]['target'];
				$dnsPort = $dns[0]['port'];
				$configuration['location_host'] = $dnsTarget;
				$configuration['location_port'] = $dnsPort;
				// attempt to locate service path using dns txt records
				// example: _caldavs._tcp.example.com. IN TXT "path=/dav/"
				if (empty($configuration['location_path'])) {
					$dnsTxt = dns_get_record($dnsService, DNS_TXT);
					foreach (is_array($dnsTxt) ? $dnsTxt : [] as $record) {
						if (($record['type'] ?? null) !== 'TXT') {
							continue;
						}
						if (preg_match('/(?:^|\s)path=(\S+)/', $record['txt'] ?? '', $matches) === 1) {
							$configuration['location_path'] = $matches[1];
							break;
						}
					}
				}
			}
		}
		// find template for dns service target
		if ($dnsTarget !== null && $dnsTarget !== '') {
			$template = $this->ServicesTemplateService->findByDomain($dnsTarget);
			if (isset($template[0]['connection'])) {
				$settings = json_decode($template[0]['connection'], true, 512, JSON_THROW_ON_ERROR);
				foreach ($settings as $property => $value) {
					$configuration[$property] = $value;
				}
			}
		}

		if (empty($configuration['location_host'])) {
			$configuration['location_host'] = $identityDomain;
		}

		if (empty($configuration['location_path'])) {
			$configuration['location_path'] = '/.well-known/caldav';
		}

		return $configuration;
	}

	/**
	 * connects to account, verifies details, then create service
	 *
	 * @param string $uid user id
	 * @param array $configuration service connection data
	 * @param array $options
	 *
	 * @return ServiceEntity
	 */
	public function connectAccount(string $uid, array $configuration, array $options = []): ServiceEntity {
		$forceAutoDiscovery = in_array('AUTO_DISCOVERY', $options, true);

		// validate service configuration
		if (!empty($configuration['location_host']) && !\OCA\DAVC\Utile\Validator::host($configuration['location_host'])) {
			throw new \InvalidArgumentException('Invalid DAV host provided.');
		}

		if ($configuration['auth'] === Constants::AUTHENTICATION_TYPE_BASIC) {
			// validate id
			if (!\OCA\DAVC\Utile\Validator::username($configuration['bauth_id'])) {
				throw new \InvalidArgumentException('Invalid DAV username provided for basic authentication.');
			}
			// validate secret
			if (!\OCA\DAVC\Utile\Validator::password($configuration['bauth_secret'])) {
				throw new \InvalidArgumentException('Invalid DAV password provided for basic authentication.');
			}
		} elseif ($configuration['auth'] === Constants::AUTHENTICATION_TYPE_TOKEN) {
			// validate id
			if (!\OCA\DAVC\Utile\Validator::username($configuration['oauth_id'])) {
				throw new \InvalidArgumentException('Invalid DAV identity provided for token authentication.');
			}
			// validate secret
			if (!\OCA\DAVC\Utile\Validator::password($configuration['oauth_access_token'])) {
				throw new \InvalidArgumentException('Invalid DAV access token provided.');
			}
		} else {
			throw new \InvalidArgumentException('Unsupported DAV authentication type.');
		}
		// if host was not provided, or auto-discovery was explicitly requested, attempt to locate it
		if ($forceAutoDiscovery || empty($configuration['location_host'])) {
			$configuration = $this->locateAccount($configuration) ?? [];
			if (empty($configuration['location_host'])) {
				throw new \RuntimeException('Unable to locate a DAV host for the provided account.');
			}
		}

		// apply administrator transport security policy
		if ($this->ConfigurationService->getForbidInsecureHttp()
			&& ($configuration['location_protocol'] ?? 'https') === 'http') {
			throw new \InvalidArgumentException('Insecure (http) connections are not permitted by the administrator.');
		}
		if ($this->ConfigurationService->getForceCertificateVerification()) {
			$configuration['location_security'] = true;
		}

		// construct service entity
		$service = new ServiceEntity();
		if (isset($configuration['id'])) {
			unset($configuration['id']);
		}
		$service->setUuid(\OCA\DAVC\Utile\UUID::v4());
		$service->setLabel($configuration['label'] ?? 'Unknown');
		$service->setLocationProtocol($configuration['location_protocol'] ?? 'https');
		$service->setLocationHost($configuration['location_host']);
		$service->setLocationPort($configuration['location_port'] ?? 443);
		$service->setLocationPath($configuration['location_path'] ?? null);
		$service->setLocationSecurity((bool)($configuration['location_security'] ?? 1));
		$service->setAuth($configuration['auth']);
		if ($configuration['auth'] === Constants::AUTHENTICATION_TYPE_BASIC) {
			$service->setBauthId($configuration['bauth_id']);
			$service->setBauthSecret($configuration['bauth_secret']);
			$service->setBauthLocation($configuration['bauth_location'] ?? null);
		}
		if ($configuration['auth'] === Constants::AUTHENTICATION_TYPE_TOKEN) {
			$service->setOauthId($configuration['oauth_id']);
			$service->setOauthAccessToken($configuration['oauth_access_token']);
			$service->setOauthAccessLocation($configuration['oauth_location'] ?? null);
		}

		// construct remote data store client
		$remoteStore = $this->remoteFactory->freshClient($service);
		$endpoint = sprintf(
			'%s://%s:%d%s',
			$configuration['location_protocol'] ?? 'https',
			$configuration['location_host'],
			$configuration['location_port'] ?? 443,
			$configuration['location_path'] ?? '/',
		);

		// connect client
		try {
			$info = $remoteStore->discover();
		} catch (Throwable $e) {
			$this->logger->error('Connection failed:', ['app' => 'davc', 'exception' => $e]);
			throw new \RuntimeException(sprintf('DAV discovery failed for %s: %s', $endpoint, $e->getMessage()), 0, $e);
		}

		// determine if connection was established
		if ($info['connected'] === false) {
			throw new \RuntimeException(sprintf('DAV discovery did not establish a connection for %s.', $endpoint));
		}

		if ($info['principalUrl'] !== null) {
			$service->setPrincipalUrl($info['principalUrl']);
		}
		if ($info['calendarHomeSet'] !== null) {
			$service->setCalendarsUrl($info['calendarHomeSet']);
		}
		if ($info['addressbookHomeSet'] !== null) {
			$service->setAddressbooksUrl($info['addressbookHomeSet']);
		}

		$service->setEnabled(true);
		$service->setConnected(true);

		$service = $this->ServicesService->deposit($uid, $service);

		// register periodic harmonization task for this service
		$this->TaskService->add(\OCA\DAVC\Tasks\HarmonizationLauncher::class, ['uid' => $uid, 'sid' => $service->getId()]);

		return $service;
	}

	/**
	 * Removes all users settings, etc for specific user
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 *
	 * @return void
	 */
	public function disconnectAccount(string $uid, int $sid): void {

		// retrieve service information
		$service = $this->ServicesService->fetch($sid);
		// determine if user if the service owner
		if ($service->getUid() !== $uid) {
			return;
		}
		// deregister task
		$this->TaskService->remove(\OCA\DAVC\Tasks\HarmonizationLauncher::class, ['uid' => $uid, 'sid' => $sid]);
		// initialize contacts data store
		$localStore = $this->localFactory->contactsStore();
		// delete local entities
		$localStore->entityDeleteByService($sid);
		// delete local collection
		$localStore->collectionDeleteByService($sid);
		// initialize events data store
		$localStore = $this->localFactory->eventsStore();
		// delete local entities
		$localStore->entityDeleteByService($sid);
		// delete local collection
		$localStore->collectionDeleteByService($sid);
		// delete service
		$this->ServicesService->delete($uid, $service);

	}

	/**
	 * retrieves remote collections for all modules
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 *
	 * @return array of remote collection(s) and attributes
	 */
	public function remoteCollectionsFetch(string $uid, int $sid): array {

		// construct response object
		$data = ['ContactsSupported' => false, 'ContactsCollections' => [], 'EventsSupported' => false, 'EventsCollections' => []];
		// retrieve service information
		$service = $this->ServicesService->fetch($sid);
		// determine if user is the service owner
		if ($service->getUid() !== $uid) {
			return $data;
		}
		// create remote store client
		$remoteStore = $this->remoteFactory->freshClient($service);
		// retrieve collections for contacts module
		if ($this->ConfigurationService->isContactsAppAvailable() && $remoteStore->getAddressbookHome() !== null) {
			$remoteContactsService = null;
			try {
				$remoteContactsService = $this->remoteFactory->contactsService($remoteStore);
				$collections = $remoteContactsService->collectionList();
				$data['ContactsSupported'] = true;
				$data['ContactsCollections'] = array_map(function ($collection) {
					return ['id' => $collection->remoteId, 'label' => 'Personal - ' . $collection->label];
				}, $collections);
			} catch (Throwable $e) {
				// AddressBook name space is not supported fail silently
			}
			// if AddressBook name space is not supported see if Contacts name space works
			if ($remoteContactsService !== null && count($data['ContactsCollections']) === 0) {
				try {
					$list = $remoteContactsService->entityList('', 'B');
					$data['ContactsSupported'] = true;
					$data['ContactsCollections'][] = ['id' => 'Default', 'label' => 'Personal - Contacts', 'count' => $list['total']];
				} catch (Throwable $e) {
					// ContactCard name space is not supported fail silently
				}

			}
		}
		// retrieve collections for events module
		if ($this->ConfigurationService->isCalendarAppAvailable() && $remoteStore->getCalendarHome() !== null) {
			$data['EventsSupported'] = true;
			$data['EventsCollections'] = [];

			try {
				$remoteEventsService = $this->remoteFactory->eventsService($remoteStore);
				$collections = $remoteEventsService->collectionList();
				$data['EventsCollections'] = array_map(function ($collection) {
					return ['id' => $collection->remoteId, 'label' => 'Personal - ' . $collection->label];
				}, $collections);
			} catch (Throwable $e) {
				$data['EventsSupported'] = false;
				$data['EventsCollections'] = [];
				$this->logger->error('Failed to retrieve remote events collections:', ['app' => 'davc', 'exception' => $e]);
			}
		}
		// return response
		return $data;
	}

	/**
	 * retrieves local collections for all modules
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 *
	 * @return array of collection correlation(s) and attributes
	 */
	public function localCollectionsFetch(string $uid, int $sid): array {

		// construct response object
		$data = ['ContactCollections' => [], 'EventCollections' => []];
		// retrieve service information
		$service = $this->ServicesService->fetch($sid);
		// determine if user if the service owner
		if ($service->getUid() !== $uid) {
			return $data;
		}
		// retrieve local collections
		if ($this->ConfigurationService->isContactsAppAvailable()) {
			$localStore = $this->localFactory->contactsStore();
			$data['ContactCollections'] = $localStore->collectionListByService($sid);
		}
		if ($this->ConfigurationService->isCalendarAppAvailable()) {
			$localStore = $this->localFactory->eventsStore();
			$data['EventCollections'] = $localStore->collectionListByService($sid);
		}
		// return response
		return $data;
	}

	/**
	 * Deposit collection correlations for all modules
	 *
	 * @param string $uid user id
	 * @param int $sid service id
	 * @param array $cc contacts collection(s) correlations
	 * @param array $ec events collection(s) correlations
	 *
	 * @return void of collection correlation(s) and attributes
	 */
	public function localCollectionsDeposit(string $uid, int $sid, array $cc, array $ec): void {

		// retrieve service information
		$service = $this->ServicesService->fetch($sid);
		// determine if user is the service owner
		if ($service->getUid() !== $uid) {
			return;
		}
		$remoteStore = $this->remoteFactory->freshClient($service);
		// deposit contacts correlations
		if ($this->ConfigurationService->isContactsAppAvailable()) {
			// initialize data store
			$localStore = $this->localFactory->contactsStore();
			$remoteContactsService = null;
			if ($remoteStore->getAddressbookHome() !== null) {
				try {
					$remoteContactsService = $this->remoteFactory->contactsService($remoteStore);
				} catch (Throwable $e) {
					$this->logger->warning('Failed to initialize remote contacts service during collection deposit.', ['app' => 'davc', 'exception' => $e]);
				}
			}
			// process entries
			foreach ($cc as $entry) {
				if (!isset($entry['enabled']) || !is_bool($entry['enabled'])) {
					continue;
				}
				switch ((bool)$entry['enabled']) {
					case false:
						if (is_numeric($entry['id'])) {
							$collection = $localStore->collectionFetch($entry['id']);
							if ($collection->getUid() === $uid) {
								$localStore->collectionDelete($collection);
							}
						}
						break;
					case true:
						if (empty($entry['id'])) {
							$remoteCollection = null;
							if ($remoteContactsService !== null && !empty($entry['ccid'])) {
								try {
									$remoteCollection = $remoteContactsService->collectionFetch((string)$entry['ccid']);
								} catch (Throwable $e) {
									$this->logger->warning('Failed to fetch remote contacts collection during deposit.', ['app' => 'davc', 'exception' => $e, 'collectionId' => $entry['ccid']]);
								}
							}
							// create local collection
							$collection = $localStore->collectionFresh();
							$collection->setUid($uid);
							$collection->setSid($sid);
							$collection->setCcid($entry['ccid']);
							$collection->setUuid(\OCA\DAVC\Utile\UUID::v4());
							$collection->setPermissions($remoteCollection?->permissions);
							$collection->setLabel('DavC: ' . ($remoteCollection?->label ?? $entry['label'] ?? 'Unknown'));
							$collection->setColor($remoteCollection?->color ?? $entry['color'] ?? '#0055aa');
							$collection->setVisible(true);
							$id = $localStore->collectionCreate($collection);
						}
						break;
				}
			}
		}
		// deposit events correlations
		if ($this->ConfigurationService->isCalendarAppAvailable()) {
			// initialize data store
			$localStore = $this->localFactory->eventsStore();
			$remoteEventsService = null;
			if ($remoteStore->getCalendarHome() !== null) {
				try {
					$remoteEventsService = $this->remoteFactory->eventsService($remoteStore);
				} catch (Throwable $e) {
					$this->logger->warning('Failed to initialize remote events service during collection deposit.', ['app' => 'davc', 'exception' => $e]);
				}
			}
			// process entries
			foreach ($ec as $entry) {
				if (!isset($entry['enabled']) || !is_bool($entry['enabled'])) {
					continue;
				}
				switch ((bool)$entry['enabled']) {
					case false:
						if (is_numeric($entry['id'])) {
							$collection = $localStore->collectionFetch($entry['id']);
							if ($collection->getUid() === $uid) {
								$localStore->collectionDelete($collection);
							}
						}
						break;
					case true:
						if (empty($entry['id'])) {
							$remoteCollection = null;
							if ($remoteEventsService !== null && !empty($entry['ccid'])) {
								try {
									$remoteCollection = $remoteEventsService->collectionFetch((string)$entry['ccid']);
								} catch (Throwable $e) {
									$this->logger->warning('Failed to fetch remote events collection during deposit.', ['app' => 'davc', 'exception' => $e, 'collectionId' => $entry['ccid']]);
								}
							}
							// create local collection
							$collection = $localStore->collectionFresh();
							$collection->setUid($uid);
							$collection->setSid($sid);
							$collection->setCcid($entry['ccid']);
							$collection->setUuid(\OCA\DAVC\Utile\UUID::v4());
							$collection->setPermissions($remoteCollection?->permissions);
							$collection->setLabel('DavC: ' . ($remoteCollection?->label ?? $entry['label'] ?? 'Unknown'));
							$collection->setColor($remoteCollection?->color ?? $entry['color'] ?? '#0055aa');
							$collection->setComponents($remoteCollection?->components);
							$collection->setVisible(true);
							$id = $localStore->collectionCreate($collection);
						}
						break;
				}
			}
		}
	}

}
