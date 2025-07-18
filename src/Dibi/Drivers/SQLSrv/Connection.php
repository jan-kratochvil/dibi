<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers\SQLSrv;

use Dibi;
use Dibi\Drivers;
use Dibi\Helpers;
use function is_resource, sprintf;


/**
 * The driver for Microsoft SQL Server and SQL Azure databases.
 *
 * Driver options:
 *   - host => the MS SQL server host name. It can also include a port number (hostname:port)
 *   - username (or user)
 *   - password (or pass)
 *   - database => the database name to select
 *   - options (array) => connection options {@link https://msdn.microsoft.com/en-us/library/cc296161(SQL.90).aspx}
 *   - charset => character encoding to set (default is UTF-8)
 *   - resource (resource) => existing connection resource
 */
class Connection implements Drivers\Connection
{
	/** @var resource */
	private $connection;
	private ?int $affectedRows;


	/** @throws Dibi\NotSupportedException */
	public function __construct(array $config)
	{
		if (!extension_loaded('sqlsrv')) {
			throw new Dibi\NotSupportedException("PHP extension 'sqlsrv' is not loaded.");
		}

		Helpers::alias($config, 'options|UID', 'username');
		Helpers::alias($config, 'options|PWD', 'password');
		Helpers::alias($config, 'options|Database', 'database');
		Helpers::alias($config, 'options|CharacterSet', 'charset');

		if (isset($config['resource'])) {
			$this->connection = $config['resource'];
			if (!is_resource($this->connection)) {
				throw new \InvalidArgumentException("Configuration option 'resource' is not resource.");
			}
		} else {
			$options = $config['options'];

			// Default values
			$options['CharacterSet'] ??= 'UTF-8';
			$options['PWD'] = (string) $options['PWD'];
			$options['UID'] = (string) $options['UID'];
			$options['Database'] = (string) $options['Database'];

			sqlsrv_configure('WarningsReturnAsErrors', 0);
			$this->connection = sqlsrv_connect($config['host'], $options);
			if (!is_resource($this->connection)) {
				$info = sqlsrv_errors(SQLSRV_ERR_ERRORS);
				throw new Dibi\DriverException($info[0]['message'], $info[0]['code']);
			}

			sqlsrv_configure('WarningsReturnAsErrors', 1);
		}
	}


	/**
	 * Disconnects from a database.
	 */
	public function disconnect(): void
	{
		@sqlsrv_close($this->connection); // @ - connection can be already disconnected
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Result
	{
		$this->affectedRows = null;
		$res = sqlsrv_query($this->connection, $sql);

		if ($res === false) {
			$info = sqlsrv_errors();
			throw new Dibi\DriverException($info[0]['message'], $info[0]['code'], $sql);

		} elseif (is_resource($res)) {
			$this->affectedRows = Helpers::false2Null(sqlsrv_rows_affected($res));
			return sqlsrv_num_fields($res)
				? $this->createResultDriver($res)
				: null;
		}

		return null;
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
		$res = sqlsrv_query($this->connection, 'SELECT SCOPE_IDENTITY()');
		if (is_resource($res)) {
			$row = sqlsrv_fetch_array($res, SQLSRV_FETCH_NUMERIC);
			return Dibi\Helpers::intVal($row[0]);
		}

		return null;
	}


	/**
	 * Begins a transaction (if supported).
	 * @throws Dibi\DriverException
	 */
	public function begin(?string $savepoint = null): void
	{
		sqlsrv_begin_transaction($this->connection);
	}


	/**
	 * Commits statements in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function commit(?string $savepoint = null): void
	{
		sqlsrv_commit($this->connection);
	}


	/**
	 * Rollback changes in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function rollback(?string $savepoint = null): void
	{
		sqlsrv_rollback($this->connection);
	}


	/**
	 * Returns the connection resource.
	 * @return resource|null
	 */
	public function getResource(): mixed
	{
		return is_resource($this->connection) ? $this->connection : null;
	}


	/**
	 * Returns the connection reflector.
	 */
	public function getReflector(): Drivers\Engine
	{
		return new Drivers\Engines\SQLServerEngine($this);
	}


	/**
	 * Result set driver factory.
	 * @param  resource  $resource
	 */
	public function createResultDriver($resource): Result
	{
		return new Result($resource);
	}


	/********************* SQL ****************d*g**/


	/**
	 * Encodes data for use in a SQL statement.
	 */
	public function escapeText(string $value): string
	{
		return "N'" . str_replace("'", "''", $value) . "'";
	}


	public function escapeBinary(string $value): string
	{
		return '0x' . bin2hex($value);
	}
}
