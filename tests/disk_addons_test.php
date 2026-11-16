<?php
error_reporting(E_ALL & ~E_WARNING);
require_once __DIR__ . '/../pterosync/helper.php';

function check($name, $actual, $expected)
{
    if ($actual === $expected) {
        echo "PASS: $name\n";
        return;
    }
    echo "FAIL: $name (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    exit(1);
}

check('Package A + 5GB addon', pteroSyncComputeTotalDiskMb(20480, [5], true), 25600);
check('Package B + 5GB addon (upgrade)', pteroSyncComputeTotalDiskMb(40960, [5], true), 46080);
check('two 10GB addons', pteroSyncComputeTotalDiskMb(20480, [10, 10], true), 40960);
check('no addons', pteroSyncComputeTotalDiskMb(20480, [], true), 20480);
check('MB mode addon', pteroSyncComputeTotalDiskMb(20480, [512], false), 20992);
check('unlimited base stays 0', pteroSyncComputeTotalDiskMb(0, [10], true), 0);
check('invalid amounts ignored', pteroSyncComputeTotalDiskMb(20480, ['abc', -5, '', null, 5], true), 25600);
check('decimal string amount', pteroSyncComputeTotalDiskMb(20480, ['2.5'], true), 23040);
