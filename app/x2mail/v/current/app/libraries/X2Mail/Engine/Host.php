<?php

namespace X2Mail\Engine;

/**
 * Holds the HostBridge of the current request. The hosting application sets it
 * during bootstrap; the engine core only ever reads it through get().
 */
final class Host
{
	private static ?HostBridge $oBridge = null;

	public static function set(HostBridge $oBridge) : void
	{
		self::$oBridge = $oBridge;
	}

	/**
	 * @throws \LogicException when the hosting application registered no bridge
	 */
	public static function get() : HostBridge
	{
		return self::$oBridge ?? throw new \LogicException('X2Mail engine: no HostBridge registered');
	}

	/** Test isolation only — production never unregisters. */
	public static function reset() : void
	{
		self::$oBridge = null;
	}
}
