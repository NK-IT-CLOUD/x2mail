<?php

namespace X2Mail\Engine;

/**
 * What the engine core needs from the application that hosts it — the
 * Nextcloud app or the standalone webmail. Registered once per request via
 * Host::set() before the engine handles anything.
 */
interface HostBridge
{
	/** True when the request belongs to an SSO session the engine may log in from. */
	public function isSsoLogin() : bool;

	/** Mail address of the SSO user, null when unknown. */
	public function ssoEmail() : ?string;

	/** Stable user id of the SSO user, null when unknown. */
	public function ssoUid() : ?string;

	/** Per-session secret for the connection/CSRF token, null when no session exists. */
	public function sessionSeed() : ?string;
}
