#!/bin/bash
if [ -n "${FPP_BUILD_PROFILE:-}" ] || [ ! -f /opt/fpp/src/fppinit ]; then
    cd /opt/fpp/src
    CPUS=$(/opt/fpp/scripts/functions ComputeMakeParallelism)
    BUILD_TARGET=all
    if [ -n "${FPP_BUILD_PROFILE:-}" ]; then
        # ADR: The Playwright Docker stack must explicitly rebuild the mounted
        # workspace with the requested CI profile so it doesn't reuse stale
        # host-built binaries. See adr/0001-ci-web-base-playwright-docker-profile.md
        case "${FPP_BUILD_PROFILE}" in
            ci-web-base)
                BUILD_TARGET="ci-web-base"
                ;;
            *)
                echo "Unknown FPP_BUILD_PROFILE '${FPP_BUILD_PROFILE}', defaulting to '${BUILD_TARGET}'"
                ;;
        esac
        make clean
    fi
    make -j ${CPUS} ${BUILD_TARGET}
fi

# Detect if running in a container and flag accordingly
if [ -f "/.dockerenv" ]
then
	echo "docker" > /etc/fpp/container
elif [ -f "/run/.containerenv" -o -f "/var/run/.containerenv" ]
then
	echo "podman" > /etc/fpp/container
elif [ -f "/etc/fpp/container" ]
then
	rm /etc/fpp/container
fi

/opt/fpp/src/fppinit start
/opt/fpp/scripts/fppd_start

mkdir /run/php
/usr/sbin/php-fpm8.4 --fpm-config /etc/php/8.4/fpm/php-fpm.conf
/usr/sbin/apache2ctl -D FOREGROUND
