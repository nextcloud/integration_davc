<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service;

use OCA\DAVC\AppInfo\Application;
use OCP\IConfig;
use OCP\Security\ICrypto;

class ConfigurationService {
	/**
	 * Default System Configuration
	 * @var array
	 * */
	private const _SYSTEM = [
		'harmonization_interval' => '900',
		'force_certificate_verification' => '0',
		'forbid_insecure_http' => '0',
	];

	/**
	 * Default System Secure Parameters
	 * @var array
	 * */
	private const _SYSTEM_SECURE = [];

	/**
	 * Minimum allowed harmonization interval in seconds
	 * @var int
	 * */
	private const HARMONIZATION_INTERVAL_MINIMUM = 300;

	/**
	 * Default User Configuration
	 * @var array
	 * */
	private const _USER = [
		'contacts_presentation' => '',
		'events_timezone' => '',
		'events_attachment_path' => '/Calendar',
	];

	/**
	 * Default User Secure Parameters
	 * @var array
	 * */
	private const _USER_SECURE = [];

	public function __construct(
		private IConfig $_ds,
		private ICrypto $_cs,
	) {
	}

	/**
	 * Retrieves collection of system configuration parameters
	 *
	 * @param string $uid nextcloud user id
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return array of key/value pairs, of configuration parameter
	 */
	public function retrieveUser(string $uid, ?array $keys = null): array {

		// define parameters place holder
		$parameters = [];
		// evaluate if we are looking for specific parameters

		if (!isset($keys) || count($keys) == 0) {
			// retrieve all user configuration keys
			$keys = array_keys(self::_USER);
			// retrieve all user configuration values
			foreach ($keys as $entry) {
				$parameters[$entry] = $this->retrieveUserValue($uid, $entry);
			}
			// retrieve system parameters
			$parameters['system_timezone'] = date_default_timezone_get();
			$parameters['system_contacts'] = $this->isContactsAppAvailable();
			$parameters['system_events'] = $this->isCalendarAppAvailable();
			$parameters['user_id'] = $uid;
			// user default time zone
			$v = $this->_ds->getUserValue($uid, 'core', 'timezone');
			if (!empty($v)) {
				$parameters['user_timezone'] = $v;
			}
			// user events attachment path
			$v = $this->_ds->getUserValue($uid, 'dav', 'attachmentsFolder');
			if (!empty($v)) {
				$parameters['events_attachment_path'] = $v;
			}
		} else {
			// retrieve specific user configuration values
			foreach ($keys as $entry) {
				$parameters[$entry] = $this->retrieveUserValue($uid, $entry);
			}
		}
		// remove account secret
		if (isset($parameters['account_bauth_secret'])) {
			$parameters['account_bauth_secret'] = null;
		}
		// return configuration parameters
		return $parameters;
	}

	/**
	 * Deposit collection of system configuration parameters
	 *
	 * @param string $uid nextcloud user id
	 * @param array $parameters collection of key/value pairs, of parameters
	 *
	 * @return void
	 */
	public function depositUser($uid, array $parameters): void {

		// deposit system configuration parameters
		foreach ($parameters as $key => $value) {
			$this->depositUserValue($uid, $key, $value);
		}

	}

	/**
	 * Destroy collection of system configuration parameters
	 *
	 * @param string $uid nextcloud user id
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return void
	 */
	public function destroyUser(string $uid, ?array $keys = null): void {

		// evaluate if we are looking for specific parameters
		if (!isset($keys) || count($keys) == 0) {
			$keys = $this->_ds->getUserKeys($uid, Application::APP_ID);
		}
		// destroy system configuration parameter
		foreach ($keys as $entry) {
			$this->destroyUserValue($uid, $entry);
		}

	}

	/**
	 * Retrieves single system configuration parameter
	 *
	 * @param string $uid nextcloud user id
	 * @param string $key configuration parameter key
	 *
	 * @return string configuration parameter value
	 */
	public function retrieveUserValue(string $uid, string $key): string {

		// retrieve configured parameter value
		$value = $this->_ds->getUserValue($uid, Application::APP_ID, $key);
		// evaluate if value was returned
		if ($value != '') {
			// evaluate if parameter is on the secure list
			if (isset(self::_USER_SECURE[$key])) {
				$value = $this->_cs->decrypt($value);
			}
			// return configuration parameter value
			return $value;
		} else {
			// return default system configuration value
			return self::_USER[$key];
		}

	}

	/**
	 * Deposit single system configuration parameter
	 *
	 * @param string $uid nextcloud user id
	 * @param string $key configuration parameter key
	 * @param string $value configuration parameter value
	 *
	 * @return void
	 */
	public function depositUserValue(string $uid, string $key, string $value): void {

		// trim whitespace
		$value = trim($value);
		// evaluate if parameter is on the secure list
		if (isset(self::_USER_SECURE[$key]) && strlen($value) != 0) {
			$value = $this->_cs->encrypt($value);
		}
		// deposit user configuration parameter value
		$this->_ds->setUserValue($uid, Application::APP_ID, $key, $value);

	}

	/**
	 * Destroy single user configuration parameter
	 *
	 * @param string $uid nextcloud user id
	 * @param string $key configuration parameter keys
	 *
	 * @return void
	 */
	public function destroyUserValue(string $uid, string $key): void {

		// destroy user configuration parameter
		$this->_ds->deleteUserValue($uid, Application::APP_ID, $key);

	}

	/**
	 * Retrieves collection of system configuration parameters
	 *
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return array of key/value pairs, of configuration parameter
	 */
	public function retrieveSystem(?array $keys = null): array {

		// evaluate if we are looking for specific parameters
		if (!isset($keys) || count($keys) == 0) {
			$keys = array_keys(self::_SYSTEM);
		}
		// retrieve system configuration values
		$parameters = [];
		foreach ($keys as $entry) {
			$parameters[$entry] = $this->retrieveSystemValue($entry);
		}
		// return configuration parameters
		return $parameters;
	}

	/**
	 * Deposit collection of system configuration parameters
	 *
	 * @param array $parameters collection of key/value pairs, of parameters
	 *
	 * @return void
	 */
	public function depositSystem(array $parameters): void {

		// deposit system configuration parameters
		foreach ($parameters as $key => $value) {
			// normalise booleans and numerics to string for storage
			if (is_bool($value)) {
				$value = $value ? '1' : '0';
			}
			$this->depositSystemValue($key, (string)$value);
		}

	}

	/**
	 * Destroy collection of system configuration parameters
	 *
	 * @param array $keys collection of configuration parameter keys
	 *
	 * @return void
	 */
	public function destroySystem(?array $keys = null): void {

		// evaluate if we are looking for specific parameters
		if (!isset($keys) || count($keys) == 0) {
			$keys = $this->_ds->getAppKeys(Application::APP_ID);
		}
		// destroy system configuration parameter
		foreach ($keys as $entry) {
			$this->destroySystemValue($entry);
		}

	}

	/**
	 * Retrieves single system configuration parameter
	 *
	 * @param string $key configuration parameter key
	 *
	 * @return string configuration parameter value
	 */
	public function retrieveSystemValue(string $key): string {

		// retrieve configured parameter value
		$value = $this->_ds->getAppValue(Application::APP_ID, $key);
		// evaluate if value was returned
		if ($value != '') {
			if (isset(self::_SYSTEM_SECURE[$key])) {
				$value = $this->_cs->decrypt($value);
			}
			// return configuration parameter value
			return $value;
		} else {
			// return default system configuration value
			return self::_SYSTEM[$key];
		}

	}

	/**
	 * Deposit single system configuration parameter
	 *
	 * @param string $key configuration parameter key
	 * @param string $value configuration parameter value
	 *
	 * @return void
	 */
	public function depositSystemValue(string $key, string $value): void {

		// trim whitespace
		$value = trim($value);
		// evaluate if parameter is on the secure list
		if (isset(self::_SYSTEM_SECURE[$key]) && !empty($value)) {
			$value = $this->_cs->encrypt($value);
		}
		// deposit system configuration parameter value
		$this->_ds->setAppValue(Application::APP_ID, $key, $value);

	}

	/**
	 * Destroy single system configuration parameter
	 *
	 * @return void
	 */
	public function destroySystemValue(string $key): void {

		// destroy system configuration parameter
		$this->_ds->deleteAppValue(Application::APP_ID, $key);

	}

	/**
	 * Gets harmonization interval
	 *
	 * Interval, in seconds, between background harmonization runs.
	 *
	 * @return int harmonization interval in seconds (default 900)
	 */
	public function getHarmonizationInterval(): int {

		// retrieve value
		$interval = $this->retrieveSystemValue('harmonization_interval');
		// return value or default, never below the allowed minimum
		if (is_numeric($interval)) {
			return max(self::HARMONIZATION_INTERVAL_MINIMUM, intval($interval));
		} else {
			return intval(self::_SYSTEM['harmonization_interval']);
		}

	}

	/**
	 * Sets harmonization interval
	 *
	 * @param int $interval harmonization interval in seconds
	 *
	 * @return void
	 */
	public function setHarmonizationInterval(int $interval): void {

		// set value, never below the allowed minimum
		$this->depositSystemValue('harmonization_interval', (string)max(self::HARMONIZATION_INTERVAL_MINIMUM, $interval));

	}

	/**
	 * Gets administrator transport security policy: force certificate verification
	 *
	 * @return bool true if certificate verification is enforced for all connections
	 */
	public function getForceCertificateVerification(): bool {

		return $this->retrieveSystemValue('force_certificate_verification') === '1';
	}

	/**
	 * Sets administrator transport security policy: force certificate verification
	 *
	 * @param bool $enabled
	 *
	 * @return void
	 */
	public function setForceCertificateVerification(bool $enabled): void {

		$this->depositSystemValue('force_certificate_verification', $enabled ? '1' : '0');

	}

	/**
	 * Gets administrator transport security policy: forbid insecure (http) connections
	 *
	 * @return bool true if insecure (http) connections are forbidden
	 */
	public function getForbidInsecureHttp(): bool {

		return $this->retrieveSystemValue('forbid_insecure_http') === '1';
	}

	/**
	 * Determine if the administrator transport security policy permits connections using a protocol
	 *
	 * @param string|null $protocol connection protocol (e.g. http, https)
	 *
	 * @return bool
	 */
	public function isTransportPermitted(?string $protocol): bool {

		return !$this->getForbidInsecureHttp() || strtolower((string)$protocol) !== 'http';
	}

	/**
	 * Sets administrator transport security policy: forbid insecure (http) connections
	 *
	 * @param bool $enabled
	 *
	 * @return void
	 */
	public function setForbidInsecureHttp(bool $enabled): void {

		$this->depositSystemValue('forbid_insecure_http', $enabled ? '1' : '0');

	}

	/**
	 * retrieve contacts app status
	 *
	 * @return bool
	 */
	public function isContactsAppAvailable(): bool {

		// retrieve contacts app status
		$status = $this->_ds->getAppValue('contacts', 'enabled');
		// evaluate status
		if ($status == 'yes') {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * retrieve calendar app status
	 *
	 * @return bool
	 */
	public function isCalendarAppAvailable(): bool {

		// retrieve calendar app status
		$status = $this->_ds->getAppValue('calendar', 'enabled');
		// evaluate status
		if ($status == 'yes') {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * encrypt string
	 *
	 * @return string
	 */
	public function encrypt(string $value): string {

		return $this->_cs->encrypt($value);
	}

	/**
	 * decrypt string
	 *
	 * @return string
	 */
	public function decrypt(string $value): string {

		return $this->_cs->decrypt($value);
	}

}
