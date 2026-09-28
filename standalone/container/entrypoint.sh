#!/bin/sh
# Roles: php (provision, then php-fpm) | web (nginx) | web-check (nginx -t against the runtime
# config, used at build time as www-data). Anything else is executed as given.
set -eu

# Writes /tmp/nginx-runtime.conf: a resolver (so nginx re-resolves the php upstream per request
# instead of failing at startup/config-test if it isn't up yet) and the $x2w_php upstream variable.
write_nginx_runtime() {
    nameserver=$(awk '/^nameserver/{print $2; exit}' /etc/resolv.conf)
    if [ -z "${nameserver:-}" ]; then
        echo "write_nginx_runtime: no nameserver found in /etc/resolv.conf" >&2
        exit 1
    fi
    cat > /tmp/nginx-runtime.conf <<EOF
resolver ${nameserver} valid=10s ipv6=off;
map "" \$x2w_php { default "${X2W_PHP_UPSTREAM:-php:9000}"; }
EOF
}

case "${1:-php}" in
    php)
        umask 027
        mkdir -p /var/lib/x2mail-webmail/sessions
        php /srv/x2mail/standalone/bin/provision
        exec php-fpm --nodaemonize
        ;;
    web)
        write_nginx_runtime
        exec nginx -e stderr -g 'daemon off;'
        ;;
    web-check)
        write_nginx_runtime
        exec nginx -t -e stderr
        ;;
    *)
        exec "$@"
        ;;
esac
