<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Store\Local;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method getId(): int
 * @method getUid(): string
 * @method setUid(string $uid): void
 * @method getUuid(): ?string
 * @method setUuid(string $uuid): void
 * @method getLabel(): ?string
 * @method setLabel(string $label): void
 * @method getLocationProtocol(): string
 * @method setLocationProtocol(string $value): void
 * @method getLocationHost(): string
 * @method setLocationHost(string $value): void
 * @method getLocationPort(): int
 * @method setLocationPort(int $value): void
 * @method getLocationPath(): ?string
 * @method setLocationPath(string $value): void
 * @method getLocationSecurity(): bool
 * @method setLocationSecurity(bool $value): void
 * @method getPrincipalUrl(): ?string
 * @method setPrincipalUrl(string $value): void
 * @method getCalendarsUrl(): ?string
 * @method setCalendarsUrl(string $value): void
 * @method getAddressbooksUrl(): ?string
 * @method setAddressbooksUrl(string $value): void
 * @method getAuth(): ?string
 * @method setAuth(string $value): void
 * @method getBauthId(): ?string
 * @method setBauthId(string $value): void
 * @method getBauthSecret(): ?string
 * @method setBauthSecret(string $value): void
 * @method getBauthLocation(): ?string
 * @method setBauthLocation(string $value): void
 * @method getOauthId(): ?string
 * @method setOauthId(string $value): void
 * @method getOauthAccessToken(): ?string
 * @method setOauthAccessToken(string $value): void
 * @method getOauthAccessLocation(): ?string
 * @method setOauthAccessLocation(string $value): void
 * @method getOauthAccessExpiry(): ?int
 * @method setOauthAccessExpiry(int $value): void
 * @method getOauthRefreshToken(): ?string
 * @method setOauthRefreshToken(string $value): void
 * @method getOauthRefreshLocation(): ?string
 * @method setOauthRefreshLocation(string $value): void
 * @method getEnabled(): bool
 * @method setEnabled(bool $value): void
 * @method getConnected(): bool
 * @method setConnected(bool $value): void
 * @method getDebug(): bool
 * @method setDebug(bool $value): void
 * @method getHarmonizationState(): ?int
 * @method setHarmonizationState(int $value): void
 * @method getHarmonizationStart(): ?int
 * @method setHarmonizationStart(int $value): void
 * @method getHarmonizationEnd(): ?int
 * @method setHarmonizationEnd(int $value): void
 * @method getSubscriptionCode(): ?string
 * @method setSubscriptionCode(string $value): void
 * @method getContactsMode(): ?string
 * @method setContactsMode(?string $value): void
 * @method getEventsMode(): ?string
 * @method setEventsMode(string $value): void
 */
class ServiceEntity extends Entity implements JsonSerializable {
	protected ?string $uid = null;
	protected ?string $uuid = null;
	protected ?string $label = null;
	protected ?string $locationProtocol = null;
	protected ?string $locationHost = null;
	protected ?int $locationPort = null;
	protected ?string $locationPath = null;
	protected ?string $principalUrl = null;
	protected ?string $calendarsUrl = null;
	protected ?string $addressbooksUrl = null;
	protected ?int $locationSecurity = 1;
	protected ?string $auth = null;
	protected ?string $bauthId = null;
	protected ?string $bauthSecret = null;
	protected ?string $bauthLocation = null;
	protected ?string $oauthId = null;
	protected ?string $oauthAccessToken = null;
	protected ?string $oauthAccessLocation = null;
	protected ?int $oauthAccessExpiry = null;
	protected ?string $oauthRefreshToken = null;
	protected ?string $oauthRefreshLocation = null;
	protected ?bool $connected = null;
	protected ?bool $enabled = null;
	protected ?bool $debug = false;
	protected ?int $harmonizationState = 0;
	protected ?int $harmonizationStart = 0;
	protected ?int $harmonizationEnd = 0;
	protected ?string $subscriptionCode = null;
	protected ?string $contactsMode = null;
	protected ?string $eventsMode = null;

	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uid' => $this->uid,
			'uuid' => $this->uuid,
			'label' => $this->label,
			'location_protocol' => $this->locationProtocol,
			'location_host' => $this->locationHost,
			'location_port' => $this->locationPort,
			'location_path' => $this->locationPath,
			'principal_url' => $this->principalUrl,
			'calendars_url' => $this->calendarsUrl,
			'addressbooks_url' => $this->addressbooksUrl,
			'location_security' => $this->locationSecurity,
			'auth' => $this->auth,
			'bauth_id' => $this->bauthId,
			'bauth_location' => $this->bauthLocation,
			'oauth_id' => $this->oauthId,
			'oauth_access_location' => $this->oauthAccessLocation,
			'oauth_access_expiry' => $this->oauthAccessExpiry,
			'oauth_refresh_location' => $this->oauthRefreshLocation,
			'enabled' => $this->enabled,
			'connected' => $this->connected,
			'debug' => $this->debug,
			'harmonization_state' => $this->harmonizationState,
			'harmonization_start' => $this->harmonizationStart,
			'harmonization_end' => $this->harmonizationEnd,
			'subscription_code' => $this->subscriptionCode,
			'contacts_mode' => $this->contactsMode,
			'events_mode' => $this->eventsMode,
		];
	}
}
