<?php

class TkWordPressMedicConnectionFactory
{
	protected static $connection;
	private static $dbname;

	public function getConnection()
	{
		try
		{
   		$dbserver = get_option('tk_wpm_db_server');
			$dbname = get_option('tk_wpm_db_name');
			$dbusername = get_option('tk_wpm_db_username');
			$dbpassword = get_option('tk_wpm_db_password');

			if (strpos($dbserver, ":") !== false)
			{
				$server_port = explode(':', $dbserver);
				$dbserver = $server_port[0];
				$dbport = $server_port[1];
			}

			self::$dbname = $dbname;
			if (!self::$connection)
			{
				self::$connection = new PDO("mysql:host={$dbserver};dbname={$dbname};charset=utf8".(isset($dbport) ? ";port={$dbport}" : ''), $dbusername, $dbpassword);
			}
			return self::$connection;
		}
		catch (Exception $e)
		{
			return null;
		}
   }

	function databaseExists($dbname, $pdo)
	{
		$quark = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = {$dbname}");
		if ($quark.count > 0) return true;
		else return false;
	}
}

?>
