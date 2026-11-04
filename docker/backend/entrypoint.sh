#!/bin/sh
set -eu
for directory in "${UPLOAD_DIR:-/var/app/uploads}" "${IMAGE_DIR:-/var/app/images}"; do
    case "$directory" in
        /var/app/*) ;;
        *) echo 'Storage paths must be inside /var/app/.' >&2; exit 78 ;;
    esac
    case "$directory" in
        */../*|*/..|*/./*|*/.) echo 'Storage paths must not contain traversal segments.' >&2; exit 78 ;;
    esac
done
if [ "${UPLOAD_DIR:-/var/app/uploads}" = "${IMAGE_DIR:-/var/app/images}" ]; then
    echo 'Upload and image directories must be different.' >&2
    exit 78
fi
mkdir -p "${UPLOAD_DIR:-/var/app/uploads}" "${IMAGE_DIR:-/var/app/images}"
chown www-data:www-data "${UPLOAD_DIR:-/var/app/uploads}" "${IMAGE_DIR:-/var/app/images}"
if [ "${1:-}" = 'php-fpm' ]; then
    # The official image's FPM master opens the container log pipe as root;
    # its request workers use the pool's www-data account.
    exec docker-php-entrypoint "$@"
fi
exec gosu www-data docker-php-entrypoint "$@"
