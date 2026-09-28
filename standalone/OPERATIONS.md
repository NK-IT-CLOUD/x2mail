# X2Mail standalone webmail — operations guide

## 1. What runs

One image, `x2mail-webmail`, built from `standalone/container/Containerfile` (repository root as build context). It is started with different roles via the container command:

- **`php`** (default) — runs `bin/provision` (validates `webmail.toml`, writes the engine's domain and theme configuration), then `php-fpm --nodaemonize` listening on **9000**. `php-fpm` never starts if provisioning fails.
- **`web`** — nginx on **8080**, serving the front controller over FastCGI and two static, PHP-free locations (`/x2mail/v/current/static/`, `/x2mail/v/current/themes/`). It resolves the `php` role's address at request time (see Deploy) rather than at nginx startup, so it can start before `php` is ready.
- **`web-check`** — `nginx -t` against the same runtime config; used only at build time to catch a broken nginx config early.

Port 9000 is internal only — it is never exposed to clients. Port 8080 serves plain HTTP only — it does not speak TLS and cannot be forwarded to directly over HTTPS. TLS must terminate at the reverse proxy in front of it, which then reaches port 8080 over plain HTTP; the `web` role always passes `HTTPS on` to PHP regardless (the application assumes it is always reached over TLS via the proxy and never reads `X-Forwarded-Proto`), so clients must reach the site over HTTPS for cookies and redirects to behave correctly.

Both roles run as uid/gid 82 (`www-data`) on a read-only root filesystem; the only writable paths are `/tmp` (mounted as tmpfs) and, for the `php` role only, the data volume at `/var/lib/x2mail-webmail`. The image declares no `VOLUME` — the data path is an ordinary directory unless the deployment mounts a volume over it.

## 2. Deploy

`standalone/container/compose.example.yaml` is a working starting point:

```yaml
services:
  php:
    image: registry.example.com/x2mail-webmail:1.0.0
    command: ["php"]
    read_only: true
    tmpfs: ["/tmp:size=128m"]
    volumes:
      - ./config:/etc/x2mail-webmail:ro
      - data:/var/lib/x2mail-webmail
    mem_limit: 512m
    ...

  web:
    image: registry.example.com/x2mail-webmail:1.0.0
    command: ["web"]
    read_only: true
    tmpfs: ["/tmp:size=128m"]
    ports: ["8080:8080"]
    depends_on: [php]
    mem_limit: 256m
    healthcheck:
      test: ["CMD", "wget", "-qO-", "http://127.0.0.1:8080/healthz"]
      ...
```

- **Config directory** (`./config` above) is mounted read-only at `/etc/x2mail-webmail` on the `php` service only — the `web` role never reads it. It must contain `webmail.toml` (path overridable via `X2W_CONFIG`, default `/etc/x2mail-webmail/webmail.toml`) and any file referenced by `oidc.client_secret_file`. Both must be readable by uid/gid 82; the directory does not need to be writable.
- **Data volume** (`data` above) is mounted at `/var/lib/x2mail-webmail` on the `php` service only. It holds sessions, the DAV response cache, the engine's provisioned mail-domain configuration, and per-user engine data (additional identities, signatures, settings, PGP keys) — it needs to be backed up along with the config directory (see Operations, section 6); only sessions and cache are regenerable.
- **`mem_limit`** must be set explicitly on both services — the image does not size itself from the container's memory limit. The example values (512m for `php`, 256m for `web`) are starting points, not guaranteed minimums; size them to the actual `pm.max_children` and request load.
- **Read-only root** (`read_only: true` + `tmpfs: ["/tmp:size=128m"]`) is required — both roles write only to `/tmp` and, for `php`, the data volume. Nothing else on the image needs to be writable at runtime.
- **Uploads and `/tmp` sizing**: nginx buffers a request body larger than 16 KB to a file under `/tmp` (`client_body_temp_path`) before proxying it to `php`; on the `php` side, PHP's own `upload_tmp_dir` is also `/tmp`. Both `/tmp` mounts are tmpfs, so their contents count against each service's `mem_limit` — on a host without swap, a full tmpfs plus RSS hits the limit hard. The webmail allows attachments up to 50 MB, so one in-flight upload needs roughly 50 MB of `web`'s tmpfs and 50 MB of `php`'s tmpfs, on top of nginx's/PHP's own RSS. The example's `tmpfs: ["/tmp:size=128m"]` and `web mem_limit: 256m` fit one 50 MB upload with headroom; size both the tmpfs `size=` and `mem_limit` up (e.g. `size=<N × 55m>` plus RSS headroom) for the number of large uploads you expect concurrently, on both the `web` and `php` services.
- **Healthcheck**: only the `web` role exposes `/healthz` over HTTP; the compose example wires a container healthcheck against it. `/healthz` is routed through nginx to the `php` role over FastCGI, so a successful check proves both the `web` process and the `php`/`php-fpm` process are up and reachable from each other — it does not exercise the application's config, the identity provider, or the mail backend (see section 6). There is no separate HTTP endpoint on the `php` service; if it needs to be checked in isolation, monitor it by process (e.g. `php-fpm` PID).
- The `web` role's upstream for the `php` role is configurable via env var `X2W_PHP_UPSTREAM` (`host:port`, default `php:9000`), resolved at request time using the resolver from the container's `/etc/resolv.conf` — so `web` can start and pass its own config test before `php` exists on the network.

## 3. Configuration reference

Config file: TOML, path from `X2W_CONFIG` (default `/etc/x2mail-webmail/webmail.toml`). The `php` role reads and validates `webmail.toml` (plus the secret file it references) **on every request**, via `App::fromEnvironment()` (`public/index.php`) — not only once at start. **Any unknown key anywhere in the file is a config error**, and an edit to rules, `[access]`, `[dav.<name>]`, `[theme]` or `[oidc]` takes effect on the **next request**, no restart needed. A broken edit therefore breaks live traffic immediately: every request that reaches the `php` role returns HTTP 500 `configuration error`, while `/healthz` (which answers before the config is loaded) stays green.

`bin/provision` is separate: it runs once, only at container start (before `php-fpm` starts listening), and writes the engine's own configuration from `[mail]` (see below). A change to `[mail]` therefore needs a `php`-role restart to take effect; it is not picked up per-request.

### `[webmail]`

| Key | Type | Default | Rule |
|---|---|---|---|
| `base_url` | string | — (required) | Must be an `https://` URL with no path, query or fragment (an origin only). All redirect and logout URIs are built from this value. |
| `data_dir` | string | `/var/lib/x2mail-webmail` | Non-empty string; trailing slashes are stripped. |

### `[oidc]`

| Key | Type | Default | Rule |
|---|---|---|---|
| `issuer` | string | — (required) | Must be an `https://` URL. |
| `client_id` | string | — (required) | Non-empty string. |
| `client_secret_file` | string | — (required) | Path to a file readable at start; the file must exist, be readable and contain a non-empty value after trimming whitespace — the secret is never taken from the TOML file directly. |
| `tenant_claim` | string | `organization` | Non-empty string; the dot path in the token claims that must resolve to a list with exactly one string entry matching `tenant_pattern` for the tenant to be considered valid (see section 5). |
| `tenant_pattern` | string | `^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$` | Must compile as a regular expression; the config load fails otherwise. |
| `scopes` | list of strings | `["openid", "email", "profile", "organization"]` | Every element must be a non-empty string. |

### `[mail]`

| Key | Type | Default | Rule |
|---|---|---|---|
| `host` | string | — (required) | Non-empty string; one host used for every tenant, since routing to the right mailbox is done by the mailproxy from the token, not by this config. |
| `imap_port` | integer | `993` | 1–65535. |
| `smtp_port` | integer | `587` | 1–65535. |
| `sieve_port` | integer | `4190` | 1–65535. |

### `[session]`

| Key | Type | Default | Rule |
|---|---|---|---|
| `backend` | string | `files` | Only `"files"` is accepted in this version; any other value is a start error. |

### `[theme]` (optional section)

| Key | Type | Default | Rule |
|---|---|---|---|
| `primary_color` | string | none (engine default applies) | Must match `#rrggbb` (6 hex digits). |

The webmail ships Nextcloud's default theming variables for both light mode and a dark mode that follows the browser's `prefers-color-scheme`. `primary_color` re-tints buttons, links and highlights; it does not replace the rest of the palette. Button text is white in light mode and black in dark mode — a very light brand colour gives poor contrast against white button text in light mode, and a very dark brand colour gives poor contrast against black button text in dark mode. Check both modes before shipping a custom colour.

### `[rules.<name>]`

One or more named sections, at least one required by `[access] login_requires`.

| Key | Type | Default | Rule |
|---|---|---|---|
| `claim` | string | — (required) | Non-empty string; a dot path into the token claims (e.g. `realm_access.roles`). |
| `any_of` | list of strings | `[]` | Every element must be a non-empty string. |
| `all_of` | list of strings | `[]` | Every element must be a non-empty string. |
| `strip_path` | bool | `true` | — |

At least one of `any_of` / `all_of` must be non-empty, or the rule itself is a config error.

### `[access]`

| Key | Type | Default | Rule |
|---|---|---|---|
| `login_requires` | string | — (required) | Must name a rule defined under `[rules.<name>]`. |

### `[dav.<name>]`

One section per DAV adapter (registry key is the section name). Order of definition does not matter.

| Key | Type | Default | Rule |
|---|---|---|---|
| `type` | string | — (required) | Only `"carddav"` is implemented; any other value is a config error. |
| `enabled` | bool | `true` | — |
| `requires` | string | — (required) | Must name a rule defined under `[rules.<name>]`. |
| `url_template` | string | — (required) | Must be an `https://` URL (a `{tenant}` placeholder is substituted before the scheme/host check, so the template itself can contain it). |
| `timeout_s` | integer | `3` | 1–60. |
| `include_system_addressbook` | bool | `true` | — |
| `writable` | string | `personal` | Must be `"personal"` or `"none"`. |

## 4. Identity provider requirements

The webmail's own client at the identity provider:

- Confidential client, standard (authorization code) flow, PKCE (S256).
- Redirect URI: `<base_url>/oidc/callback`.
- Post-logout redirect URI: `<base_url>/`.
- Direct grant (resource owner password) disabled.
- The issued access token must carry: the audiences the mail backend and any DAV backend validate against, an `email` claim, the tenant claim configured under `[oidc] tenant_claim` (default `organization`) as a **list containing exactly one alias** — not a map, not multiple entries — and whatever role claims the configured rules reference (e.g. `realm_access.roles`, `resource_access.<client>.roles`).
- The tenant claim should be a default scope on the webmail's client so it is present without the client having to request it explicitly.

Requirements outside the webmail's own configuration, without which it will not work end to end:

- **Mail backends that validate tokens locally by client (`azp`)**: whatever key material the mail backend uses to verify tokens must be keyed to the webmail client's `azp`. If that key is missing, a component ahead of the mail backend may accept the token while the mail backend itself rejects it (surfacing to the user as `AUTHENTICATIONFAILED`).
- **Logout across a broker**: if the identity provider brokers to a per-tenant IdP, the broker's post-logout callback URI must be registered in that tenant IdP's own client under its post-logout redirect URIs (some identity providers keep these separate from ordinary redirect URIs). If it is missing, logout ends in an "invalid redirect uri" error at the tenant IdP.

## 5. Access rules and DAV

- A rule's `claim` is a dot path into the token's claims (e.g. `realm_access.roles`, `resource_access.some-client.roles`). The value found there may be a string or a list of strings.
- `any_of`: the rule matches if at least one listed value is present at the claim path. `all_of`: the rule matches only if every listed value is present. Both may be set on the same rule; both conditions must then hold.
- `strip_path` (default `true`): a claim value like `/some-role` is treated as `some-role` when matching against `any_of`/`all_of`.
- The tenant is derived **only** from the organization claim (`[oidc] tenant_claim`), never from any other source. It must resolve to a list of strings with exactly one entry that matches `[oidc] tenant_pattern`. That single value is what `{tenant}` in `[dav.<name>] url_template` is substituted with.
- An invalid or missing tenant (wrong shape, zero or more than one entry, pattern mismatch) does not block login or mail — it disables only the DAV entries that use `{tenant}` for that session, logged once.
- Role revocation takes effect with the next token: a session whose current token still carries a role keeps using it until the token is refreshed or the user logs in again with a token that no longer has it.

## 6. Operations

- **`/healthz`** (served by the `web` role, routed over FastCGI to the `php` role) proves that both the `web` process and the `php`/`php-fpm` process are up and reachable from each other — `public/index.php` answers it before loading or validating `webmail.toml`. It does not exercise the application's configuration, the identity provider, or the mail backend. A green `/healthz` means the two containers are up and talking to each other; it does not mean login or mail work.
- **Logs** go to stdout/stderr (`docker compose logs`). Tokens are never logged — at most a token's length and its `kid`. Config errors, provisioning failures and identity-provider errors are logged with a reason but without secrets.
- **Upgrade**: pull the new image and recreate both services (`docker compose pull && docker compose up -d`). `bin/provision` runs again on the `php` role's next start, so any `[mail]` change in `webmail.toml` (the engine's mail-host domain configuration) takes effect on that restart. All other config sections (rules, `[access]`, `[dav.<name>]`, `[theme]`, `[oidc]`) are re-read on every request already and need no restart at all — see section 3.
- **Sessions survive restarts** of the `php` role as long as the data volume is preserved — the `files` session backend stores sessions under the data volume, not in the container's writable layer.
- **Backup**: back up both the config directory (`webmail.toml` and the secret file it references) **and** the data volume. The data volume holds per-user data the engine writes under `<data_dir>/engine/_data_/_default_/storage/<domain>/<user>/`: additional identities, signatures, UI settings, and any PGP keys stored server-side (`Provisioner` enables `allow_additional_identities`; the engine's `FileStorage`/`FileIdentities` providers write there). None of that is regenerated by `bin/provision`. Only the `.sessions` and `cache/` subtrees are regenerable and can be excluded.

## 7. Troubleshooting

| Symptom | Cause / where to look |
|---|---|
| Container exits before `php-fpm` starts, exit code 2, message on stderr starting with `config error:` | Invalid `webmail.toml` — check the message for the exact key. |
| Container exits before `php-fpm` starts, exit code 1, message starting with `provisioning failed:` | Provisioning error other than config validation (e.g. the engine tree could not be prepared). |
| `/healthz` returns 502/504 | The `php` container is down, restarting, or failed at start — `web` reached nginx but could not reach `php-fpm` over FastCGI. Check `docker compose logs php`: exit code 2 is a config error (see above). |
| Requests hang and then show a retry page | The identity provider is unreachable from the `php` container at request time (the `web` role never contacts the IdP, so only `php` needs a network path to the issuer) — the app returns its own retry page (HTTP 503) rather than a stack trace; check network/DNS to the configured `issuer` from the `php` container. |
| Mail login fails with `AUTHENTICATIONFAILED` from the mail backend | The mail backend does not have key material for the webmail client's `azp` — see section 4. |
| Logout ends with "invalid redirect uri" | The post-logout redirect URI is missing at the broker or tenant IdP client — see section 4. |
| Every page returns HTTP 500 `configuration error`, but `/healthz` stays green | A recent `webmail.toml` edit broke config validation (unknown key, wrong type, failed rule) — the config is re-read and re-validated on every request, so the break is live immediately. Check `docker compose logs php` for the `config error: …` line naming the key. |
| Container exits before `php-fpm` starts, exit code 1, message `mkdir: can't create directory '/var/lib/x2mail-webmail/sessions': Permission denied` (raw busybox message, **not** prefixed `provisioning failed:`) | The data volume is not owned by uid/gid 82 (`www-data`) — the `mkdir` in `entrypoint.sh` runs before `bin/provision` and fails first. Fix ownership on the host/volume and restart. |
| No contacts show up | Any of: the DAV rule (`[dav.<name>] requires`) does not match for the current token; the tenant is invalid or missing (see section 5), which disables `{tenant}`-based DAV entries; or the DAV backend returned 401/403, which blocks that user's DAV access for 300 seconds — check the log for a DAV warning and wait, or fix the underlying auth issue. |
