# Changelog

Copied by the build from the <CHANGES> block of unraid-lookout.plg; edit the .plg, not this file.

## 2026.11.05
- First release.
- More than 100 rules for known Unraid log errors: disks, the array, file systems, hardware, network, Docker, VMs, shares and more. Each finding says what the error means, what to check, and which setting or file it concerns.
- Severity follows what the line says: a corrected memory or CPU error is listed as info, an uncorrected one as critical.
- Checks the system log, the Docker log and the libvirt and VM logs as they are written, and the log saved at the last shutdown, if your server keeps one.
- What is already in the logs at the first start is listed, without notifications. Notifications are for what happens from then on.
- A repeated error stays one finding with a count, also when numbers or addresses in the line differ. A flood of messages from one rule folds into one finding.
- Optional Unraid notifications for critical or warning findings: one per rule within a time you set, and any rule can be muted.
- Your own rules: custom-rules.json on the flash drive.
