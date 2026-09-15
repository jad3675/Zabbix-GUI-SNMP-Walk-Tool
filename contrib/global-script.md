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

The macro references in the command above must stay literal. Zabbix server resolves them
when it runs the script, which is the entire reason this engine can reach credentials the
frontend cannot. Do not substitute real values into the command field.

## Secret text and Vault macros

Global script commands are one of the locations where Zabbix server unmasks secret macro
values, so `{$SNMP_COMMUNITY}` resolves here even when the macro is Secret text or a Vault
secret. The frontend cannot read either (`usermacro.get` does not return those values to
anyone), which makes this engine mandatory rather than optional for those hosts: the local
and server engines are refused for them, with the macro named in the message.

The flip side is worth stating plainly. Anyone who can edit this script can replace the
command with `echo '{$SNMP_COMMUNITY}'` and read the value off the screen. That is true of
any global script and is why *User group* above is not optional. Restrict script editing
to the people who would already be told the community string.

## If the script never runs

Two server settings can block execution regardless of how the script is configured:

* `EnableGlobalScripts` in `zabbix_server.conf` controls execution on the server. For new
  installations since 7.0 it defaults to disabled. Set it to `1` and restart the server.
* `EnableRemoteCommands` in `zabbix_proxy.conf` controls execution on a proxy, and is off
  by default. Set it on every proxy that owns SNMP hosts.

`diagnose()` cannot see either flag, so a script that passes the module's own checks can
still fail at execution with a permission error from the server. Check these two first.

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
