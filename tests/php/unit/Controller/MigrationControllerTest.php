<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Tests\Unit\Controller;

use OCA\DAVC\Controller\MigrationController;
use OCA\DAVC\Exception\ServiceNotFoundException;
use OCA\DAVC\Service\MigrationService;
use OCA\DAVC\Service\Native\NativeContactsService;
use OCA\DAVC\Service\Native\NativeEventsService;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MigrationControllerTest extends TestCase {
	private MigrationService&MockObject $migrationService;
	private IJobList&MockObject $jobList;
	private MigrationController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->controller = new MigrationController(
			'integration_davc',
			$this->createMock(IRequest::class),
			$this->migrationService,
			$this->createMock(NativeEventsService::class),
			$this->createMock(NativeContactsService::class),
			$this->jobList,
			'user1',
		);
	}

	private function lingeringJob(string $status): IJob&MockObject {
		$job = $this->createMock(IJob::class);
		$job->method('getId')->willReturn('7');
		$job->method('getArgument')->willReturn(['uid' => 'user1', 'sid' => 3, 'status' => $status]);
		$this->jobList->method('getJobsIterator')->willReturn([$job]);
		return $job;
	}

	public function testStartReturnsNotFoundForServiceOfOtherUser(): void {
		$this->jobList->method('getJobsIterator')->willReturn([]);
		$this->migrationService->method('start')->willThrowException(new ServiceNotFoundException('Service 3 not found'));
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller->start(3, 'event', 'outbound', '5', '/calendars/user/personal/');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testStartRejectsInaccessibleCollectionAndKeepsFinishedMigration(): void {
		$this->lingeringJob(MigrationService::STATUS_COMPLETED);
		$this->migrationService->method('start')->willThrowException(new \InvalidArgumentException('The selected collection does not exist or is not accessible.'));
		$this->jobList->expects($this->never())->method('removeById');
		$this->jobList->expects($this->never())->method('add');

		$response = $this->controller->start(3, 'event', 'outbound', '99', '/calendars/user/personal/');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('The selected collection does not exist or is not accessible.', $response->getData());
	}

	public function testStartReplacesFinishedMigration(): void {
		$this->lingeringJob(MigrationService::STATUS_COMPLETED);
		$state = ['uid' => 'user1', 'sid' => 3, 'status' => MigrationService::STATUS_RUNNING];
		$this->migrationService->method('start')->willReturn($state);
		$this->jobList->expects($this->once())->method('removeById')->with('7');
		$this->jobList->expects($this->once())->method('add')->with($this->anything(), $state);

		$response = $this->controller->start(3, 'event', 'outbound', '5', '/calendars/user/personal/');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testStartRefusesWhileMigrationIsRunning(): void {
		$this->lingeringJob(MigrationService::STATUS_RUNNING);
		$this->migrationService->expects($this->never())->method('start');

		$response = $this->controller->start(3, 'event', 'outbound', '5', '/calendars/user/personal/');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}

}
