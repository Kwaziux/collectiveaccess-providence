<?php
/** ---------------------------------------------------------------------
 * app/lib/Db/mysql.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2006-2017 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package    CollectiveAccess
 * @subpackage Core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

/**
 *
 */

require_once( __CA_LIB_DIR__ . "/Db/DbDriverBase.php" );
require_once( __CA_LIB_DIR__ . "/Db/DbResult.php" );
require_once( __CA_LIB_DIR__ . "/Db/DbStatement.php" );

/**
 * Cache for prepared statements
 */
$g_mysql_statement_cache = array();

/**
 * Flag indicating if db user has FILE priv; null=undetermined
 */
$g_mysql_has_file_priv = null;

/**
 * MySQL driver for Db abstraction class
 *
 * You should always use the Db class as interface to the database.
 */
class Db_mysql extends DbDriverBase {
	/**
	 * MySQL database resource
	 *
	 * @access private
	 */
	var $opr_db;

	/**
	 * SQL statement
	 *
	 * @access private
	 */
	var $ops_sql;

	/**
	 * List of features supported by this driver
	 *
	 * @access private
	 */
	var $opa_features
		= array(
			'limit'                   => true,
			'numrows'                 => true,
			'pconnect'                => true,
			'prepare'                 => false,
			'ssl'                     => false,
			'transactions'            => true,
			'max_nested_transactions' => 1
		);

	/**
	 * Constructor
	 *
	 * @see DbDriverBase::DbDriverBase()
	 */
	public function __construct() {
		//print "Construct db driver\n";
	}

	/**
	 * Establishes a connection to the database
	 *
	 * @param mixed $po_caller  representation of the caller, usually a Db() object
	 * @param array $pa_options array containing options like host, username, password
	 *
	 * @return bool success state
	 */
	public function connect( $po_caller, $pa_options ) {
		global $g_connect;
		if ( ! is_array( $g_connect ) ) {
			$g_connect = array();
		}
		$vs_db_connection_key = $pa_options["host"] . '/' . $pa_options["database"];

		if (
			! ( $vb_unique_connection = caGetOption( 'uniqueConnection', $pa_options, false ) )
			&& isset( $g_connect[ $vs_db_connection_key ] )
			&& is_resource( $g_connect[ $vs_db_connection_key ] )
		) {
			$this->opr_db = $g_connect[ $vs_db_connection_key ];

			return true;
		}

		if ( ! function_exists( "mysql_connect" ) ) {
			throw new DatabaseException( _t( "Your PHP installation lacks MySQL support. Please add it and retry..." ),
				200, "Db->mysql->connect()" );
		}

		if ( ! $vb_unique_connection
		     && ( $vb_persistent_connections = caGetOption( 'persistentConnections', $pa_options, false ) )
		) {
			$this->opr_db = @mysql_pconnect( $pa_options["host"], $pa_options["username"], $pa_options["password"] );
		} else {
			$this->opr_db = @mysql_connect( $pa_options["host"], $pa_options["username"], $pa_options["password"],
				true );
		}
		if ( ! $this->opr_db ) {
			$po_caller->postError( 200, mysql_error(), "Db->mysql->connect()" );
			throw new DatabaseException( mysql_error(), 200, "Db->mysql->connect()" );

			return false;
		}

		if ( ! mysql_select_db( $pa_options["database"], $this->opr_db ) ) {
			$po_caller->postError( 201, mysql_error( $this->opr_db ), "Db->mysql->connect()" );
			throw new DatabaseException( mysql_error(), 201, "Db->mysql->connect()" );

			return false;
		}
		mysql_query( 'SET NAMES \'utf8\'', $this->opr_db );
		mysql_query( 'SET character_set_results = NULL', $this->opr_db );

		if ( ! $vb_unique_connection ) {
			$g_connect[ $vs_db_connection_key ] = $this->opr_db;
		}

		return true;
	}

	/**
	 * Closes the connection if it exists
	 *
	 * @return bool success state
	 */
	public function disconnect() {
		//if (!is_resource($this->opr_db)) { return true; }
		//if (!@mysql_close($this->opr_db)) {
		//	return false;
		//}
		return true;
	}

	/**
	 * Prepares a SQL statement
	 * You may use placeholders (?) in your statement and attach the values
	 * as additional parameters to avoid SQL injection vulnerabilities
	 *
	 * @param mixed  $po_caller object representation of calling class, usually Db
	 * @param string $ps_sql    query string
	 *
	 * @return DbStatement
	 * @see Db::prepare()
	 */
	public function prepare( $po_caller, $ps_sql ) {
		$this->ops_sql = $ps_sql;

		// are there any placeholders at all?
		if ( strpos( $ps_sql, '?' ) === false ) {
			return new DbStatement( $this, $this->ops_sql, array( 'placeholder_map' => array() ) );
		}

		global $g_mysql_statement_cache;

		$vs_md5 = md5( $ps_sql );

		// is prepared statement cached?
		if ( isset( $g_mysql_statement_cache[ $vs_md5 ] ) ) {
			return new DbStatement( $this, $ps_sql, array( 'placeholder_map' => $g_mysql_statement_cache[ $vs_md5 ] ) );
		}


		// find placeholders
		$vn_i = 0;
		$vn_l = strlen( $ps_sql );

		$va_placeholder_map = array();
		$vb_in_quote        = '';
		$vb_is_escaped      = false;

		while ( $vn_i < $vn_l ) {
			$vs_c = $ps_sql{$vn_i};

			switch ( $vs_c ) {
				case '"':
					if ( ! $vb_is_escaped ) {
						if ( $vb_in_quote == '"' ) {
							$vb_in_quote = '';
						} else {
							if ( ! $vb_in_quote ) {
								$vb_in_quote = '"';
							}
						}
					}
					$vb_is_escaped = false;
					break;
				case "'":
					if ( ! $vb_is_escaped ) {
						if ( $vb_in_quote == "'" ) {
							$vb_in_quote = '';
						} else {
							if ( ! $vb_in_quote ) {
								// handle escaped quote in '' format
								if ( $ps_sql{$vn_i + 1} == "'" ) {
									$vn_i += 2;
									continue;
								} else {
									$vb_in_quote = "'";
								}
							}
						}
					}
					$vb_is_escaped = false;
					break;
				case '?':
					if ( ( ! $vb_is_escaped ) && ( ! $vb_in_quote ) ) {
						$va_placeholder_map[] = $vn_i;
					}
					$vb_is_escaped = false;
					break;
				case "\\":
					$vb_is_escaped = ! $vb_is_escaped;
					break;
				default:
					$vb_is_escaped = false;
					break;
			}
			$vn_i ++;
		}

		while ( is_array( $g_mysql_statement_cache ) && ( sizeof( $g_mysql_statement_cache ) >= 2048 ) ) {
			array_shift( $g_mysql_statement_cache );
		}    // limit statement cache to 2048 entries, otherwise we'll eat up memory in long running processes


		$g_mysql_statement_cache[ $vs_md5 ] = $va_placeholder_map;

		return new DbStatement( $this, $this->ops_sql, array( 'placeholder_map' => $va_placeholder_map ) );
	}

	/**
	 * Executes a SQL statement
	 *
	 * @param mixed       $po_caller object representation of the calling class, usually Db()
	 * @param DbStatement $opo_statement
	 * @param string      $ps_sql    SQL statement
	 * @param array       $pa_options
	 * @param array       $pa_values array of placeholder replacements
	 */
	public function execute( $po_caller, $opo_statement, $ps_sql, $pa_values, $pa_options = null ) {
		if ( ! $ps_sql ) {
			$opo_statement->postError( 240, _t( "Query is empty" ), "Db->mysql->execute()" );
			throw new DatabaseException( _t( "Query is empty" ), 240, "Db->mysql->execute()" );

			return false;
		}

		$vs_sql = $ps_sql;

		$va_placeholder_map = $opo_statement->getOption( 'placeholder_map' );
		$vn_needed_values   = sizeof( $va_placeholder_map );
		if ( $vn_needed_values != sizeof( $pa_values ) ) {
			$opo_statement->postError( 285,
				_t( "Number of values passed (%1) does not equal number of values required (%2)", sizeof( $pa_values ),
					$vn_needed_values ), "Db->mysql->execute()" );
			throw new DatabaseException( _t( "Number of values passed (%1) does not equal number of values required (%2)",
				sizeof( $pa_values ), $vn_needed_values ), 285, "Db->mysql->execute()" );

			return false;
		}

		for ( $vn_i = ( sizeof( $pa_values ) - 1 ); $vn_i >= 0; $vn_i -- ) {
			if ( is_array( $pa_values[ $vn_i ] ) ) {
				foreach ( $pa_values[ $vn_i ] as $vn_x => $vs_vx ) {
					$pa_values[ $vn_i ][ $vn_x ] = $this->autoQuote( $vs_vx );
				}
				$vs_sql = substr_replace( $vs_sql, join( ',', $pa_values[ $vn_i ] ), $va_placeholder_map[ $vn_i ], 1 );
			} else {
				$vs_sql = substr_replace( $vs_sql, $this->autoQuote( $pa_values[ $vn_i ] ),
					$va_placeholder_map[ $vn_i ], 1 );
			}
		}

		$va_limit_info = $opo_statement->getLimit();
		if ( ( $va_limit_info["limit"] > 0 ) || ( $va_limit_info["offset"] > 0 ) ) {
			if ( ! preg_match( "/LIMIT[ ]+[\d]+[,]{0,1}[\d]*$/i", $vs_sql ) ) {    // check for LIMIT clause is raw SQL
				$vn_limit = $va_limit_info["limit"];
				if ( $vn_limit == 0 ) {
					$vn_limit = 4000000000;
				}
				$vs_sql .= " LIMIT " . intval( $va_limit_info["offset"] ) . "," . intval( $vn_limit );
			}
		}

		if ( Db::$monitor ) {
			$t = new Timer();
		}
		if ( ! ( $r_res = mysql_query( $vs_sql, $this->opr_db ) ) ) {
			$vn_mysql_err = (int) mysql_errno( $this->opr_db );

			switch ( $vn_mysql_err ) {
				case 1205:        // deadlock
				case 1216:        // deadlock
					$vn_tries = 0;
					// wait a bit and try the query again (up to 10 times)
					while ( $vn_tries < 10 ) {
						usleep( 500 );
						if ( $r_res = mysql_query( $vs_sql, $this->opr_db ) ) {
							break;
						}
						$vn_tries ++;
					}

					if ( ! $r_res ) {
						$opo_statement->postError( $this->nativeToDbError( $vn_mysql_err ),
							mysql_error( $this->opr_db ), "Db->mysql->execute()" );
						throw new DatabaseException( mysql_error( $this->opr_db ),
							$this->nativeToDbError( $vn_mysql_err ), "Db->mysql->execute()" );

						return false;
					}

					return new DbResult( $this, $r_res );
					break;
				default:
					$opo_statement->postError( $this->nativeToDbError( $vn_mysql_err ), mysql_error( $this->opr_db ),
						"Db->mysql->execute()" );
					throw new DatabaseException( mysql_error( $this->opr_db ), $this->nativeToDbError( $vn_mysql_err ),
						"Db->mysql->execute()" );
					break;
			}

			return false;
		}
		if ( Db::$monitor ) {
			Db::$monitor->logQuery( $ps_sql, $pa_values, $t->getTime( 4 ),
				is_bool( $r_res ) ? null : mysql_num_rows( $r_res ) );
		}

		return new DbResult( $this, $r_res );
	}

	/**
	 * Fetches the ID generated by the last MySQL INSERT statement
	 *
	 * @param mixed $po_caller object representation of calling class, usually Db
	 *
	 * @return int the ID generated by the last MySQL INSERT statement
	 */
	public function getLastInsertID( $po_caller ) {
		return @mysql_insert_id( $this->opr_db );
	}

	/**
	 * How many rows have been affected by your query?
	 *
	 * @param mixed $po_caller object representation of calling class, usually Db
	 *
	 * @return int number of rows
	 */
	public function affectedRows( $po_caller ) {
		return @mysql_affected_rows( $this->opr_db );
	}

	/**
	 * @param string
	 *
	 * @return string
	 * @see Db::escape()
	 */
	public function escape( $ps_text ) {
		if ( $this->opr_db ) {
			return mysql_real_escape_string( $ps_text, $this->opr_db );
		} else {
			return mysql_real_escape_string( $ps_text );
		}
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 *
	 * @return bool success state
	 * @see Db::beginTransaction()
	 */
	public function beginTransaction( $po_caller ) {
		if ( ! @mysql_query( 'set autocommit=0', $this->opr_db ) ) {
			$po_caller->postError( 250, mysql_error( $this->opr_db ), "Db->mysql->beginTransaction()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 250, "Db->mysql->beginTransaction()" );

			return false;
		}
		if ( ! @mysql_query( 'start transaction', $this->opr_db ) ) {
			$po_caller->postError( 250, mysql_error( $this->opr_db ), "Db->mysql->beginTransaction()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 250, "Db->mysql->beginTransaction()" );

			return false;
		}

		return true;
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 *
	 * @return bool success state
	 * @see Db::commitTransaction()
	 */
	public function commitTransaction( $po_caller ) {
		if ( ! @mysql_query( 'commit', $this->opr_db ) ) {
			$po_caller->postError( 250, mysql_error( $this->opr_db ), "Db->mysql->commitTransaction()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 250, "Db->mysql->commitTransaction()" );

			return false;
		}
		if ( ! @mysql_query( 'set autocommit=1', $this->opr_db ) ) {
			$po_caller->postError( 250, mysql_error( $this->opr_db ), "Db->mysql->commitTransaction()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 250, "Db->mysql->commitTransaction()" );

			return false;
		}

		return true;
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 *
	 * @return bool success state
	 * @see Db::rollbackTransaction()
	 */
	public function rollbackTransaction( $po_caller ) {
		if ( ! @mysql_query( 'rollback', $this->opr_db ) ) {
			$po_caller->postError( 250, mysql_error( $this->opr_db ), "Db->mysql->rollbackTransaction()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 250, "Db->mysql->rollbackTransaction()" );

			return false;
		}
		if ( ! @mysql_query( 'set autocommit=1', $this->opr_db ) ) {
			$po_caller->postError( 250, mysql_error( $this->opr_db ), "Db->mysql->rollbackTransaction()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 250, "Db->mysql->rollbackTransaction()" );

			return false;
		}

		return true;
	}

	/**
	 * @param mixed $po_caller  object representation of the calling class, usually Db
	 * @param mixed $pr_res     mysql resource
	 * @param mixed $pm_field   the field or an array of fields
	 * @param array $pa_options Options include:
	 *                          limit = cap number of field values returned. [Default is null]
	 *
	 * @return array an array of field values (if $pm_field is a single field name) or an array if field names each of
	 *               which is an array of values (if $pm_field is an array of field names)
	 * @see DbResult::getAllFieldValues()
	 */
	function getAllFieldValues( $po_caller, $pr_res, $pa_fields, $pa_options = null ) {
		$va_vals = array();

		$pn_limit = isset( $pa_options['limit'] ) ? (int) $pa_options['limit'] : null;
		$c        = 0;
		if ( is_array( $pa_fields ) ) {
			$va_row = @mysql_fetch_assoc( $pr_res );
			foreach ( $pa_fields as $vs_field ) {
				if ( ! is_array( $va_row ) || ! array_key_exists( $vs_field, $va_row ) ) {
					return array();
				}
			}
			$this->seek( $po_caller, $pr_res, 0 );
			while ( is_array( $va_row = @mysql_fetch_assoc( $pr_res ) ) ) {
				foreach ( $pa_fields as $vs_field ) {
					$va_vals[ $vs_field ][] = $va_row[ $vs_field ];
				}

				$c ++;
				if ( $pn_limit && ( $c > $pn_limit ) ) {
					break;
				}
			}
		} else {
			$va_row = @mysql_fetch_assoc( $pr_res );
			if ( ! is_array( $va_row ) || ! array_key_exists( $pa_fields, $va_row ) ) {
				return array();
			}
			$this->seek( $po_caller, $pr_res, 0 );
			while ( is_array( $va_row = @mysql_fetch_assoc( $pr_res ) ) ) {
				$va_vals[] = $va_row[ $pa_fields ];

				$c ++;
				if ( $pn_limit && ( $c > $pn_limit ) ) {
					break;
				}
			}
		}

		return $va_vals;
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 * @param mixed $pr_res    mysql resource
	 *
	 * @return array array representation of the next row
	 * @see DbResult::nextRow()
	 */
	public function nextRow( $po_caller, $pr_res ) {
		//$va_row = @mysql_fetch_row($pr_res);
		$va_row = @mysql_fetch_assoc( $pr_res );
		if ( ! is_array( $va_row ) ) {
			return null;
		}

		return $va_row;
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 * @param mixed $pr_res    mysql resource
	 * @param int   $pn_offset line number to seek
	 *
	 * @return array array representation of the next row
	 * @see DbResult::seek()
	 */
	public function seek( $po_caller, $pr_res, $pn_offset ) {
		if ( $pn_offset < 0 ) {
			return false;
		}
		if ( $pn_offset > ( mysql_num_rows( $pr_res ) - 1 ) ) {
			return false;
		}
		if ( ! @mysql_data_seek( $pr_res, $pn_offset ) ) {
			$po_caller->postError( 260,
				_t( "seek(%1) failed: result has %2 rows", $pn_offset, $this->numRows( $pr_res ) ),
				"Db->mysql->seek()" );
			throw new DatabaseException( _t( "seek(%1) failed: result has %2 rows", $pn_offset,
				$this->numRows( $pr_res ) ), 260, "Db->mysql->seek()" );

			return false;
		};

		return true;
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 * @param mixed $pr_res    mysql resource
	 *
	 * @return int number of rows
	 * @see DbResult::numRows()
	 */
	public function numRows( $po_caller, $pr_res ) {
		return @mysql_num_rows( $pr_res );
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 * @param mixed $pr_res    mysql resource
	 *
	 * @return bool success state
	 * @see DbResult::free()
	 */
	public function free( $po_caller, $pr_res ) {
		if ( is_resource( $pr_res ) ) {
			return @mysql_free_result( $pr_res );
		}

		return false;
	}

	/**
	 * @param mixed  $po_caller object representation of the calling class, usually Db
	 * @param string $ps_key    feature to look for
	 *
	 * @return bool|int
	 * @see Db::supports()
	 */
	public function supports( $po_caller, $ps_key ) {
		return $this->opa_features[ $ps_key ];
	}

	/**
	 * @param mixed $po_caller object representation of the calling class, usually Db
	 *
	 * @return array field list, false on error
	 * @see Db::getTables()
	 */
	public function &getTables( $po_caller ) {
		if ( $r_show = mysql_query( "SHOW TABLES", $this->opr_db ) ) {
			$va_tables = array();
			while ( $va_row = mysql_fetch_row( $r_show ) ) {
				$va_tables[] = $va_row[0];
			}

			return $va_tables;
		} else {
			$po_caller->postError( 280, mysql_error( $this->opr_db ), "Db->mysql->getTables()" );
			throw new DatabaseException( mysql_error( $this->opr_db ), 280, "Db->mysql->getTables()" );

			return false;
		}
	}

	/**
	 * Get database connection handle
	 *
	 * @return resource
	 */
	public function getHandle() {
		return $this->opr_db;
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		// Disconnecting here can affect other classes that need
		// to clean up by writing to the database so we disabled 
		// disconnect-on-destruct
		//$this->disconnect();
	}
}
