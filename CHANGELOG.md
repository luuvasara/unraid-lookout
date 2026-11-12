# Changelog

Copied by the build from the <CHANGES> block of unraid-lookout.plg; edit the .plg, not this file.

## 2026.11.12
- Log lines are cleaned before they are checked, stored or shown: colour codes and other terminal escape sequences, control characters and text direction overrides are taken out, and a line is cut at 8 KB.
- A log line of several megabytes without a line end no longer stops Lookout from reading the lines after it.
- `lookout findings` in a terminal prints no escape sequences, also from findings stored by an older version.
- Custom rules are checked field by field. A rule with a wrong type, an id other than letters, digits and . _ : -, a pattern over 1000 characters, or a pattern that is very slow on long lines is skipped and listed by `lookout check-rules` with the reason, instead of stopping the daemon. A custom-rules.json over 1 MB is not read.
- Settings with a wrong type fall back to the default instead of stopping the page or the daemon.
- Notifications carry no line breaks, control characters or angle brackets, and are kept short: Unraid stores them one setting per line.

## 2026.11.08a
- Drives tab: a drive behind more than one PCIe bridge now names its controller, not a bridge in between.

## 2026.11.07
- A finding whose log line names a drive by a kernel name, a port or a file system id (ata7, sdk1, nvme2, 7:0:2:0, btrfs devid 2) now says which drive that is: its Unraid slot, model and serial.
- New Drives tab: every drive with all the names it goes by, and a box that finds the drive a name or a pasted log line means.

## 2026.11.05a
- The description now leads with the errors Lookout is built for: obscure ones whose message doesn't point at the real problem, and alarming lines that mean nothing. The rules are unchanged.

## 2026.11.05
- First release.
- More than 100 rules for known Unraid log errors: disks, the array, file systems, hardware, network, Docker, VMs, shares and more. Each finding says what the error means, what to check, and which setting or file it concerns.
- Severity follows what the line says: a corrected memory or CPU error is listed as info, an uncorrected one as critical.
- Checks the system log, the Docker log and the libvirt and VM logs as they are written, and the log saved at the last shutdown, if your server keeps one.
- What is already in the logs at the first start is listed, without notifications. Notifications are for what happens from then on.
- A repeated error stays one finding with a count, also when numbers or addresses in the line differ. A flood of messages from one rule folds into one finding.
- Optional Unraid notifications for critical or warning findings: one per rule within a time you set, and any rule can be muted.
- Your own rules: custom-rules.json on the flash drive.
