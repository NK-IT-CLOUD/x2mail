<?php

namespace X2Mail\Engine;

abstract class Shutdown
{
	private static
		$actions = [],
		$running = false;

	final public static function run() : void
	{
		if (!self::$running && \count(self::$actions)) {
			self::$running = true;
			\ini_set('display_errors', 0);
			\ignore_user_abort(true);

			# Flush all output buffers
			if ($i = \ob_get_level()) {
				while ($i-- && \ob_end_flush());
			}
			\flush();

			if (\is_callable('fastcgi_finish_request')) {
				// Special FPM/FastCGI (fpm-fcgi) function to finish request and
				// flush all data while continuing to do something time-consuming.
				\fastcgi_finish_request();
			}

			foreach (self::$actions as $action) {
				try {
					\call_user_func_array($action[0], $action[1]);
				} catch (\Throwable $e) { } # skip
			}
		}
	}

	final public static function add(callable $function, array $args = []) : void
	{
		if (!\count(self::$actions)) {
			\register_shutdown_function('\\X2Mail\Engine\\Shutdown::run');
		}
		self::$actions[] = [$function, $args];
	}

	final public static function count() : int
	{
		return \count(self::$actions);
	}
}
