<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Service;

use OCA\DAVC\AppInfo\Application;
use OCA\DAVC\Service\ConfigurationService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigurationServiceTest extends TestCase {
	private IConfig&MockObject $config;

	private ICrypto&MockObject $crypto;

	private ConfigurationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->service = new ConfigurationService($this->config, $this->crypto);
	}

	public function testGetHarmonizationIntervalReturnsDefaultWhenUnset(): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'harmonization_interval')
			->willReturn('');

		$this->assertSame(900, $this->service->getHarmonizationInterval());
	}

	public function testGetHarmonizationIntervalReturnsStoredValue(): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'harmonization_interval')
			->willReturn('3600');

		$this->assertSame(3600, $this->service->getHarmonizationInterval());
	}

	public function testGetHarmonizationIntervalClampsToMinimum(): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'harmonization_interval')
			->willReturn('30');

		$this->assertSame(300, $this->service->getHarmonizationInterval());
	}

	public function testSetHarmonizationIntervalPersistsValue(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'harmonization_interval', '1800');

		$this->service->setHarmonizationInterval(1800);
	}

	public function testSetHarmonizationIntervalClampsToMinimum(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'harmonization_interval', '300');

		$this->service->setHarmonizationInterval(60);
	}

	public function testGetForceCertificateVerificationDefaultsFalse(): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'force_certificate_verification')
			->willReturn('');

		$this->assertFalse($this->service->getForceCertificateVerification());
	}

	public function testGetForceCertificateVerificationReturnsTrueWhenEnabled(): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'force_certificate_verification')
			->willReturn('1');

		$this->assertTrue($this->service->getForceCertificateVerification());
	}

	public function testSetForceCertificateVerificationPersistsFlag(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'force_certificate_verification', '1');

		$this->service->setForceCertificateVerification(true);
	}

	public function testGetForbidInsecureHttpDefaultsFalse(): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'forbid_insecure_http')
			->willReturn('');

		$this->assertFalse($this->service->getForbidInsecureHttp());
	}

	public function testSetForbidInsecureHttpPersistsFlag(): void {
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(Application::APP_ID, 'forbid_insecure_http', '0');

		$this->service->setForbidInsecureHttp(false);
	}

	public static function transportPermittedProvider(): array {
		return [
			'http forbidden' => ['1', 'http', false],
			'http forbidden ignores case' => ['1', 'HTTP', false],
			'https with http forbidden' => ['1', 'https', true],
			'missing protocol with http forbidden' => ['1', null, true],
			'http allowed' => ['0', 'http', true],
		];
	}

	#[DataProvider('transportPermittedProvider')]
	public function testIsTransportPermitted(string $forbidInsecureHttp, ?string $protocol, bool $expected): void {
		$this->config->method('getAppValue')
			->with(Application::APP_ID, 'forbid_insecure_http')
			->willReturn($forbidInsecureHttp);

		$this->assertSame($expected, $this->service->isTransportPermitted($protocol));
	}

	public function testDepositSystemNormalisesBooleansAndNumbers(): void {
		$stored = [];
		$this->config->method('setAppValue')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$stored): void {
				$stored[$key] = $value;
			});

		$this->service->depositSystem([
			'harmonization_interval' => 1800,
			'force_certificate_verification' => true,
			'forbid_insecure_http' => false,
		]);

		$this->assertSame('1800', $stored['harmonization_interval']);
		$this->assertSame('1', $stored['force_certificate_verification']);
		$this->assertSame('0', $stored['forbid_insecure_http']);
	}
}
