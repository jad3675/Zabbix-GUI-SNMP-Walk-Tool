# Running the module in a containerised Zabbix

The frontend container is where this module runs, so that is where `php-snmp` and
`snmptranslate` have to be. The server container is irrelevant to it unless you use the
script engine.

## Quick, temporary

Enough to try the console. Survives `docker restart`, not `docker compose up -d`.

```
docker compose exec -u root zabbix-web sh -lc '
  FPM="$(ls /usr/sbin/php-fpm* /usr/bin/php-fpm* 2>/dev/null | head -n1)"
  V="$(basename "$FPM" | tr -cd 0-9)"
  echo "php-fpm is $FPM, version $V"
  apk update
  apk add --no-cache "php$V-snmp" net-snmp-tools
  install -d -o zabbix -g zabbix -m 0750 /var/lib/zabbix/snmpwalk
  ls "/etc/php$V/conf.d" | grep -i snmp
'
docker compose restart zabbix-web
```

Three traps in that, all of which produce a silent no-op rather than an error:

**Derive the version from the php-fpm binary, not from `php`.** These images ship no
PHP CLI, and can carry config trees for several PHP versions at once. On a
`zabbix-web-nginx-pgsql:alpine-7.4` image there is both `/etc/php84` and `/etc/php85`,
and only the second has `php-fpm.conf`. Installing `php84-snmp` there puts the
extension and its ini where the running FPM never looks.

**`apk update` before `apk add`.** Without a package index apk reports every package as
"no such package", which reads like the package name being wrong rather than the index
being absent.

**Restart the container.** `apk add` writes into the container layer, but the running
FPM master will not load a new extension, and there is no separate FPM service to
bounce inside these images: the container *is* the service.

There is no CLI to run `php -m` against, so the ini appearing in `/etc/php<V>/conf.d`
is the check, and the console's "Run from" note is the confirmation: it reads the live
SAPI, which is the only opinion that counts.

## Durable

Use the `Dockerfile` in this directory:

```yaml
services:
  zabbix-web:
    build:
      context: ./snmpwalk-web
      args:
        ZABBIX_WEB_IMAGE: zabbix/zabbix-web-nginx-pgsql:alpine-7.0-latest
    volumes:
      - ./modules/SnmpWalk:/usr/share/zabbix/modules/SnmpWalk:ro
      - snmpwalk-data:/var/lib/zabbix/snmpwalk

volumes:
  snmpwalk-data:
```

Two things worth doing here regardless of how you install the extension:

**Mount the module read-only from the host.** Otherwise it lives inside the container
and disappears on recreate, and you are back to re-extracting a zip after every update.

**Give the data directory a named volume.** The MIB index costs one `snmptranslate` run
to rebuild, so losing it is cheap. Losing every saved snapshot is not: those are the
baselines the diff compares against, and a pre-upgrade walk you cannot reproduce is
gone for good.

## Network reachability

The local engine sends SNMP packets from the *frontend* container, not from the server
container and not from the host. On a default bridge network it will have a route to
your management subnet through the host, but a device with an ACL on its SNMP community
will see the request arriving from the container's NAT address rather than from the
Zabbix server's, and drop it.

If walks time out from the frontend while the server polls the same host fine, that is
what happened. Options, in order of how much you will regret them later: put the
frontend container on the same macvlan or host network as the server, add the container
network to the device ACLs, or use the script engine so the packet leaves from the
server or proxy that the device already trusts.

## MIBs

`net-snmp-tools` brings `snmptranslate` but Alpine is stingy about shipping the MIB
files themselves. If the index builds with very few objects, that is why. Either add
whichever Alpine package carries them on your base image, or upload the MIBs you
actually care about through *Monitoring* → *SNMP walk* → *MIBs*, which stores them in
the data directory and therefore in the named volume above.
