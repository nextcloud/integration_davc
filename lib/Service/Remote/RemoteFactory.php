<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Remote;

use OCA\DAVC\Constants;
use OCA\DAVC\Logging\FileLogger;
use OCA\DAVC\Service\ConfigurationService;
use OCA\DAVC\Store\Local\ServiceEntity;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class RemoteFactory {
	public static string $clientTransportAgent = 'NextcloudDAVC/1.0 (1.0; x64)';

	public function __construct(
		private readonly IClientService $clientService,
		private readonly IConfig $config,
		private readonly ConfigurationService $configurationService,
	) {
	}

	/**
	 * Initialize remote data store client
	 */
	public function freshClient(ServiceEntity $service): RemoteClient {
		if (!$this->configurationService->isTransportPermitted($service->getLocationProtocol())) {
			throw new \RuntimeException('Insecure (http) connections are not permitted by the administrator.');
		}

		$client = new RemoteClient($this->clientService);
		$client->configureTransportAgent(self::$clientTransportAgent);
		$client->configureLocation(
			$service->getLocationProtocol(),
			$service->getLocationHost(),
			$service->getLocationPort(),
			$service->getLocationPath(),
		);
		// the administrator may enforce certificate verification regardless of the per-service setting
		$client->configureTransportVerification(
			$this->configurationService->getForceCertificateVerification()
				? true
				: (bool)$service->getLocationSecurity(),
		);

		if ($service->getAuth() === Constants::AUTHENTICATION_TYPE_BASIC) {
			$client->setBasicAuthentication(
				(string)$service->getBauthId(),
				(string)$service->getBauthSecret(),
			);
		}

		if ($service->getAuth() === Constants::AUTHENTICATION_TYPE_TOKEN) {
			$client->setBearerAuthentication((string)$service->getOauthAccessToken());
		}

		// Set default capabilities from saved service
		// This is does to short circuit the discovery process and avoid
		// unnecessary network calls when capabilities are already known from previous discovery
		$client->setPrincipalUrl($service->getPrincipalUrl());
		$client->setCalendarHome($service->getCalendarsUrl());
		$client->setAddressbookHome($service->getAddressbooksUrl());
		$client->configureLogging((bool)$service->getDebug() ? $this->logger($service) : null);

		return $client;
	}

	private function logger(ServiceEntity $service): LoggerInterface {
		/** @var int $serviceId */
		$serviceId = $service->getId();
		$path = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/davc-' . $serviceId . '.log';

		return new FileLogger($path);
	}

	/**
	 * Appropriate Contacts Service for Connection
	 */
	public function contactsService(RemoteClient $client): RemoteContactsService {
		$service = new RemoteContactsService();
		$service->initialize($client);

		return $service;
	}

	/**
	 * Appropriate Events Service for Connection
	 */
	public function eventsService(RemoteClient $client): RemoteEventsService {
		$service = new RemoteEventsService();
		$service->initialize($client);

		return $service;
	}

}
