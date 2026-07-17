<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Service\Remote;

class RemoteConvert {

	public static function extractUrlPath(?string $url): ?string {
		if ($url === null || $url === '') {
			return null;
		}
		$parsed = parse_url($url);
		if (isset($parsed['host'])) {
			return $parsed['path'] ?? null;
		}
		return $url;
	}

	public static function extractPermissions(array $acl): array {
		$permissions = [];
		foreach ($acl as $entry) {
			if (!is_array($entry) || ($entry['name'] ?? null) !== '{DAV:}ace' || !is_array($entry['value'] ?? null)) {
				continue;
			}

			$principal = null;
			$privileges = [];
			$protected = false;

			foreach ($entry['value'] as $aceElement) {
				if (!is_array($aceElement)) {
					continue;
				}

				switch ($aceElement['name'] ?? null) {
					case '{DAV:}principal':
						$principal = self::extractPrincipal($aceElement['value'] ?? null);
						break;

					case '{DAV:}grant':
						$privileges = self::extractPrivilegeNames($aceElement['value'] ?? null);
						break;
				}
			}

			foreach ($privileges as $privilege) {
				$permissions[$principal][] = $privilege;
			}
		}

		return $permissions;
	}

	public static function extractPrincipal($owner): ?string {
		if (is_string($owner) && $owner !== '') {
			return $owner;
		}

		if (!is_array($owner)) {
			return null;
		}

		foreach ($owner as $item) {
			if (!is_array($item)) {
				continue;
			}

			if (($item['name'] ?? null) === RemoteClient::DAV_HREF && is_string($item['value'] ?? null) && $item['value'] !== '') {
				return $item['value'];
			}

			if (isset($item['value']) && is_array($item['value'])) {
				$principal = self::extractPrincipal($item['value']);
				if ($principal !== null) {
					return $principal;
				}
			}
		}

		return null;
	}

	/**
	 * @return list<string>|null component names (e.g. VEVENT, VTODO) or null when the property is absent
	 */
	public static function extractSupportedComponents(mixed $value): ?array {
		if (!is_array($value)) {
			return null;
		}

		$components = [];
		foreach ($value as $element) {
			if (!is_array($element) || ($element['name'] ?? null) !== RemoteClient::CALDAV_COMPONENT) {
				continue;
			}

			$name = $element['attributes']['name'] ?? null;
			if (is_string($name) && $name !== '') {
				$components[] = $name;
			}
		}

		return array_values(array_unique($components));
	}

	public static function extractPrivilegeNames(mixed $grant): array {
		if (!is_array($grant)) {
			return [];
		}

		$privileges = [];
		foreach ($grant as $grantElement) {
			if (!is_array($grantElement) || ($grantElement['name'] ?? null) !== '{DAV:}privilege' || !is_array($grantElement['value'] ?? null)) {
				continue;
			}

			foreach ($grantElement['value'] as $privilegeElement) {
				if (!is_array($privilegeElement)) {
					continue;
				}

				$name = $privilegeElement['name'] ?? null;
				if (is_string($name) && $name !== '') {
					$privileges[] = $name;
				}
			}
		}

		return array_values(array_unique($privileges));
	}

}
