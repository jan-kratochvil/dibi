<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers\PgSQL;

use Dibi;
use Dibi\Drivers;
use Dibi\Helpers;
use PgSql;
use function in_array, is_array, is_resource, strlen;


/**
 * The driver for PostgreSQL database.
 *
 * Driver options:
 *   - host, hostaddr, port, dbname, user, password, connect_timeout, options, sslmode, service => see PostgreSQL API
 *   - string => or use connection string
 *   - schema => the schema search path
 *   - charset => character encoding to set (default is utf8)
 *   - persistent (bool) => try to find a persistent link?
 *   - resource (PgSql\Connection) => existing connection resource
 *   - connect_type (int) => see pg_connect()
 */
class Connection implements Drivers\Connection
{
	private PgSql\Connection $connection;
	private ?int $affectedRows;


	/** @throws Dibi\NotSupportedException */
	public function __construct(array $config)
	{
		if (!extension_loaded('pgsql')) {
			throw new Dibi\NotSupportedException("PHP extension 'pgsql' is not loaded.");
		}

		$error = null;
		if (isset($config['resource'])) {
			$this->connection = $config['resource'];

		} else {
			$config += [
				'charset' => 'utf8',
			];
			if (isset($config['string'])) {
				$string = $config['string'];
			} else {
				$string = '';
				Helpers::alias($config, 'user', 'username');
				Helpers::alias($config, 'dbname', 'database');
				foreach (['host', 'hostaddr', 'port', 'dbname', 'user', 'password', 'connect_timeout', 'options', 'sslmode', 'service'] as $key) {
					if (isset($config[$key])) {
						$string .= $key . '=' . $config[$key] . ' ';
					}
				}
			}

			$connectType = $config['connect_type'] ?? PGSQL_CONNECT_FORCE_NEW;

			set_error_handler(function (int $severity, string $message) use (&$error) {
				$error = $message;
			});
			$this->connection = empty($config['persistent'])
				? pg_connect($string, $connectType)
				: pg_pconnect($string, $connectType);
			restore_error_handler();
		}

		if (!$this->connection instanceof PgSql\Connection) {
			throw new Dibi\DriverException($error ?: 'Connecting error.');
		}

		pg_set_error_verbosity($this->connection, PGSQL_ERRORS_VERBOSE);

		if (isset($config['charset']) && pg_set_client_encoding($this->connection, $config['charset'])) {
			throw static::createException(pg_last_error($this->connection));
		}

		if (isset($config['schema'])) {
			$this->query('SET search_path TO "' . implode('", "', (array) $config['schema']) . '"');
		}
	}


	/**
	 * Disconnects from a database.
	 */
	public function disconnect(): void
	{
		@pg_close($this->connection); // @ - connection can be already disconnected
	}


	/**
	 * Pings database.
	 */
	public function ping(): bool
	{
		return pg_ping($this->connection);
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Result
	{
		$this->affectedRows = null;
		$res = @pg_query($this->connection, $sql); // intentionally @

		if ($res === false) {
			throw static::createException(pg_last_error($this->connection), null, $sql);

		} elseif ($res instanceof PgSql\Result) {
			$this->affectedRows = Helpers::false2Null(pg_affected_rows($res));
			if (pg_num_fields($res)) {
				return $this->createResultDriver($res);
			}
		}

		return null;
	}


	public static function createException(string $message, $code = null, ?string $sql = null): Dibi\DriverException
	{
		if ($code === null && preg_match('#^ERROR:\s+(\S+):\s*#', $message, $m)) {
			$code = $m[1];
			$message = substr($message, strlen($m[0]));
		}

		if ($code === '0A000' && str_contains($message, 'truncate')) {
			return new Dibi\ForeignKeyConstraintViolationException($message, $code, $sql);

		} elseif ($code === '23502') {
			return new Dibi\NotNullConstraintViolationException($message, $code, $sql);

		} elseif ($code === '23503') {
			return new Dibi\ForeignKeyConstraintViolationException($message, $code, $sql);

		} elseif ($code === '23505') {
			return new Dibi\UniqueConstraintViolationException($message, $code, $sql);

		} else {
			return new Dibi\DriverException($message, $code, $sql);
		}
	}


	/**
	 * Gets the number of affected rows by the last INSERT, UPDATE or DELETE query.
	 */
	public function getAffectedRows(): ?int
	{
		return $this->affectedRows;
	}


	/**
	 * Retrieves the ID generated for an AUTO_INCREMENT column by the previous INSERT query.
	 */
	public function getInsertId(?string $sequence): ?int
	{
		$res = $sequence === null
			? $this->query('SELECT LASTVAL()') // PostgreSQL 8.1 is needed
			: $this->query("SELECT CURRVAL('$sequence')");

		if (!$res) {
			return null;
		}

		$row = $res->fetch(false);
		return is_array($row) ? (int) $row[0] : null;
	}


	/**
	 * Begins a transaction (if supported).
	 * @throws Dibi\DriverException
	 */
	public function begin(?string $savepoint = null): void
	{
		$this->query($savepoint ? "SAVEPOINT {$this->escapeIdentifier($savepoint)}" : 'START TRANSACTION');
	}


	/**
	 * Commits statements in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function commit(?string $savepoint = null): void
	{
		$this->query($savepoint ? "RELEASE SAVEPOINT {$this->escapeIdentifier($savepoint)}" : 'COMMIT');
	}


	/**
	 * Rollback changes in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function rollback(?string $savepoint = null): void
	{
		$this->query($savepoint ? "ROLLBACK TO SAVEPOINT {$this->escapeIdentifier($savepoint)}" : 'ROLLBACK');
	}


	/**
	 * Is in transaction?
	 */
	public function inTransaction(): bool
	{
		return !in_array(pg_transaction_status($this->connection), [PGSQL_TRANSACTION_UNKNOWN, PGSQL_TRANSACTION_IDLE], true);
	}


	/**
	 * Returns the connection resource.
	 */
	public function getResource(): PgSql\Connection
	{
		return $this->connection;
	}


	/**
	 * Returns the connection reflector.
	 */
	public function getReflector(): Drivers\Engine
	{
		return new Drivers\Engines\PostgreSQLEngine($this);
	}


	/**
	 * Result set driver factory.
	 */
	public function createResultDriver(PgSql\Result $resource): Result
	{
		return new Result($resource);
	}


	/********************* SQL ****************d*g**/


	/**
	 * Encodes data for use in a SQL statement.
	 */
	public function escapeText(string $value): string
	{
		if (!$this->getResource()) {
			throw new Dibi\Exception('Lost connection to server.');
		}

		return "'" . pg_escape_string($this->connection, $value) . "'";
	}


	public function escapeBinary(string $value): string
	{
		if (!$this->getResource()) {
			throw new Dibi\Exception('Lost connection to server.');
		}

		return "'" . pg_escape_bytea($this->connection, $value) . "'";
	}
}
