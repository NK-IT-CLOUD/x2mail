# X2Mail standalone webmail

X2Mail without Nextcloud: OIDC login against a central IdP, one mail host for every tenant (the mailproxy routes by token), CardDAV per tenant.

## Layout

- `public/index.php` — front controller (only PHP entry point)
- `bin/provision` — engine setup, run at container start and after every update
- `engine-plugin/standalone/` — engine plugin, copied into the engine data dir by `bin/provision`
- `src/` — config, rules, OIDC, session, engine glue

## Web server

For container deployments, see `OPERATIONS.md` — it covers the image's roles, the reverse proxy in front of it, config/secret/data mounts and the full configuration reference.

The web root is **not** the repository. Two locations are exposed, nothing else:

- `/x2mail/v/current/static/` is aliased read-only to `app/x2mail/v/current/static/` — static assets only, no PHP execution.
- Every other request is passed to `public/index.php` via FastCGI with `SCRIPT_NAME=/index.php`.

`app/index.php`, the engine tree, `app/data/`, `standalone/src`, config and secrets are not web-reachable at all; `App`/`Router` additionally block admin and setup paths for anything that does reach `public/index.php`.

### Without containers

Example nginx server block (adjust root/socket paths, no real hostnames):

```nginx
server {
    listen 443 ssl;
    root /opt/x2mail;

    location = /healthz {
        try_files /nonexistent @php;
    }

    location /x2mail/v/current/static/ {
        alias /opt/x2mail/app/x2mail/v/current/static/;
    }

    location / {
        try_files /nonexistent @php;
    }

    location @php {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME /opt/x2mail/standalone/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        include fastcgi_params;
    }
}
```

IdP unreachable → retry page; loop guard after 3 redirects.

## Run locally

    composer install
    vendor/bin/phpunit
    vendor/bin/phpstan analyse -c phpstan.neon
    X2W_CONFIG=/path/to/webmail.toml bin/provision

Configuration reference: `webmail.example.toml`.
