# Zabbix web frontend with the SNMP walk module's dependencies baked in.
#
# Installing php-snmp with "docker exec ... apk add" works, and survives a
# "docker restart", but not a "docker compose up -d" that recreates the container.
# Six weeks later someone updates the image and the console quietly loses its best
# engine. Build it in instead.
#
# Pin the tag to whatever you are already running:
#   docker compose build zabbix-web && docker compose up -d zabbix-web
ARG ZABBIX_WEB_IMAGE=zabbix/zabbix-web-nginx-pgsql:alpine-7.0-latest
FROM ${ZABBIX_WEB_IMAGE}

USER root

# The PHP major/minor in the Zabbix Alpine images changes between releases, so the
# package name is derived rather than hardcoded. This turns a wrong guess into a build
# failure with a readable message instead of a silently missing extension.
# The version is taken from the php-fpm binary, not from a "php" CLI: these images
# ship no CLI at all, and can carry config trees for more than one PHP version. Only
# the one FPM runs under counts -- installing the extension against the other puts the
# .so and its ini somewhere the web server never reads, which looks identical to the
# install having done nothing.
RUN set -eux; \
	FPM="$(ls /usr/sbin/php-fpm* /usr/bin/php-fpm* 2>/dev/null | head -n1)"; \
	[ -n "$FPM" ] || { echo "no php-fpm binary found"; exit 1; }; \
	PHP_VER="$(basename "$FPM" | tr -cd '0-9')"; \
	[ -n "$PHP_VER" ] || { echo "cannot derive php version from $FPM"; exit 1; }; \
	echo "building against php${PHP_VER} (from $FPM)"; \
	apk update; \
	apk add --no-cache \
		"php${PHP_VER}-snmp" \
		net-snmp-tools; \
	ls "/etc/php${PHP_VER}/conf.d" | grep -qi snmp \
		|| { echo "snmp ini did not land in /etc/php${PHP_VER}/conf.d"; \
		     ls "/etc/php${PHP_VER}/conf.d"; exit 1; }; \
	command -v snmptranslate >/dev/null || { echo "snmptranslate missing"; exit 1; }

# Data directory for the MIB index, snapshots and in-progress walk buffers. Mount a
# volume over this in compose if you want snapshots to outlive the container.
RUN install -d -o zabbix -g zabbix -m 0750 /var/lib/zabbix/snmpwalk

USER zabbix
