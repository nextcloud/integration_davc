<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Controller;

use OCA\DAVC\Controller\UserConfigurationController;
use OCA\DAVC\Exception\ServiceNotFoundException;
use OCA\DAVC\Service\CoreService;
use OCA\DAVC\Service\HarmonizationService;
use OCA\DAVC\Service\ServicesService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserConfigurationControllerTest extends TestCase {
	private CoreService&MockObject $coreService;
	private HarmonizationService&MockObject $harmonizationService;
	private LoggerInterface&MockObject $logger;
	private UserConfigurationController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->coreService = $this->createMock(CoreService::class);
		$this->harmonizationService = $this->createMock(HarmonizationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->controller = new UserConfigurationController(
			'integration_davc',
			$this->createMock(IRequest::class),
			$this->coreService,
			$this->harmonizationService,
			$this->createMock(ServicesService::class),
			$this->logger,
			'user1',
		);
	}

	public function testUnknownServiceReturnsNotFound(): void {
		$this->coreService->method('localCollectionsFetch')->willThrowException(new ServiceNotFoundException('Service 3 not found'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller->localCollectionsFetch(3);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Service not found', $response->getData());
	}

	public function testUnknownServiceOnHarmonizeReturnsNotFound(): void {
		$this->harmonizationService->method('performHarmonization')->willThrowException(new ServiceNotFoundException('Service 3 not found'));

		$response = $this->controller->Harmonize(3);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUnexpectedErrorIsLoggedAndNotExposed(): void {
		$exception = new \TypeError('Argument #1 ($service) must be of type ServiceEntity');
		$this->coreService->method('disconnectAccount')->willThrowException($exception);
		$this->logger->expects($this->once())
			->method('error')
			->with($this->anything(), $this->callback(static fn (array $context): bool => $context['exception'] === $exception));

		$response = $this->controller->Disconnect(3);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('An internal error occurred', $response->getData());
	}

	public function testConnectReturnsValidationMessage(): void {
		$this->coreService->method('connectAccount')->willThrowException(new \InvalidArgumentException('Invalid DAV host provided.'));

		$response = $this->controller->Connect(['location_host' => '']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid DAV host provided.', $response->getData());
	}

	public function testConnectHidesUnexpectedErrors(): void {
		$this->coreService->method('connectAccount')->willThrowException(new \Exception('SQLSTATE[23000]: Integrity constraint violation'));
		$this->logger->expects($this->once())->method('error');

		$response = $this->controller->Connect([]);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('An internal error occurred', $response->getData());
	}

	public static function rateLimitedEndpointProvider(): array {
		return [
			'connect' => ['Connect', 10, 600],
			'harmonize' => ['Harmonize', 5, 300],
		];
	}

	#[DataProvider('rateLimitedEndpointProvider')]
	public function testEndpointIsRateLimitedPerUser(string $method, int $limit, int $period): void {
		$attributes = (new \ReflectionMethod(UserConfigurationController::class, $method))->getAttributes(UserRateLimit::class);

		$this->assertCount(1, $attributes);
		$rateLimit = $attributes[0]->newInstance();
		$this->assertSame($limit, $rateLimit->getLimit());
		$this->assertSame($period, $rateLimit->getPeriod());
	}

}
