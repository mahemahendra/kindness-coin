<?php
$zip = new ZipArchive();
$zip->open(__DIR__ . '/data/LIONS_CA_COUNTRIES.xlsx');

echo "=== workbook.xml ===\n";
echo $zip->getFromName('xl/workbook.xml') . "\n\n";

echo "=== sheet2.xml (first 1500 chars) ===\n";
echo substr($zip->getFromName('xl/worksheets/sheet2.xml'), 0, 1500) . "\n\n";

echo "=== sheet3.xml (first 1500 chars) ===\n";
echo substr($zip->getFromName('xl/worksheets/sheet3.xml'), 0, 1500) . "\n";

$zip->close();
