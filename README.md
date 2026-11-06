<img src="icon.png" width="96" alt="Lookout">

# Lookout for Unraid

A database of known Unraid log errors, each with what it means and what to do about it, and a small daemon that checks your logs against it.

Lookout especially goes after the obscure ones: errors whose message doesn't point at the real problem, and alarming lines that mean nothing.

When the log says `auth request unexpected status: 504`, nothing in that line mentions the real problem: every php-fpm worker is busy. Lookout lists it under Settings, Lookout with that explanation, what to check, and the setting or file it concerns.

## Messages that mislead

| The log says | What is usually behind it |
|---|---|
| `No space left on device`, with plenty of free space | BTRFS metadata chunks are full, or old Docker subvolumes are holding space |
| `Array has 1 failed device` | Parity is marked invalid; no device may have failed |
| `auth request unexpected status: 504` | Every php-fpm worker is busy, so the WebGUI's login check times out |
| `database is locked` in a container | Its SQLite database is on SMB, which lacks the file locking SQLite needs |
| `fork: Cannot allocate memory` | Can be exhausted inotify watches rather than RAM |
| `failed to register layer` | `docker.img` is damaged, usually after a full cache or an unclean shutdown |
| `failed to prefill DIMM database` | Harmless: the motherboard's tables are incomplete |

## Screenshots

![The Findings tab](docs/screenshots/findings.png)

The Findings tab: each message with what it means and what to do.

![A finding for a 504 error](docs/screenshots/finding-504.png)

`auth request unexpected status: 504` explained, with the log line shown.

![A finding for a failed device message](docs/screenshots/finding-array.png)

"Array has 1 failed device" can just mean parity is marked invalid.

![The Settings tab](docs/screenshots/settings.png)

The Settings tab.

## What it reads

- The system log, the Docker log, the libvirt log and the log of each VM, as they are written.
- At the first start of a boot, what is already in those logs.
- The log saved at the last shutdown, `/boot/logs/syslog-previous`, once per boot, if your server keeps one. Findings from it show the source "syslog (previous boot)".

It only reads logs. It never changes a setting or restarts anything.

## The rules

More than 100 rules in 13 categories, in `/usr/local/share/unraid-lookout/rules.json`. Each one has a pattern, a severity (critical, warning or info), a description, a recommendation and, where it applies, the setting or config file.

Your own rules go in `/boot/config/plugins/unraid-lookout/custom-rules.json`:

```json
{"rules": [{
  "id": "my-ups-lost",
  "category": "power",
  "name": "UPS connection lost",
  "pattern": "apcupsd.*communications with UPS lost",
  "log_sources": ["syslog"],
  "severity": "warning",
  "description": "The server cannot reach the UPS.",
  "recommendation": "Check the USB cable of the UPS."
}]}
```

`severity` is one level per rule. To let it follow the line, make two rules, as the shipped ones do for corrected and uncorrected errors, with `exclude_pattern` to keep them apart.

`pattern` is a case-insensitive regular expression. An optional `exclude_pattern` drops lines that also match it. `log_sources` is any of `syslog`, `docker`, `libvirt`; without it the rule applies to all. An id that is already in the shipped rules is skipped. Reload Rules on the Findings tab loads them; `lookout check-rules` in a terminal lists rules that do not compile.

Rules are read only from the package and the flash drive.

## Repeats and floods

The same message inside the dedup window (300 seconds by default) counts on one finding, also when numbers, addresses or ids in the line differ. When one rule produces more than five different messages from one source in that window, the rest fold into the latest finding, marked "(flood)".

## Notifications

Settings, Notifications: off, critical findings (default), or warning and critical findings. One notification per rule within the cooldown, 6 hours by default. Mute on a finding stops the notifications of that rule; the finding is still listed. A notification carries the rule's text, not the log line.

## Files

| Path | What |
|---|---|
| `/boot/config/plugins/unraid-lookout/config.json` | Settings |
| `/boot/config/plugins/unraid-lookout/custom-rules.json` | Your rules |
| `/var/local/emhttp/plugins/unraid-lookout/findings.json` | Findings, in RAM until the next reboot |
| `/var/log/lookout.log` | The daemon's own log |

## Command line

`lookout status`, `findings`, `findings json`, `ack <id>`, `clear`, `reload`, `check-rules`. Start and stop: `/etc/rc.d/rc.lookout start|stop|restart`.

## Install

Plugins, Install Plugin:

```
https://raw.githubusercontent.com/luuvasara/unraid-lookout/main/unraid-lookout.plg
```

Unraid 6.12 or later.

## Build

`./build.sh` on a workstation; `tests/run-tests.sh` runs the tests. The build, the About tab and the install message come from `kit/`, which is part of this repository.

## Licence

GPL-2.0, see [LICENSE](LICENSE). Copyright (C) 2026 Lookout project.
