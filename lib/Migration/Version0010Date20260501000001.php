<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAVC\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @psalm-api
 */
class Version0010Date20260501000001 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// check if the table already exists
		if ($schema->hasTable('davc_services')) {
			return;
		}
		// create the table
		$table = $schema->createTable('davc_services');
		// id
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true
		]);
		// user identifier
		$table->addColumn('uid', Types::STRING, [
			'length' => 255,
			'notnull' => true
		]);
		// universally unique identifier
		$table->addColumn('uuid', Types::STRING, [
			'length' => 36,
			'notnull' => true
		]);
		// service label
		$table->addColumn('label', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// service location protocol
		$table->addColumn('location_protocol', Types::STRING, [
			'length' => 8,
			'notnull' => true
		]);
		// service location host
		$table->addColumn('location_host', Types::STRING, [
			'length' => 255,
			'notnull' => true
		]);
		// service location port
		$table->addColumn('location_port', Types::INTEGER, [
			'notnull' => true
		]);
		// service location path
		$table->addColumn('location_path', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// dav principal url
		$table->addColumn('principal_url', Types::TEXT, [
			'notnull' => false
		]);
		// caldav home set
		$table->addColumn('calendars_url', Types::TEXT, [
			'notnull' => false
		]);
		// carddav home set
		$table->addColumn('addressbooks_url', Types::TEXT, [
			'notnull' => false
		]);
		// service location security
		$table->addColumn('location_security', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true
		]);
		// service authentication
		$table->addColumn('auth', Types::STRING, [
			'length' => 8,
			'notnull' => true
		]);
		// service authentication basic id
		$table->addColumn('bauth_id', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// service authentication basic secret
		$table->addColumn('bauth_secret', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// service authentication basic location
		$table->addColumn('bauth_location', Types::TEXT, [
			'notnull' => false
		]);
		// service authentication bearer id
		$table->addColumn('oauth_id', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// service authentication bearer access token
		$table->addColumn('oauth_access_token', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// service authentication bearer access location
		$table->addColumn('oauth_access_location', Types::TEXT, [
			'notnull' => false
		]);
		// service authentication bearer access expiry
		$table->addColumn('oauth_access_expiry', Types::INTEGER, [
			'notnull' => false
		]);
		// service authentication bearer refresh token
		$table->addColumn('oauth_refresh_token', Types::STRING, [
			'length' => 255,
			'notnull' => false
		]);
		// service authentication bearer refresh location
		$table->addColumn('oauth_refresh_location', Types::TEXT, [
			'notnull' => false
		]);
		// service enabled
		$table->addColumn('enabled', Types::BOOLEAN, [
			'notnull' => false
		]);
		// service connected
		$table->addColumn('connected', Types::BOOLEAN, [
			'notnull' => false
		]);
		// debug mode
		$table->addColumn('debug', Types::BOOLEAN, [
			'notnull' => false
		]);
		// harmonization state
		$table->addColumn('harmonization_state', Types::INTEGER, [
			'notnull' => false
		]);
		// harmonization start
		$table->addColumn('harmonization_start', Types::INTEGER, [
			'notnull' => false
		]);
		// harmonization end
		$table->addColumn('harmonization_end', Types::INTEGER, [
			'notnull' => false
		]);
		// subscription code
		$table->addColumn('subscription_code', Types::STRING, [
			'length' => 64,
			'notnull' => false
		]);
		// contacts mode
		$table->addColumn('contacts_mode', Types::STRING, [
			'length' => 8,
			'notnull' => false
		]);
		// calendars mode
		$table->addColumn('events_mode', Types::STRING, [
			'length' => 8,
			'notnull' => false
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['uid'], 'davc_service_index_1'); // by user id

		return $schema;
	}
}
