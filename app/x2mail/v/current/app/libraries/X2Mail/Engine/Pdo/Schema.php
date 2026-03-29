<?php

namespace X2Mail\Engine\Pdo;

abstract class Schema
{
	public static function mysql() : array
	{
		return [
			'CREATE TABLE IF NOT EXISTS x2mail_system (
sys_name varchar(64) NOT NULL,
value_int int UNSIGNED NOT NULL DEFAULT 0,
PRIMARY KEY (sys_name)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;',
			'CREATE TABLE IF NOT EXISTS x2mail_users (
id_user int UNSIGNED NOT NULL AUTO_INCREMENT,
rl_email varchar(254) NOT NULL,
PRIMARY KEY (id_user),
UNIQUE KEY ui_x2mail_users_email (rl_email)
);'
		];
	}

	public static function pgsql() : array
	{
		return [
			'CREATE TABLE x2mail_system (
sys_name varchar(50) NOT NULL,
value_int integer NOT NULL DEFAULT 0
);',
			'CREATE INDEX sys_name_x2mail_system_index ON x2mail_system (sys_name);',
			'CREATE SEQUENCE id_user START WITH 1 INCREMENT BY 1 NO MAXVALUE NO MINVALUE CACHE 1;',
			'CREATE TABLE x2mail_users (
id_user integer DEFAULT nextval(\'id_user\'::text) PRIMARY KEY,
rl_email varchar(254) NOT NULL DEFAULT \'\'
);',
			'CREATE INDEX rl_email_x2mail_users_index ON x2mail_users (rl_email);'
		];
	}

	public static function sqlite() : array
	{
		return [
			'CREATE TABLE x2mail_system (
sys_name text NOT NULL,
value_int integer NOT NULL DEFAULT 0
);',
			'CREATE UNIQUE INDEX ui_x2mail_system_sys_name ON x2mail_system (sys_name);',
			'CREATE TABLE x2mail_users (
id_user integer NOT NULL PRIMARY KEY,
rl_email text NOT NULL DEFAULT \'\'
);',
			'CREATE INDEX rl_email_x2mail_users_index ON x2mail_users (rl_email);'
		];
	}

	public static function getForDbType(string $sDbType) : array
	{
		switch ($sDbType)
		{
			case 'mysql':
			case 'pgsql':
			case 'sqlite':
				return static::{$sDbType}();
		}
		return [];
	}
}
