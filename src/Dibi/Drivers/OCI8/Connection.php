<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers\OCI8;

use Dibi;
use Dibi\Drivers;
use function in_array, is_resource;


/**
 * The driver for Oracle database.
 *
 * Driver options:
 *   - database => the name of the local Oracle instance or the name of the entry in tnsnames.ora
 *   - username (or user)
 *   - password (or pass)
 *   - charset => character encoding to set
 *   - schema => alters session schema
 *   - nativeDate => use native date format (defaults to true)
 *   - resource (resource) => existing connection resource
 *   - persistent => Creates persistent connections with oci_pconnect instead of oci_new_connect
 */
class Connection implements Drivers\Connection
{
	/** @var resource */
	private $connection;
	private bool $autocommit = true;
	private bool $nativeDate;
	private ?int $affectedRows;


	/** @throws Dibi\NotSupportedException */
	public function __construct(array $config)
	{
		if (!extension_loaded('oci8')) {
			throw new Dibi\NotSupportedException("PHP extension 'oci8' is not loaded.");
		}

		$foo = &$config['charset'];
		$this->nativeDate = $config['nativeDate'] ?? true;

		if (isset($config['resource'])) {
			$this->connection = $config['resource'];
		} elseif (empty($config['persistent'])) {
			$this->connection = @oci_new_connect($config['username'], $config['password'], $config['database'], $config['charset']); // intentionally @
		} else {
			$this->connection = @oci_pconnect($config['username'], $config['password'], $config['database'], $config['charset']); // intentionally @
		}

		if (!$this->connection) {
			$err = oci_error();
			throw new Dibi\DriverException($err['message'], $err['code']);
		}

		if (isset($config['schema'])) {
			$this->query('ALTER SESSION SET CURRENT_SCHEMA=' . $config['schema']);
		}
	}


	/**
	 * Disconnects from a database.
	 */
	public function disconnect(): void
	{
		@oci_close($this->connection); // @ - connection can be already disconnected
	}


	/**
	 * Executes the SQL query.
	 * @throws Dibi\DriverException
	 */
	public function query(string $sql): ?Result
	{
		$this->affectedRows = null;
		$res = oci_parse($this->connection, $sql);
		if ($res) {
			@oci_execute($res, $this->autocommit ? OCI_COMMIT_ON_SUCCESS : OCI_DEFAULT);
			$err = oci_error($res);
			if ($err) {
				throw static::createException($err['message'], $err['code'], $sql);

			} elseif (is_resource($res)) {
				$this->affectedRows = Dibi\Helpers::false2Null(oci_num_rows($res));
				return oci_num_fields($res)
					? $this->createResultDriver($res)
					: null;
			}
		} else {
			$err = oci_error($this->connection);
			throw new Dibi\DriverException($err['message'], $err['code'], $sql);
		}

		return null;
	}


	public static function createException(string $message, $code, string $sql): Dibi\DriverException
	{
		if (in_array($code, [1, 2299, 38911], true)) {
			return new Dibi\UniqueConstraintViolationException($message, $code, $sql);

		} elseif (in_array($code, [1400], true)) {
			return new Dibi\NotNullConstraintViolationException($message, $code, $sql);

		} elseif (in_array($code, [2266, 2291, 2292], true)) {
			return new Dibi\ForeignKeyConstraintViolationException($message, $code, $sql);

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
		$row = $this->query("SELECT $sequence.CURRVAL AS ID FROM DUAL")->fetch(true);
		return isset($row['ID']) ? Dibi\Helpers::intVal($row['ID']) : null;
	}


	/**
	 * Begins a transaction (if supported).
	 */
	public function begin(?string $savepoint = null): void
	{
		$this->autocommit = false;
	}


	/**
	 * Commits statements in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function commit(?string $savepoint = null): void
	{
		if (!oci_commit($this->connection)) {
			$err = oci_error($this->connection);
			throw new Dibi\DriverException($err['message'], $err['code']);
		}

		$this->autocommit = true;
	}


	/**
	 * Rollback changes in a transaction.
	 * @throws Dibi\DriverException
	 */
	public function rollback(?string $savepoint = null): void
	{
		if (!oci_rollback($this->connection)) {
			$err = oci_error($this->connection);
			throw new Dibi\DriverException($err['message'], $err['code']);
		}

		$this->autocommit = true;
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
		return new Drivers\Engines\OracleEngine($this);
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
		return "'" . str_replace("'", "''", $value) . "'"; // TODO: not tested
	}


	public function escapeBinary(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'"; // TODO: not tested
	}
}
