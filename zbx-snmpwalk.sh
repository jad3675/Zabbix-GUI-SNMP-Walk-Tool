#!/bin/sh
#
# Wrapper for the SNMP walk module's script engine.
#
# Install on the Zabbix server and on every proxy that owns SNMP hosts:
#   install -o root -g zabbix -m 0750 zbx-snmpwalk.sh /usr/local/bin/zbx-snmpwalk.sh
#
# Two things this does that a bare snmpwalk command in the script field does not:
#
#  1. Credentials arrive in the environment, not in argv. Process arguments are
#     world-readable through ps; the environment of a process is not. A community
#     string in a script field is a community string on the screen of anyone with a
#     shell on the poller.
#
#  2. The OID is validated again here. The module already validates it, but this
#     script is reachable by anyone who can run the global script, so it does not
#     assume its caller was careful.
#
# Called as:
#   ZBX_COMMUNITY=... zbx-snmpwalk.sh <address> <port> <version> <oid>
#   ZBX_SECNAME=... ZBX_AUTHPASS=... ZBX_PRIVPASS=... zbx-snmpwalk.sh <address> <port> 3 <oid> \
#       <seclevel> <authproto> <privproto> [context]

set -eu

ADDRESS="${1:-}"
PORT="${2:-161}"
VERSION="${3:-2c}"
OID="${4:-}"

if [ -z "$ADDRESS" ] || [ -z "$OID" ]; then
	echo "usage: $0 <address> <port> <version> <oid>" >&2
	exit 2
fi

# Numeric OIDs only, with an optional leading dot. Anything else is refused rather
# than quoted, because there is no legitimate reason for this script to receive it.
case "$OID" in
	.*) CHECK="${OID#.}" ;;
	*) CHECK="$OID" ;;
esac

if ! printf '%s' "$CHECK" | grep -Eq '^[0-9]+(\.[0-9]+)*$'; then
	echo "refusing non-numeric OID" >&2
	exit 2
fi

if ! printf '%s' "$ADDRESS" | grep -Eq '^[A-Za-z0-9._:-]+$'; then
	echo "refusing malformed address" >&2
	exit 2
fi

if ! printf '%s' "$PORT" | grep -Eq '^[0-9]{1,5}$'; then
	echo "refusing malformed port" >&2
	exit 2
fi

# -On numeric OIDs: the module indexes on numeric form and translates for display, so
#     symbolic output here would have to be translated back.
# -Ir do not try to fill in the value type from a MIB the poller may not have.
# -t/-r keep a dead device from holding the script slot open until the timeout.
COMMON="-On -Ir -t 3 -r 2 -m none"

case "$VERSION" in
	1|2c)
		: "${ZBX_COMMUNITY:?community not supplied in the environment}"
		exec snmpwalk -v "$VERSION" -c "$ZBX_COMMUNITY" $COMMON "${ADDRESS}:${PORT}" "$OID"
		;;
	3)
		SECLEVEL="${5:-authPriv}"
		AUTHPROTO="${6:-SHA}"
		PRIVPROTO="${7:-AES}"
		CONTEXT="${8:-}"

		: "${ZBX_SECNAME:?security name not supplied in the environment}"

		set -- -v 3 -l "$SECLEVEL" -u "$ZBX_SECNAME"

		if [ "$SECLEVEL" != "noAuthNoPriv" ]; then
			: "${ZBX_AUTHPASS:?auth passphrase not supplied in the environment}"
			set -- "$@" -a "$AUTHPROTO" -A "$ZBX_AUTHPASS"
		fi

		if [ "$SECLEVEL" = "authPriv" ]; then
			: "${ZBX_PRIVPASS:?priv passphrase not supplied in the environment}"
			set -- "$@" -x "$PRIVPROTO" -X "$ZBX_PRIVPASS"
		fi

		if [ -n "$CONTEXT" ]; then
			set -- "$@" -n "$CONTEXT"
		fi

		exec snmpwalk "$@" $COMMON "${ADDRESS}:${PORT}" "$OID"
		;;
	*)
		echo "unsupported SNMP version: $VERSION" >&2
		exit 2
		;;
esac
