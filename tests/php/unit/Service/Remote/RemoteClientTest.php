<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service\Remote;

use GuzzleHttp\Psr7\Uri;
use OCA\DAVC\Service\Remote\RemoteClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\Security\IRemoteHostValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RemoteClientTest extends TestCase {
	private IClient&MockObject $httpClient;
	private IRemoteHostValidator&MockObject $remoteHostValidator;
	private RemoteClient $client;
	/** @var list<array{url: string, options: array}> */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->httpClient = $this->createMock(IClient::class);
		$this->httpClient->method('request')->willReturnCallback(function (string $method, string $url, array $options): IResponse {
			$this->requests[] = ['url' => $url, 'options' => $options];
			return $this->createMock(IResponse::class);
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($this->httpClient);
		$this->remoteHostValidator = $this->createMock(IRemoteHostValidator::class);

		$this->client = new RemoteClient($clientService, $this->remoteHostValidator);
	}

	public function testRelativePathResolvesAgainstConfiguredLocation(): void {
		$this->client->configureLocation('https', 'dav.example.com', 443, '/dav/');

		$this->client->delete('/calendars/user/personal/event.ics');

		$this->assertSame('https://dav.example.com/calendars/user/personal/event.ics', $this->requests[0]['url']);
	}

	public function testSecureHrefOnOtherHostIsAllowed(): void {
		$this->client->configureLocation('https', 'caldav.example.com', 443, '/');

		$this->client->delete('https://p42-caldav.example.com/calendars/user/personal/event.ics');

		$this->assertSame('https://p42-caldav.example.com/calendars/user/personal/event.ics', $this->requests[0]['url']);
	}

	public function testInsecureHrefIsRefusedForSecureService(): void {
		$this->client->configureLocation('https', 'dav.example.com', 443, '/');

		try {
			$this->client->delete('http://dav.example.com/calendars/user/personal/event.ics');
			$this->fail('Expected the insecure request to be refused');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('Refused insecure (http) request', $e->getMessage());
		}
		$this->assertSame([], $this->requests);
	}

	public function testInsecureHrefIsAllowedForInsecureService(): void {
		$this->client->configureLocation('http', 'dav.example.com', 80, '/');

		$this->client->delete('http://other.example.com/event.ics');

		$this->assertSame('http://other.example.com/event.ics', $this->requests[0]['url']);
	}

	public function testRedirectsOfSecureServiceAreLimitedToHttps(): void {
		$this->client->configureLocation('https', 'dav.example.com', 443, '/');

		$this->client->delete('/event.ics');

		$redirects = $this->requests[0]['options']['allow_redirects'];
		$this->assertSame(['https'], $redirects['protocols']);
		$this->assertTrue($redirects['strict']);
	}

	public function testRedirectsOfInsecureServiceAllowHttpAndHttps(): void {
		$this->client->configureLocation('http', 'dav.example.com', 80, '/');

		$this->client->delete('/event.ics');

		$this->assertSame(['http', 'https'], $this->requests[0]['options']['allow_redirects']['protocols']);
	}

	public function testRedirectToDisallowedHostIsRefused(): void {
		$this->client->configureLocation('https', 'dav.example.com', 443, '/');
		$this->remoteHostValidator->method('isValid')->willReturnMap([
			['dav2.example.com', true],
			['127.0.0.1', false],
		]);

		$this->client->delete('/event.ics');
		$onRedirect = $this->requests[0]['options']['allow_redirects']['on_redirect'];

		$onRedirect(null, null, new Uri('https://dav2.example.com/event.ics'));
		$this->expectException(LocalServerException::class);
		$onRedirect(null, null, new Uri('https://127.0.0.1/event.ics'));
	}

}
