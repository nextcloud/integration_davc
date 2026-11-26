<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Store\Local;

use OCA\DAVC\Store\Local\ServiceEntity;
use PHPUnit\Framework\TestCase;

class ServiceEntityTest extends TestCase {

	public function testJsonSerializeExcludesCredentials(): void {
		$service = new ServiceEntity();
		$service->setId(7);
		$service->setBauthId('user');
		$service->setBauthSecret('basic-secret');
		$service->setOauthId('user');
		$service->setOauthAccessToken('access-token');
		$service->setOauthRefreshToken('refresh-token');

		$serialized = $service->jsonSerialize();

		$this->assertSame(7, $serialized['id']);
		$this->assertSame('user', $serialized['bauth_id']);
		$this->assertSame('user', $serialized['oauth_id']);
		$this->assertArrayNotHasKey('bauth_secret', $serialized);
		$this->assertArrayNotHasKey('oauth_access_token', $serialized);
		$this->assertArrayNotHasKey('oauth_refresh_token', $serialized);
		$encoded = json_encode($service);
		$this->assertStringNotContainsString('basic-secret', $encoded);
		$this->assertStringNotContainsString('access-token', $encoded);
		$this->assertStringNotContainsString('refresh-token', $encoded);
	}

}
