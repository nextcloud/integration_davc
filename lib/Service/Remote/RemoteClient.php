<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Remote;

use OCA\DAVC\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Xml\Response\MultiStatus;
use Sabre\DAV\Xml\Service as SabreXmlService;
use Sabre\Xml\ParseException;

class RemoteClient {

	public const DAV_PROFIND = '{DAV:}propfind';
	public const DAV_PROPERTY = '{DAV:}prop';
	public const DAV_MULTISTATUS = '{DAV:}multistatus';
	public const DAV_SYNC_COLLECTION = '{DAV:}sync-collection';
	public const DAV_SYNC_LEVEL = '{DAV:}sync-level';
	public const DAV_SYNC_TOKEN = '{DAV:}sync-token';
	public const DAV_HREF = '{DAV:}href';
	public const DAV_ETAG = '{DAV:}getetag';
	public const DAV_USER_PRINCIPAL = '{DAV:}current-user-principal';
	public const DAV_PRINCIPAL_URL = '{DAV:}principal-URL';
	public const DAV_RESOURCE_TYPE = '{DAV:}resourcetype';
	public const DAV_DISPLAYNAME = '{DAV:}displayname';
	public const DAV_OWNER = '{DAV:}owner';
	public const DAV_ACL = '{DAV:}acl';
	public const DAV_CURRENT_USER_PRIVILEGE_SET = '{DAV:}current-user-privilege-set';
	public const CALDAV_CALENDAR_TYPE = '{urn:ietf:params:xml:ns:caldav}calendar';
	public const CALDAV_CALENDAR_HOME_SET = '{urn:ietf:params:xml:ns:caldav}calendar-home-set';
	public const CALDAV_CALENDAR_DESCRIPTION = '{urn:ietf:params:xml:ns:caldav}calendar-description';
	public const CALDAV_CALENDAR_DATA = '{urn:ietf:params:xml:ns:caldav}calendar-data';
	public const CALDAV_CALENDAR_MULTIGET = '{urn:ietf:params:xml:ns:caldav}calendar-multiget';
	public const CALDAV_SUPPORTED_CALENDAR_COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
	public const CALDAV_COMPONENT = '{urn:ietf:params:xml:ns:caldav}comp';
	public const CARDDAV_ADDRESSBOOK_TYPE = '{urn:ietf:params:xml:ns:carddav}addressbook';
	public const CARDDAV_ADDRESSBOOK_HOME_SET = '{urn:ietf:params:xml:ns:carddav}addressbook-home-set';
	public const CARDDAV_ADDRESSBOOK_DESCRIPTION = '{urn:ietf:params:xml:ns:carddav}addressbook-description';
	public const CARDDAV_ADDRESS_DATA = '{urn:ietf:params:xml:ns:carddav}address-data';
	public const CARDDAV_ADDRESSBOOK_MULTIGET = '{urn:ietf:params:xml:ns:carddav}addressbook-multiget';
	public const CARDDAV_SUPPORTED_ADDRESS_DATA = '{urn:ietf:params:xml:ns:carddav}supported-address-data';
	public const CARDDAV_SUPPORTED_COLLATION_SET = '{urn:ietf:params:xml:ns:carddav}supported-collation-set';
	public const CARDDAV_MAX_RESOURCE_SIZE = '{urn:ietf:params:xml:ns:carddav}max-resource-size';
	public const APPLE_ICAL_CALENDAR_COLOR = '{http://apple.com/ns/ical/}calendar-color';
	public const APPLE_ICAL_CALENDAR_ORDER = '{http://apple.com/ns/ical/}calendar-order';
	public const CALENDARSERVER_GETCTAG = '{http://calendarserver.org/ns/}getctag';
	public const SABREDAV_SYNC_TOKEN = '{http://sabredav.org/ns}sync-token';

	private ?IClient $client = null;

	private bool $connected = false;

	private string $transportAgent = '';

	private string $locationProtocol = 'https';

	private string $locationHost = '';

	private int $locationPort = 443;

	private ?string $locationPath = null;

	private bool $locationSecurity = true;

	private ?array $basicAuthentication = null;

	private ?string $bearerToken = null;

	private ?LoggerInterface $logger = null;

	private array $capabilities = [
		'connected' => false,
		'discovery' => false,
		'endpoint' => null,
		'dav' => [],
		'allow' => [],
		'principalUrl' => null,
		'calendarHomeSet' => null,
		'addressbookHomeSet' => null,
	];

	public function __construct(
		private IClientService $clientService,
		?LoggerInterface $logger = null,
	) {
		$this->logger = $logger;
	}

	public function configureTransportAgent(string $transportAgent): void {
		$this->transportAgent = $transportAgent;
	}

	public function configureTransportVerification(bool $verify): void {
		$this->locationSecurity = $verify;
	}

	public function configureLocation(?string $protocol, string $host, ?int $port, ?string $path): void {
		$this->locationHost = $host;
		if ($protocol !== null) {
			$this->locationProtocol = $protocol;
		}
		if ($port !== null) {
			$this->locationPort = $port;
		}
		if ($path !== null) {
			$this->locationPath = $path;
		}
	}

	public function configureLogging(?LoggerInterface $logger): void {
		$this->logger = $logger instanceof LoggerInterface ? $logger : null;
	}

	public function setBasicAuthentication(string $username, string $password): void {
		$this->basicAuthentication = [$username, $password];
		$this->bearerToken = null;
	}

	public function setBearerAuthentication(string $token): void {
		$this->bearerToken = $token;
		$this->basicAuthentication = null;
	}

	public function getPrincipalUrl(): ?string {
		return $this->capabilities['principalUrl'] ?? null;
	}

	public function setPrincipalUrl(?string $principalUrl): void {
		$this->capabilities['principalUrl'] = $principalUrl;
	}

	public function getCalendarHome(): ?string {
		return $this->capabilities['calendarHomeSet'] ?? null;
	}

	public function setCalendarHome(?string $calendarHomeSet): void {
		$this->capabilities['calendarHomeSet'] = $calendarHomeSet;
	}

	public function getAddressbookHome(): ?string {
		return $this->capabilities['addressbookHomeSet'] ?? null;
	}

	public function setAddressbookHome(?string $addressbookHomeSet): void {
		$this->capabilities['addressbookHomeSet'] = $addressbookHomeSet;
	}

	public function capabilities(?string $capability = null): array {
		if ($capability !== null) {
			return $this->capabilities[$capability] ?? [];
		}
		return $this->capabilities;
	}

	/**
	 * Perform an OPTIONS request.
	 *
	 * @param string|null $path The path to perform the OPTIONS request on.
	 * @return IResponse The response from the OPTIONS request.
	 */
	public function options(?string $path = null): IResponse {
		$url = $this->constructUrl($path ?? $this->locationPath ?? '/');

		$response = $this->transceive('OPTIONS', $url, $this->buildOptionsRequestOptions());
		$this->capabilities['dav'] = $this->parseHeaderList($response->getHeader('DAV'));
		$this->capabilities['allow'] = $this->parseHeaderList($response->getHeader('Allow'));

		return $response;
	}

	/**
	 * Perform a PROPFIND request.
	 *
	 * @param string $uri The URI to perform the PROPFIND request on.
	 * @param int $depth The depth of the PROPFIND request.
	 * @param array<int|string, string|null> $properties The properties to request.
	 * @return array The response properties.
	 */
	public function propFind(string $path, int $depth, array $properties): array {
		$normalizedProperties = [];
		foreach ($properties as $name => $value) {
			if (is_int($name)) {
				$normalizedProperties[(string)$value] = null;
				continue;
			}

			$normalizedProperties[$name] = $value;
		}

		$request = (new SabreXmlService())->write(self::DAV_PROFIND, [
			self::DAV_PROPERTY => $normalizedProperties,
		]);

		$options = $this->buildOptionsRequestOptions(
			['Depth' => (string)$depth],
			['body' => $request],
		);

		$url = $this->constructUrl($path);

		$response = $this->transceive('PROPFIND', $url, $options);

		return $this->parseMultistatusProperties($response);
	}

	/**
	 * Perform a REPORT request.
	 *
	 * @param string $path The URI to perform the REPORT request on.
	 * @param string $rootElement The root report XML element.
	 * @param int $depth The depth of the REPORT request.
	 * @param array<int, array{name: string, value?: mixed, attributes?: array<string, string>}> $elements The report body elements.
	 * @return array The response properties.
	 */
	public function report(string $path, string $rootElement, int $depth, array $elements): array {
		$request = (new SabreXmlService())->write($rootElement, $elements);

		$options = $this->buildOptionsRequestOptions(
			['Depth' => (string)$depth],
			['body' => $request],
		);

		$url = $this->constructUrl($path);

		$response = $this->transceive('REPORT', $url, $options);

		return $this->parseMultistatusProperties($response);
	}

	/**
	 * @param array<int, string> $hrefs
	 * @return array<string, array{href: string, status: int|null, etag: mixed, payload: mixed}>
	 */
	public function multiGet(string $collectionPath, array $hrefs, string $reportType, string $payloadProperty): array {
		if ($hrefs === []) {
			return [];
		}

		$elements = [
			[
				'name' => self::DAV_PROPERTY,
				'value' => [
					self::DAV_ETAG => null,
					$payloadProperty => null,
				],
			],
		];

		foreach ($hrefs as $href) {
			$elements[] = [
				'name' => self::DAV_HREF,
				'value' => $href,
			];
		}

		$responses = $this->report($collectionPath, $reportType, 1, $elements);
		$result = [];

		foreach ($responses as $href => $responseProperties) {
			$status = $this->extractResponseStatusCode($responseProperties);
			$properties = $status !== null ? ($responseProperties[$status] ?? []) : [];
			$result[$href] = [
				'href' => $href,
				'status' => $status,
				'etag' => $this->normalizePropertyValue($properties[self::DAV_ETAG] ?? null),
				'payload' => $this->normalizePropertyValue($properties[$payloadProperty] ?? null),
			];
		}

		return $result;
	}

	/**
	 * @return (int|null|string)[]
	 *
	 * @psalm-return array{etag: null|string, status: int, location: null|string, lastModified: null|string}
	 */
	public function create(string $path, string $payload, string $contentType): array {
		$url = $this->constructUrl($path);

		$response = $this->transceive('PUT', $url, $this->buildOptionsRequestOptions(
			[
				'Content-Type' => $contentType,
				'If-None-Match' => '*',
			],
			['body' => $payload],
		));

		return $this->parsePutResponse($response);
	}

	/**
	 * @return (int|null|string)[]
	 *
	 * @psalm-return array{etag: null|string, status: int, location: null|string, lastModified: null|string}
	 */
	public function update(string $path, string $payload, string $contentType, ?string $etag = null): array {
		$url = $this->constructUrl($path);

		$headers = [
			'Content-Type' => $contentType,
		];

		if ($etag !== null) {
			$headers['If-Match'] = $etag;
		}

		$response = $this->transceive('PUT', $url, $this->buildOptionsRequestOptions(
			$headers,
			['body' => $payload],
		));

		return $this->parsePutResponse($response);
	}

	public function delete(string $path): IResponse {
		$url = $this->constructUrl($path);

		return $this->transceive('DELETE', $url, $this->buildOptionsRequestOptions());
	}

	public function discover(): array {
		$url = $this->constructUrl($this->locationPath);
		$this->capabilities['endpoint'] = $url;

		try {
			$this->options($url);

			$discoveryProperties = $this->propFind($url, 0, [
				self::DAV_USER_PRINCIPAL => null,
			]);

			$this->capabilities['principalUrl'] = $this->extractHrefProperty(
				$discoveryProperties,
				self::DAV_USER_PRINCIPAL,
				$url,
			);

			if ($this->capabilities['principalUrl'] !== null) {
				$principalProperties = $this->propFind(
					$this->capabilities['principalUrl'],
					0,
					[
						self::DAV_PRINCIPAL_URL,
						self::CALDAV_CALENDAR_HOME_SET,
						self::CARDDAV_ADDRESSBOOK_HOME_SET,
					],
				);

				$this->capabilities['principalUrl'] = $this->extractHrefProperty(
					$principalProperties,
					self::DAV_PRINCIPAL_URL,
					$this->capabilities['principalUrl'],
				) ?? $this->capabilities['principalUrl'];
				$this->capabilities['calendarHomeSet'] = $this->extractHrefProperty(
					$principalProperties,
					self::CALDAV_CALENDAR_HOME_SET,
					$this->capabilities['principalUrl'],
				);
				$this->capabilities['addressbookHomeSet'] = $this->extractHrefProperty(
					$principalProperties,
					self::CARDDAV_ADDRESSBOOK_HOME_SET,
					$this->capabilities['principalUrl'],
				);
			}

			$this->capabilities['connected'] = true;
			$this->capabilities['discovery'] = true;
		} catch (ClientExceptionInterface|ParseException $e) {
			$this->capabilities['connected'] = false;
			$this->capabilities['discovery'] = false;
			throw $e;
		}

		return $this->capabilities;
	}

	private function transceive(string $method, string $url, array $options): IResponse {
		$this->logRequest($method, $url, $options);

		if ($this->client === null) {
			$this->client = $this->clientService->newClient();
		}

		try {
			$response = $this->client->request($method, $url, $options);
		} catch (ClientExceptionInterface $e) {
			$this->logRequestFailure($method, $url, $options, $e);
			throw $e;
		}

		if ($this->logger !== null) {
			[$response, $body] = $this->bufferResponse($response);
			$this->logResponseSuccess($method, $url, $options, $response, $body);
		}

		return $response;
	}

	private function constructUrl(?string $path = '/'): string {
		$host = sprintf(
			'%s://%s:%d',
			$this->locationProtocol,
			$this->locationHost,
			$this->locationPort,
		);
		$host = rtrim($host, '/') . '/';
		if ($path === null || $path === '') {
			$path = '/';
		}

		$uri = \GuzzleHttp\Psr7\UriResolver::resolve(
			\GuzzleHttp\Psr7\Utils::uriFor($host),
			\GuzzleHttp\Psr7\Utils::uriFor($path),
		);

		return (string)$uri;
	}

	private function buildOptionsRequestOptions(array $additionalHeaders = [], array $additionalOptions = []): array {
		$headers = [
			'User-Agent' => $this->transportAgent,
			'Content-Type' => 'application/xml; charset=utf-8',
			'Accept' => 'application/xml, text/xml;q=0.9, */*;q=0.8',
		];

		$headers = array_merge($headers, $additionalHeaders);

		$options = [
			'headers' => $headers,
			'timeout' => IClient::DEFAULT_REQUEST_TIMEOUT,
			'verify' => $this->locationSecurity,
			'allow_redirects' => ['strict' => true, 'max' => 5],
		];

		$options = array_merge($options, $additionalOptions);

		if ($this->basicAuthentication !== null) {
			$options['auth'] = $this->basicAuthentication;
		}

		if ($this->bearerToken !== null) {
			$options['headers']['Authorization'] = 'Bearer ' . $this->bearerToken;
		}

		return $options;
	}

	/**
	 * @return array{0: IResponse, 1: string}
	 */
	private function bufferResponse(IResponse $response): array {
		$body = $response->getBody();

		if (is_resource($body)) {
			$body = stream_get_contents($body) ?: '';
		} elseif ($body === null) {
			$body = '';
		} else {
			$body = (string)$body;
		}

		return [
			new BufferedResponse($response->getStatusCode(), $response->getHeaders(), $body),
			$body,
		];
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	private function extractRequestHeaders(array $options): array {
		$headers = $options['headers'] ?? [];

		if (isset($options['auth']) && !isset($headers['Authorization'])) {
			$headers['Authorization'] = '[redacted]';
		}

		if (isset($headers['Authorization'])) {
			$headers['Authorization'] = '[redacted]';
		}

		return $headers;
	}

	/**
	 * @param array<string, mixed> $headers
	 */
	private function formatHeaders(array $headers): string {
		if ($headers === []) {
			return '{}';
		}

		$lines = ['{'];

		foreach ($headers as $name => $value) {
			if (is_array($value)) {
				$value = implode(', ', array_map(static fn (mixed $headerValue): string => (string)$headerValue, $value));
			}

			$lines[] = sprintf('  %s: %s', (string)$name, (string)$value);
		}

		$lines[] = '}';

		return implode("\n", $lines);
	}

	private function formatBody(mixed $body): string {
		if (is_resource($body)) {
			$body = stream_get_contents($body) ?: '';
		}

		if ($body === null) {
			return '<null>';
		}

		$body = (string)$body;

		return $body !== '' ? $body : '<empty>';
	}

	private function parseHeaderList(string $header): array {
		if ($header === '') {
			return [];
		}

		return array_values(array_filter(array_map('trim', explode(',', $header)), static fn (string $value): bool => $value !== ''));
	}

	/**
	 * @return array{etag: string|null, status: int, location: string|null, lastModified: string|null}
	 */
	private function parsePutResponse(IResponse $response): array {
		$status = $response->getStatusCode();
		$etag = trim($response->getHeader('ETag'));
		$location = trim($response->getHeader('Location'));
		$lastModified = trim($response->getHeader('Last-Modified'));

		return [
			'status' => $status,
			'etag' => $etag !== '' ? $etag : null,
			'location' => $location !== '' ? $location : null,
			'lastModified' => $lastModified !== '' ? $lastModified : null
		];
	}

	private function parseMultistatusProperties(IResponse $response): array {
		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}

		if (!is_string($body) || $body === '') {
			return [];
		}

		/** @var MultiStatus $multistatus */
		$multistatus = (new SabreXmlService())->expect(self::DAV_MULTISTATUS, $body);

		$result = [];
		foreach ($multistatus->getResponses() as $davResponse) {
			$result[$davResponse->getHref()] = $davResponse->getResponseProperties();
		}
		if ($multistatus->getSyncToken() !== null) {
			$result['token'] = $multistatus->getSyncToken();
		}

		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $responseProperties
	 */
	private function extractResponseStatusCode(array $responseProperties): ?int {
		if (isset($responseProperties[200])) {
			return 200;
		}

		foreach (array_keys($responseProperties) as $status) {
			if (is_int($status)) {
				return $status;
			}
		}

		return null;
	}

	private function normalizePropertyValue(mixed $value): mixed {
		if (is_scalar($value) || $value === null) {
			return $value;
		}

		if (is_object($value) && method_exists($value, '__toString')) {
			return (string)$value;
		}

		return $value;
	}

	private function extractHrefProperty(array $properties, string $propertyName, string $baseUrl): ?string {
		foreach ($properties as $responseProperties) {
			if (!isset($responseProperties[200][$propertyName])) {
				continue;
			}

			$propertyValue = $responseProperties[200][$propertyName];
			if (!is_array($propertyValue) || !isset($propertyValue[0]['name']) || $propertyValue[0]['name'] !== self::DAV_HREF) {
				continue;
			}

			return (string)$propertyValue[0]['value'];
		}

		return null;
	}

	private function logRequest(string $method, string $url, array $options): void {
		if ($this->logger === null) {
			return;
		}

		$lines = [
			Application::APP_TAG . ' Request:',
			sprintf('Method: %s', $method),
			sprintf('RequestUri: %s', $url),
			'Headers:',
			$this->formatHeaders($this->extractRequestHeaders($options)),
			'Body:',
			$this->formatBody($options['body'] ?? null),
			''
		];

		$this->logger->debug(implode("\n", $lines));
	}

	private function logResponseSuccess(string $method, string $url, array $options, IResponse $response, string $body): void {
		if ($this->logger === null) {
			return;
		}

		$lines = [
			Application::APP_TAG . ' Response:',
			sprintf('Method: %s', $method),
			sprintf('RequestUri: %s', $url),
			sprintf('StatusCode: %d', $response->getStatusCode()),
			'Headers:',
			$this->formatHeaders($response->getHeaders()),
			'Body:',
			$this->formatBody($body),
			''
		];

		$this->logger->debug(implode("\n", $lines));
	}

	private function logRequestFailure(string $method, string $url, array $options, ClientExceptionInterface $e): void {
		if ($this->logger === null) {
			return;
		}

		$lines = [
			Application::APP_TAG . ' Request failed:',
			sprintf('Method: %s', $method),
			sprintf('RequestUri: %s', $url),
			'Headers:',
			$this->formatHeaders($this->extractRequestHeaders($options)),
			'Body:',
			$this->formatBody($options['body'] ?? null),
			sprintf('Error: %s', $e::class),
			sprintf('Message: %s', $e->getMessage()),
			''
		];

		$this->logger->debug(implode("\n", $lines));
	}

}

final class BufferedResponse implements IResponse {
	/**
	 * @param array<string, mixed> $headers
	 */
	public function __construct(
		private int $statusCode,
		private array $headers,
		private string $body,
	) {
	}

	#[\Override]
	public function getBody() {
		return $this->body;
	}

	#[\Override]
	public function getStatusCode(): int {
		return $this->statusCode;
	}

	#[\Override]
	public function getHeader(string $key): string {
		$headers = $this->headers[$key] ?? [];

		if (!is_array($headers) || $headers === []) {
			return '';
		}

		return (string)$headers[0];
	}

	#[\Override]
	public function getHeaders(): array {
		return $this->headers;
	}
}
