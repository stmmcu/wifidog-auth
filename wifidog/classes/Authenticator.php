<?php


/********************************************************************\
 * This program is free software; you can redistribute it and/or    *
 * modify it under the terms of the GNU General Public License as   *
 * published by the Free Software Foundation; either version 2 of   *
 * the License, or (at your option) any later version.              *
 *                                                                  *
 * This program is distributed in the hope that it will be useful,  *
 * but WITHOUT ANY WARRANTY; without even the implied warranty of   *
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the    *
 * GNU General Public License for more details.                     *
 *                                                                  *
 * You should have received a copy of the GNU General Public License*
 * along with this program; if not, contact:                        *
 *                                                                  *
 * Free Software Foundation           Voice:  +1-617-542-5942       *
 * 59 Temple Place - Suite 330        Fax:    +1-617-542-2652       *
 * Boston, MA  02111-1307,  USA       gnu@gnu.org                   *
 *                                                                  *
 \********************************************************************/
/**@file Authenticator.php
 * @author Copyright (C) 2005 Benoit Gr�goire <bock@step.polymtl.ca>,
 * Technologies Coeus inc.
 */

/** Abstract class to represent an authentication source */
abstract class Authenticator
{
	protected $mAccountOrigin;

	function __construct($account_orgin)
	{
		$this->mAccountOrigin = $account_orgin;
	}

	/** Attempts to login a user against the authentication source.  If successfull, returns a User object */
	function login()
	{
	}

	/** Logs out the user */
	function logout()
	{
	}

	/** Start accounting traffic for the user */
	function acctStart($info)
	{
		global $db;
		$auth_response = $info['account_status'];
		/* Login the user */
		$mac = $db->EscapeString($_REQUEST['mac']);
		$ip = $db->EscapeString($_REQUEST['ip']);
		$sql = "UPDATE connections SET "."token_status='".TOKEN_INUSE."',"."user_mac='$mac',"."user_ip='$ip',"."last_updated=NOW()"."WHERE conn_id='{$info['conn_id']}';\n";
		$db->ExecSqlUpdate($sql, false);

		/* Logging in with a new token implies that all other active tokens should expire */
		$token = $db->EscapeString($_REQUEST['token']);
		$sql = "UPDATE connections SET "."timestamp_out=NOW(), token_status='".TOKEN_USED."' "."WHERE user_id = '{$info['user_id']}' AND token_status='".TOKEN_INUSE."' AND token!='$token';\n";
		$db->ExecSqlUpdate($sql, false);
		/* Delete all unused tokens for this user, so we don't fill the database with them */
		$sql = "DELETE FROM connections "."WHERE token_status='".TOKEN_UNUSED."' AND user_id = '{$info['user_id']}';\n";
		$db->ExecSqlUpdate($sql, false);
	}

	/** Update traffic counters */
	function acctUpdate($info, $incoming, $outgoing)
	{
		// Write traffic counters to database
		global $db;
		$db->ExecSqlUpdate("UPDATE connections SET "."incoming='$incoming',"."outgoing='$outgoing',"."last_updated=NOW() "."WHERE conn_id='{$info['conn_id']}'");
	}

	/** Final update and stop accounting */
	function acctStop($info)
	{
		// Stop traffic counters update
		global $db;
		$db->ExecSqlUpdate("UPDATE connections SET "."timestamp_out=NOW(),"."token_status='".TOKEN_USED."' "."WHERE conn_id='{$info['conn_id']}';\n");
	}

} // End class
?>


