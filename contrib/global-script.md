# Setting up the script engine

The script engine runs the walk on the Zabbix server or on the proxy that owns the
host, using nothing but published API. Set it up once and it keeps working across
frontend upgrades, which is the argument for it over the server engine.

## 1. Install the wrapper

On the Zabbix server, and on every proxy that polls SNMP hosts:

```
install -o root -g zabbix -m 0750 zbx-snmpwalk.sh /usr/local/bin/zbx-snmpwalk.sh
```

The wrapper takes credentials through the environment rather than argv, so the
community string does not show up in `ps` for every user on the poller.

## 2. Create the global script

*Alerts* → *Scripts* → *Create script*

| Field | Value |
|---|---|
| Name | `SNMP walk` |
| Scope | Manual host action |
| Menu path | leave empty |
| Type | Script |
| Execute on | Zabbix server (proxy) |
| Timeout | `60s` |
| Enable user input | checked |
| Input prompt | `OID to walk` |
| Input type | String |
| Default input string | `1.3.6.1.2.1` |
| Input validation rule | `^\.?[0-9]+(\.[0-9]+)*$` |

Commands, for SNMPv1 and v2c:

```
ZBX_COMMUNITY='{$SNMP_COMMUNITY}' /usr/local/bin/zbx-snmpwalk.sh '{HOST.CONN}' '{$SNMP_PORT}' '2c' '{MANUALINPUT}'
```

If your estate is SNMPv3, create a second script with the same shape:

```
ZBX_SECNAME='{$SNMP_SECNAME}' ZBX_AUTHPASS='{$SNMP_AUTHPASS}' ZBX_PRIVPASS='{$SNMP_PRIVPASS}' \
  /usr/local/bin/zbx-snmpwalk.sh '{HOST.CONN}' '{$SNMP_PORT}' '3' '{MANUALINPUT}' \
  'authPriv' '{$SNMP_AUTHPROTOCOL}' '{$SNMP_PRIVPROTOCOL}'
```

Set *Host group* on the script to restrict which hosts it can be run against, and
*User group* to restrict who can run it. The module checks its own permissions, but
these are the ones Zabbix enforces, so set both.

## 3. Point the module at it

Note the script's ID from the URL on the script edit form, then put it in
`manifest.json`:

```json
"config": {
    "script_id": "42",
    "engine": "script"
}
```

Re-scan the module directory in *Administration* → *General* → *Modules* so the new
configuration is picked up.

## Notes

The script timeout caps how long a walk can run. Sixty seconds is enough for a full
`1.3.6.1.2.1` on most switches; a large chassis with a deep entity MIB may need more,
and the server's own `Timeout` setting is a separate ceiling.

`{$SNMP_PORT}` is not a stock macro. Either define it globally as `161`, or replace it
with a literal `161` in the command if none of your devices use a non-standard port.
`{HOST.CONN}` resolves against the host's default interface, which is not necessarily
the SNMP one on a host that also has an agent interface; check that before assuming a
failed walk means a dead device.
