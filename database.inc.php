<?php
/**
 * Database functions
 *
 * FJ remove DatabaseType (oracle and mysql cases)
 *
 * @package RosarioSIS
 */

/**
 * Establish DB connection
 *
 * @global $DatabaseServer   Database server hostname
 * @global $DatabaseUsername Database username
 * @global $DatabasePassword Database password
 * @global $DatabaseName     Database name
 * @global $DatabasePort     Database port
 * @see config.inc.php file for globals definitions
 *
 * @return PostgreSQL connection resource
 */
function db_start()
{
	global $DatabaseServer,
		$DatabaseUsername,
		$DatabasePassword,
		$DatabaseName,
		$DatabasePort;

	/**
	 * Fix pg_connect(): Unable to connect to PostgreSQL server:
	 * could not connect to server:
	 * No such file or directory Is the server running locally
	 * and accepting connections on Unix domain socket "/tmp/.s.PGSQL.5432"
	 *
	 * Always set host, force TCP.
	 *
	 * @since 3.5.2
	 */
	$connectstring = 'host=' . $DatabaseServer . ' ';

	if ( $DatabasePort !== '5432' )
	{
		$connectstring .= 'port=' . $DatabasePort . ' ';
	}

	$connectstring .= 'dbname=' . $DatabaseName . ' user=' . $DatabaseUsername;

	if ( $DatabasePassword !== '' )
	{
		$connectstring .= ' password=' . $DatabasePassword;
	}

	$db_connection = pg_connect( $connectstring );

	// Error code for both.
	if ( $db_connection === false )
	{
		// TRANSLATION: do NOT translate these since error messages need to stay in English for technical support.
		db_show_error(
			'',
			sprintf( "Could not Connect to Database Server '%s'.", $DatabaseServer ),
			error_get_last()['message']
		);
	}

	return $db_connection;
}

/**
 * Execute DB query
 * pg_exec wrapper, dies on error.
 *
 * @since 5.1
 * @since 5.2 Add $show_error optional param.
 * @since 8.1 Remove @ error control operator on pg_exec: allow PHP Warning
 * @since 9.0 Fix PHP8.1 deprecated use PostgreSQL $db_connection global variable
 *
 * @uses db_start()
 * @uses db_show_error()
 *
 * @global $db_connection PgSql\Connection instance
 *
 * @param  string $sql        SQL statement.
 * @param  bool   $show_error Show error and die. Optional, defaults to true.
 *
 * @return resource PostgreSQL result resource.
 */
function db_query( $sql, $show_error = true )
{
	global $db_connection;

	if ( ! isset( $db_connection ) )
	{
		$db_connection = db_start();
	}

	$result = pg_exec( $db_connection, $sql );

	if ( $result === false
		&& $show_error )
	{
		// TRANSLATION: do NOT translate these since error messages need to stay in English for technical support.
		db_show_error( $sql, 'DB Execute Failed.', pg_last_error( $db_connection ) );
	}

	return $result;
}

/**
 * SQL query filter
 * Replace empty strings ('') with NULL values:
 * - Check for ( or , character before empty string ''.
 * - Check for <> or = character before empty string ''.
 *
 * @since 5.2
 *
 * @param  string $sql SQL queries.
 * @return string      Filtered SQL queries.
 */
function db_sql_filter( $sql )
{
	// Replace empty strings ('') with NULL values.

	if ( stripos( $sql, 'INSERT INTO ' ) !== false )
	{
		// Check for ( or , character before empty string ''.
		$sql = preg_replace( "/([,\(])[\r\n\t ]*''(?!')/", '\\1NULL', $sql );
	}

	// Check for <> or = character before empty string ''.
	$sql = preg_replace( "/(<>|=)[\r\n\t ]*''(?!')/", '\\1NULL', $sql );

	/**
	 * IS NOT NULL cases
	 *
	 * Replace <>NULL & !=NULL with IS NOT NULL
	 *
	 * @link http://www.postgresql.org/docs/current/static/functions-comparison.html
	 */
	$sql = str_replace(
		[ '<>NULL', '!=NULL' ],
		[ ' IS NOT NULL', ' IS NOT NULL' ],
		$sql
	);

	return $sql;
}

/**
 * This function connects, and does the passed query, then returns a result resource
 * Not receiving the return == unusable search.
 *
 * @example $processable_results = DBQuery( "SELECT * FROM students" );
 *
 * @uses db_sql_filter()
 * @uses db_query()
 * @see DBGet()
 *
 * @since 3.7 INSERT INTO case to Replace empty strings ('') with NULL values.
 * @since 4.3 Do DBQuery after action hook.
 *
 * @param  string   $sql       SQL statement.
 * @return resource PostgreSQL result resource
 */
function DBQuery( $sql )
{
	$sql = db_sql_filter( $sql );

	$result = db_query( $sql );

	// Do DBQuery after action hook.
	do_action( 'database.inc.php|dbquery_after', [ $sql, $result ] );

	return $result;
}

/**
 * Return next row
 *
 * @param  resource PostgreSQL result resource $result Result.
 * @return array    Next row in result set.
 */
function db_fetch_row( $result )
{
	$return = @pg_fetch_array( $result, null, PGSQL_ASSOC );

	return is_array( $return ) ? @array_change_key_case( $return, CASE_UPPER ) : $return;
}

/**
 * Returns code to go into SQL statement for accessing the next value of a sequence
 *
 * @deprecated since 9.2.1 Use DBLastInsertID() instead
 *
 * @param  string $seqname PostgreSQL sequence name.
 * @return sting  nextval code
 */
function db_seq_nextval( $seqname )
{
	return "nextval('" . DBEscapeString( $seqname ) . "')";
}


/**
 * DB Sequence Next ID
 *
 * @deprecated since 9.2.1 Use DBLastInsertID() instead
 *
 * @example $id = DBSeqNextID( 'people_person_id_seq' );
 *
 * @param string $seqname Sequence name.
 *
 * @return int Next ID.
 */
function DBSeqNextID( $seqname )
{
	$QI = DBQuery( "SELECT " . db_seq_nextval( $seqname ) . ' AS ID' );

	$seq_next_RET = db_fetch_row( $QI );

	return $seq_next_RET['ID'];
}

/**
 * DB Last Inserted ID
 *
 * @since 9.2.1
 *
 * @link https://stackoverflow.com/questions/2944297/postgresql-function-for-last-inserted-id
 *
 * @return int Last ID.
 */
function DBLastInsertID()
{
	return DBGetOne( "SELECT LASTVAL();" );
}

/**
 * Start transaction
 *
 * @return void
 */
function db_trans_start()
{
	db_query( 'BEGIN;' );
}

/**
 * Run query on transaction -- if failure, runs rollback
 *
 * @since 5.2 $connection param removed.
 *
 * @param  string     $sql       SQL statement.
 * @return PostgreSQL result resource
 */
function db_trans_query( $sql, $show_error = true )
{
	$sql = db_sql_filter( $sql );

	// Use @ error control operator to silence PHP Warning in case of failure.
	$result = @db_query( $sql, $show_error );

	if ( $result === false )
	{
		// Rollback commands.
		db_trans_rollback();
	}

	return $result;
}

/**
 * Commit changes
 *
 * @deprecated $connection param since 5.2
 *
 * @param  PostgreSQL connection resource $connection Connection. DEPRECATED.
 * @return void
 */
function db_trans_commit( $connection = false )
{
	db_query( 'COMMIT;' );
}

/**
 * Rollback changes
 *
 * @since 5.2
 *
 * @return void
 */
function db_trans_rollback()
{
	db_query( 'ROLLBACK;' );
}

/**
 * Dry run query on transaction -- rollback anyway
 * Useful to check first if foreign key constraints are preventing DELETE.
 *
 * @since 5.2
 *
 * @example $can_delete = DBTransDryRun( UserDeleteSQL( UserStaffID() ) );
 *
 * @param  string     $sql       SQL statement.
 * @return PostgreSQL result resource
 */
function DBTransDryRun( $sql )
{
	db_trans_start();

	$result = db_trans_query( $sql, false );

	if ( $result !== false )
	{
		// Rollback transaction anyway.
		db_trans_rollback();
	}

	return $result;
}

/**
 * Generate CASE-WHEN condition
 *
 * @example db_case( array( 'FAILED_LOGIN', "''", '1', 'FAILED_LOGIN+1' ) )
 * will return ' CASE WHEN FAILED_LOGIN  IS NULL THEN 1 ELSE FAILED_LOGIN+1 END '
 *
 * @param  array  $array    array( Column, IS, THEN, ELSE ).
 * @return string CASE-WHEN condition
 */
function db_case( $array )
{
	$counter = 0;

	$array_count = count( $array );

	$string = ' CASE WHEN ' . $array[0] . ' =';

	$counter++;

	$arr_count = count( $array );

	for ( $i = 1; $i < $arr_count; $i++ )
	{
		$value = $array[$i];

		if ( $value == "''"
			&& mb_substr( $string, -1 ) == '=' )
		{
			$value = ' IS NULL';

			$string = mb_substr( $string, 0, -1 );
		}

		$string .= $value;

		if ( $counter == ( $array_count - 2 )
			&& $array_count % 2 == 0 )
		{
			$string .= ' ELSE ';
		}
		elseif ( $counter == ( $array_count - 1 ) )
		{
			$string .= ' END ';
		}
		elseif ( $counter % 2 == 0 )
		{
			$string .= ' WHEN ' . $array[0] . '=';
		}
		elseif ( $counter % 2 == 1 )
		{
			$string .= ' THEN ';
		}

		$counter++;
	}

	return $string;
}


/**
 * Returns an array with the field names for the specified table as key with subkeys
 * of SIZE, TYPE, SCALE and NULL.  TYPE: varchar, numeric, etc.
 *
 * @param  string $table DB Table.
 * @return array  Table properties
 */
function db_properties( $table )
{
	$sql = "SELECT a.attnum,a.attname AS field,t.typname AS type,
			a.attlen AS length,a.atttypmod AS lengthvar,
			a.attnotnull AS notnull
		FROM pg_class c, pg_attribute a, pg_type t
		WHERE c.relname = '" . mb_strtolower( DBEscapeString( $table ) ) . "'
			and a.attnum > 0 and a.attrelid = c.oid
			and a.atttypid = t.oid ORDER BY a.attnum";

	$result = DBQuery( $sql );

	while ( $row = db_fetch_row( $result ) )
	{
		$field = mb_strtoupper( $row['FIELD'] );

		$properties[$field]['TYPE'] = mb_strtoupper( $row['TYPE'] );

		if ( mb_strtoupper( $row['TYPE'] ) == 'NUMERIC' )
		{
			$properties[$field]['SIZE'] = ( $row['LENGTHVAR'] >> 16 ) & 0xffff;
			$properties[$field]['SCALE'] = ( $row['LENGTHVAR'] - 4 ) & 0xffff;
		}
		else
		{
			if ( $row['LENGTH'] > 0 )
			{
				$properties[$field]['SIZE'] = $row['LENGTH'];
			}
			elseif ( $row['LENGTHVAR'] > 0 )
			{
				$properties[$field]['SIZE'] = $row['LENGTHVAR'] - 4;
			}
		}

		if ( $row['NOTNULL'] === 't' )
		{
			$properties[$field]['NULL'] = 'N';
		}
		else
		{
			$properties[$field]['NULL'] = 'Y';
		}
	}

	return $properties;
}

/**
 * Show SQL error message
 * Send notification email if `$RosarioNotifyAddress` or `$RosarioErrorsAddress` set
 *
 * @global string $RosarioNotifyAddress or $RosarioErrorsAddress email set in config.inc.php file
 * @since 4.0 Uses ErrorSendEmail()
 * @since 4.6 Show SQL query.
 *
 * @param string $sql        SQL statement.
 * @param string $failnote   Failure Notice.
 * @param string $additional Additional Information.
 */
function db_show_error( $sql, $failnote, $additional = '' )
{
	global $RosarioNotifyAddress,
		$RosarioErrorsAddress;

	// TRANSLATION: do NOT translate these since error messages need to stay in English for technical support.
	?>
	<br />
	<table class="postbox cellspacing-0">
		<thead><tr><th class="center">
			<h3><?php echo function_exists( '_' ) ?
	_( 'We have a problem, please contact technical support ...' ) :
	// PHP gettext extension not loaded, and polyfill either (PHPCompatibility functions not loaded yet).
	'We have a problem, please contact technical support ...'; ?></h3>
		</th></tr></thead>
	<tbody><tr><td class="popTable">
		<table>
			<tr>
				<td><?php echo date( 'm/d/Y H:i:s' ); ?><br />
					<span class="legend-gray">Date</span></td>
			</tr>
			<tr>
				<td><?php echo $failnote; ?> <?php echo $additional; ?><br />
					<span class="legend-gray">Failure Notice</span></td>
			</tr>
			<tr>
				<td><pre class="size-1" style="max-width: 65vw; overflow: auto;"><?php echo str_replace( "\t\t", '', $sql ); ?></pre>
					<span class="legend-gray">SQL query</span></td>
			</tr>
		</table>
	</td></tr></tbody></table>
	<?php

	// Send notification email if $RosarioNotifyAddress set & functions loaded.
	$db_error_email = ! empty( $RosarioErrorsAddress ) ? $RosarioErrorsAddress : $RosarioNotifyAddress;

	if ( function_exists( 'ErrorSendEmail' ) )
	{
		$db_error = [
			'Failure Notice: ' . $failnote,
			'Additional Info: ' . $additional,
			$sql,
		];

		ErrorSendEmail( $db_error, 'Database Error' );
	}

	die();
}

/**
 * Escapes single quotes by using two for every one.
 *
 * @example $safe_string = DBEscapeString( $string );
 * @since 9.0 Fix PHP8.1 deprecated use PostgreSQL $db_connection global variable
 *
 * @global $db_connection PgSql\Connection instance
 *
 * @param  string $input  Input string.
 * @return string escaped string
 */
function DBEscapeString( $input )
{
	global $db_connection;

	// return str_replace("'","''",$input);
	return pg_escape_string( $db_connection, (string) $input );
}

/**
 * Escapes identifiers (table, column) using double quotes.
 * Security function for
 * when you HAVE to use a variable as an identifier.
 *
 * @example $safe_sql = "SELECT COLUMN FROM " . DBEscapeIdentifier( $table ) . " WHERE " . DBEscapeIdentifier( $column ) . "='Y'";
 * @uses pg_escape_identifier(), requires PHP 5.4.4+
 * @since 3.0
 * @since 9.0 Fix PHP8.1 deprecated use PostgreSQL $db_connection global variable
 *
 * @global $db_connection PgSql\Connection instance
 *
 * @param  string $identifier SQL identifier (table, column).
 * @return string Escaped identifier.
 */
function DBEscapeIdentifier( $identifier )
{
	global $db_connection;

	$identifier = mb_strtolower( $identifier );

	return pg_escape_identifier( $db_connection, $identifier );
}

/**
 * Remove delimiter declarations inside SQL file (MySQL)
 * Delimiter are used for functions or procedures
 * when importing an SQL file from the command line.
 * They generate errors when the SQL is sent from PHP
 *
 * Used in InstallDatabase.php, Modules.inc.php & Plugins.inc.php
 *
 * @since 10.0
 *
 * @param  string $sql SQL from an .sql file.
 * @return string      SQL without delimiter declarations
 */
function MySQLRemoveDelimiter( $sql )
{
	// https://stackoverflow.com/questions/1462720/iterate-over-each-line-in-a-string-in-php
	$separator = "\r\n";

	$line = strtok( $sql, $separator );

	$sql_without_delimiter = '';

	$delimiter = ';';

	while ( $line !== false )
	{
		if ( stripos( $line, 'DELIMITER' ) !== false )
		{
			$delimiter = ';';

			if ( $line !== 'DELIMITER ;'
				&& $line !== 'delimiter ;' )
			{
				// Declaring custom delimiter, get it.
				$line = trim( $line );

				$line_exploded = explode( ' ', $line );

				$delimiter = trim( $line_exploded[1] );
			}

			$line = strtok( $separator );

			// DELIMITER declaration, skip.
			continue;
		}

		$line_without_delimiter = $line;

		if ( $delimiter !== ';' )
		{
			// Replace custom DELIMITER with ;
			$line_without_delimiter = str_replace( $delimiter, ';', $line );
		}

		$sql_without_delimiter .= $line_without_delimiter . $separator;

		$line = strtok( $separator );
	}

	return $sql_without_delimiter;
}
