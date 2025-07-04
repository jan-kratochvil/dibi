<?php

/**
 * This file is part of the Dibi, smart database abstraction layer (https://dibiphp.com)
 * Copyright (c) 2005 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Dibi\Drivers\PgSQL;

use Dibi\Drivers;
use Dibi\Helpers;
use PgSql;
use function is_resource;


/**
 * The driver for PostgreSQL result set.
 */
class Result implements Drivers\Result
{
	public function __construct(
		private readonly PgSql\Result $resultSet,
	) {
	}


	/**
	 * Returns the number of rows in a result set.
	 */
	public function getRowCount(): int
	{
		return pg_num_rows($this->resultSet);
	}


	/**
	 * Fetches the row at current position and moves the internal cursor to the next position.
	 * @param  bool  $assoc  true for associative array, false for numeric
	 */
	public function fetch(bool $assoc): ?array
	{
		return Helpers::false2Null(pg_fetch_array($this->resultSet, null, $assoc ? PGSQL_ASSOC : PGSQL_NUM));
	}


	/**
	 * Moves cursor position without fetching row.
	 */
	public function seek(int $row): bool
	{
		return pg_result_seek($this->resultSet, $row);
	}


	/**
	 * Frees the resources allocated for this result set.
	 */
	public function free(): void
	{
		pg_free_result($this->resultSet);
	}


	/**
	 * Returns metadata for all columns in a result set.
	 */
	public function getResultColumns(): array
	{
		$count = pg_num_fields($this->resultSet);
		$columns = [];
		for ($i = 0; $i < $count; $i++) {
			$row = [
				'name' => pg_field_name($this->resultSet, $i),
				'table' => pg_field_table($this->resultSet, $i),
				'nativetype' => pg_field_type($this->resultSet, $i),
			];
			$row['fullname'] = $row['table']
				? $row['table'] . '.' . $row['name']
				: $row['name'];
			$columns[] = $row;
		}

		return $columns;
	}


	/**
	 * Returns the result set resource.
	 */
	public function getResultResource(): PgSql\Result
	{
		return $this->resultSet;
	}


	/**
	 * Decodes data from result set.
	 */
	public function unescapeBinary(string $value): string
	{
		return pg_unescape_bytea($value);
	}
}
