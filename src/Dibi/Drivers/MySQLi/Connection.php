<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers\MySQLi;

use Dibi;
use Dibi\Drivers;
use function in_array;
use const MYSQLI_REPORT_OFF, MYSQLI_STORE_RESULT, MYSQLI_USE_RESULT, PREG_SET_ORDER;


/**
 * The driver for MySQL database.
 *
 * Driver options:
 *   - host => the MySQL server host name
 *   - port (int) => the port number to attempt to connect to the MySQL server
 *   - socket => the socket or named pipe
 *   - username (or user)
 *   - password (or pass)
 *   - database => the database name to select
 *   - options (array) => array of driver specific constants (MYSQLI_*) and values {@see mysqli_options}
 *   - flags (int) => driver specific constants (MYSQLI_CLIENT_*) {@see mysqli_real_connect}
 *   - charset => character encoding to set (default is utf8)
 *   - persistent (bool) => try to find a persistent link?
 *   - unbuffered (bool) => sends query without fetching and buffering the result rows automatically?
 *   - sqlmode => see http://dev.mysql.com/doc/refman/5.0/en/server-sql-mode.html
 *   - resource (mysqli) => existing connection resource
 */
class Connection implements Drivers\Connection
{
	public const ErrorAccessDenied = 1045;
	public const ErrorDuplicateEntry = 1062;
	public const ErrorDataTruncated = 1265;

	#[\Deprecated('use MySqliDriver::ErrorAccessDenied')]
	public const ERROR_ACCESS_DENIED = self::ErrorAccessDenied;

	#[\Deprecated('use MySqliDriver::ErrorDuplicateEntry')]
	public const ERROR_DUPLICATE_ENTRY = self::ErrorDuplicateEntry;

	#[\Deprecated('use MySqliDriver::ErrorDataTruncated')]
	public const ERROR_DATA_TRUNCATED = self::ErrorDataTruncated;

	private \mysqli $connection;
	private bool $buffered = false;


	/** @throws Dibi\NotSupportedException */
	public function __construct(array $config)
	{
		if (!extension_loaded('mysqli')) {
			throw new Dibi\NotSupportedException("PHP extension 'mysqli' is not loaded.");
		}

		mysqli_report(MYSQLI_REPORT_OFF);
		if (isset($config['resource']) && $config['resource'] instanceof \mysqli) {
			$this->connection = $config['resource'];

		} else {
			// default values
			$config += [
				'charset' => 'utf8',
				'timezone' => date('P'),
				'username' => ini_get('mysqli.default_user'),
				'password' => ini_get('mysqli.default_pw'),
				'socket' => (string) ini_get('mysqli.default_socket'),
				'port' => null,
			];
			if (!isset($config['host'])) {
				$host = ini_get('mysqli.default_host');
				if ($host) {
					$config['host'] = $host;
					$config['port'] = (int) ini_get('mysqli.default_port');
				} else {
					$config['host'] = null;
					$config['port'] = null;
				}
			}

			$foo = &$config['flags'];
			$foo = &$config['database'];

			$this->connection = mysqli_init();
			if (isset($config['options'])) {
				foreach ($config['options'] as $key => $value) {
					$this->connection->options($key, $value);
				}
			}

			@$this->connection->real_connect( // intentionally @
				(empty($config['persistent']) ? '' : 'p:') . $config['host'],
				$config['username'],
				$config['password'] ?? '',
				$config['database'] ?? '',
				$config['port'] ?? 0,
				$config['socket'],
				$config['flags'] ?? 0,
			);

			if ($this->connection->connect_errno) {
				throw new Dibi\DriverException($this->connection->connect_error, $this->connection->connect_errno);
			}
		}

		if (isset($config['charset'])) {
			if (!@$this->connection->set_charset($config['charset'])) {
				$this->query("SET NAMES '$config[charset]'");
			}
		}

		if (isset($config['sqlmode'])) {
			$this->query("SET sql_mode='$config[sqlmode]'");
		}

		if (isset($config['timezone'])) {
			$this->query("SET time_zone='$config[timezone]'");
		}

		$this->buffered = empty($config['unbuffered']);
	}


	/**
	 * Disconnects from a database.
	 */
	public function disconnect(): void
	{
		@$this->connection->close(); // @ - connection can be already disconnected
	}


	/**
	 * Pings a server connection, or tries to reconnect if the connection has gone down.
	 */
	public function ping(): bool
	{
		return $this->connection->ping();
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Result
	{
		$res = @$this->connection->query($sql, $this->buffered ? MYSQLI_STORE_RESULT : MYSQLI_USE_RESULT); // intentionally @

		if ($code = mysqli_errno($this->connection)) {
			throw static::createException(mysqli_error($this->connection), $code, $sql);

		} elseif ($res instanceof \mysqli_result) {
			return $this->createResultDriver($res);
		}

		return null;
	}


	public static function createException(string $message, int|string $code, string $sql): Dibi\DriverException
	{
		if (in_array($code, [1216, 1217, 1451, 1452, 1701], true)) {
			return new Dibi\ForeignKeyConstraintViolationException($message, $code, $sql);

		} elseif (in_array($code, [1062, 1557, 1569, 1586], true)) {
			return new Dibi\UniqueConstraintViolationException($message, $code, $sql);

		} elseif (in_array($code, [1048, 1121, 1138, 1171, 1252, 1263, 1566], true)) {
			return new Dibi\NotNullConstraintViolationException($message, $code, $sql);

		} else {
			return new Dibi\DriverException($message, $code, $sql);
		}
	}


	/**
	 * Retrieves information about the most recently executed query.
	 */
	public function getInfo(): array
	{
		$res = [];
		preg_match_all('#(.+?): +(\d+) *#', $this->connection->info, $matches, PREG_SET_ORDER);
		if (preg_last_error()) {
			throw new Dibi\PcreException;
		}

		foreach ($matches as $m) {
			$res[$m[1]] = (int) $m[2];
		}

		return $res;
	}


	/**
	 * Gets the number of affected rows by the last INSERT, UPDATE or DELETE query.
	 */
	public function getAffectedRows(): ?int
	{
		return $this->connection->affected_rows === -1
			? null
			: $this->connection->affected_rows;
	}


	/**
	 * Retrieves the ID generated for an AUTO_INCREMENT column by the previous INSERT query.
	 */
	public function getInsertId(?string $sequence): ?int
	{
		return $this->connection->insert_id ?: null;
	}


	/**
	 * Begins a transaction (if supported).
	 * @throws Dibi\DriverException
	 */
	public function begin(?string $savepoint = null): void
	{
		$this->query($savepoint ? "SAVEPOINT $savepoint" : 'START TRANSACTION');
	}


	/**
	 * Commits statements in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function commit(?string $savepoint = null): void
	{
		$this->query($savepoint ? "RELEASE SAVEPOINT $savepoint" : 'COMMIT');
	}


	/**
	 * Rollback changes in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function rollback(?string $savepoint = null): void
	{
		$this->query($savepoint ? "ROLLBACK TO SAVEPOINT $savepoint" : 'ROLLBACK');
	}


	/**
	 * Returns the connection resource.
	 */
	public function getResource(): ?\mysqli
	{
		try {
			return @$this->connection->thread_id ? $this->connection : null;
		} catch (\Throwable $e) {
			return null;
		}
	}


	/**
	 * Returns the connection reflector.
	 */
	public function getReflector(): Drivers\Engine
	{
		return new Drivers\Engines\MySQLEngine($this);
	}


	/**
	 * Result set driver factory.
	 */
	public function createResultDriver(\mysqli_result $result): Result
	{
		return new Result($result, $this->buffered);
	}


	/********************* SQL ****************d*g**/


	/**
	 * Encodes data for use in a SQL statement.
	 */
	public function escapeText(string $value): string
	{
		return "'" . $this->connection->escape_string($value) . "'";
	}


	public function escapeBinary(string $value): string
	{
		return "_binary'" . $this->connection->escape_string($value) . "'";
	}
}
