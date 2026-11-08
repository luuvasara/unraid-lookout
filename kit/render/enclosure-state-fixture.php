<?php
/* enclosure-state-fixture.php <dir>: Unraid's state files for the drives of render/enclosure-fixture.php (run that first, same
 * dir). As on the measured server, every drive of the enclosure is unassigned (devs.ini, dev1..), with the fields and the
 * order Unraid 7.3.4 writes; temperatures are made up, sdj is in standby, sdo has a CRC error count in its SMART report
 * (attribute 199), sdh has none yet. /var/local/emhttp/smart/<name> is smartctl -A output in the layout Unraid saves. */
$W = $argv[1] ?? ($ENCLOSURE_FIXTURE ?? '');
if ($W === '') { fwrite(STDERR, "usage: enclosure-state-fixture.php DIR\n"); exit(2); }
$temps = ['sdk' => 36, 'sdj' => null, 'sde' => 40, 'sdp' => 38, 'sdo' => 41, 'sdf' => 39, 'sdn' => 42, 'sdm' => 39, 'sdl' => 37, 'sdi' => 40,
          'sdh' => 31, 'sdg' => 30, 'sdd' => 29];
@mkdir("$W/var/local/emhttp/smart", 0755, true);
$ini = ''; $n = 0;
$serials = [];
foreach (glob("$W/dev/disk/by-id/ata-*") as $l) if (substr($l, -6, 5) !== '-part') $serials[basename(readlink($l))] = substr(basename($l), 4);
foreach ($temps as $dev => $t) {
  $n++;
  $sectors = trim((string)@file_get_contents("$W/sys/block/$dev/size"));
  $rot = trim((string)@file_get_contents("$W/sys/block/$dev/queue/rotational"));
  $ini .= "[\"dev$n\"]\nname=\"dev$n\"\nid=\"" . ($serials[$dev] ?? $dev) . "\"\ndevice=\"$dev\"\ntransport=\"ata\"\nsectors=\"$sectors\"\nsector_size=\"512\"\n"
        . "rotational=\"$rot\"\ndiscard=\"0\"\nremovable=\"0\"\nspundown=\"" . ($t === null ? 1 : 0) . "\"\ntemp=\"" . ($t === null ? '*' : $t) . "\"\nnumReads=\"189\"\nnumWrites=\"0\"\n";
  if ($dev === 'sdh') continue;
  $crc = $dev === 'sdo' ? 14 : 0;
  file_put_contents("$W/var/local/emhttp/smart/dev$n", "smartctl 7.5 2025-04-30 r5714 [x86_64-linux-6.18.58-Unraid] (local build)\n"
    . "Copyright (C) 2002-25, Bruce Allen, Christian Franke, www.smartmontools.org\n\n=== START OF READ SMART DATA SECTION ===\n"
    . "SMART Attributes Data Structure revision number: 16\nVendor Specific SMART Attributes with Thresholds:\n"
    . "ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE\n"
    . "  5 Reallocated_Sector_Ct   0x0033   200   200   140    Pre-fail  Always       -       0\n"
    . "194 Temperature_Celsius     0x0022   114   109   000    Old_age   Always       -       " . ($t ?? 35) . "\n"
    . "197 Current_Pending_Sector  0x0032   200   200   000    Old_age   Always       -       0\n"
    . "199 UDMA_CRC_Error_Count    0x0032   200   200   000    Old_age   Always       -       $crc\n");
}
file_put_contents("$W/var/local/emhttp/devs.ini", $ini);
