<?php
/**
 * One-time parser: LIONS_CA_COUNTRIES.xlsx → forms/ca-map.php
 *
 * XLSX structure (after header row is skipped):
 *   Column A = Constitutional Area name
 *   Column B = Region
 *   Column C = Country name
 *
 * Run once from the project root:
 *   php forms/parse-ca-xlsx.php
 *
 * Produces: forms/ca-map.php  (a PHP file returning an associative array)
 * Keys are lowercased country names for case-insensitive lookup.
 */

$xlsxPath   = __DIR__ . '/data/LIONS_CA_COUNTRIES.xlsx';
$outputPath = __DIR__ . '/ca-map.php';

if (!file_exists($xlsxPath)) {
    fwrite(STDERR, "Error: File not found: $xlsxPath\n");
    fwrite(STDERR, "Place LIONS_CA_COUNTRIES.xlsx in forms/data/ and re-run.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($xlsxPath) !== true) {
    fwrite(STDERR, "Error: Could not open XLSX file as a ZIP archive.\n");
    exit(1);
}

// Strip all XML namespaces so XPath queries work without prefixes.
// Removes xmlns declarations, prefixed attributes (e.g. xr:uid, x14ac:dyDescent),
// and prefixed wrapper elements (e.g. <mc:AlternateContent>).
function stripDefaultNs(string $xml): string
{
    // Remove all xmlns:* and xmlns= declarations
    $xml = preg_replace('/ xmlns(?::[a-zA-Z0-9_]+)?="[^"]*"/', '', $xml);
    // Remove all prefixed attributes  e.g.  xr:uid="{...}"  x14ac:dyDescent="0.35"  mc:Ignorable="..."
    $xml = preg_replace('/ [a-zA-Z][a-zA-Z0-9_]*:[a-zA-Z][a-zA-Z0-9_]*="[^"]*"/', '', $xml);
    // Remove prefixed element open/close tags  e.g.  <mc:AlternateContent>  </xr:revisionPtr/>
    $xml = preg_replace('/<\/?[a-zA-Z][a-zA-Z0-9_]*:[a-zA-Z][a-zA-Z0-9_]*[^>]*\/?>/', '', $xml);
    return $xml;
}

// --- Build shared-string table from xl/sharedStrings.xml ---
$sharedStrings = [];
$ssContent = $zip->getFromName('xl/sharedStrings.xml');
if ($ssContent !== false) {
    $ssXml = new SimpleXMLElement(stripDefaultNs($ssContent));
    foreach ($ssXml->xpath('//si') as $si) {
        // Plain text: <t>; Rich text: concatenate <r><t>
        $t = $si->xpath('t');
        if ($t) {
            $sharedStrings[] = (string)$t[0];
        } else {
            $text = '';
            foreach ($si->xpath('r/t') as $rt) {
                $text .= (string)$rt;
            }
            $sharedStrings[] = $text;
        }
    }
}

// --- Resolve which physical file corresponds to 'Sheet4' via workbook rels ---
// xl/workbook.xml lists sheets by r:id; xl/_rels/workbook.xml.rels maps r:id → filename.
$wsContent = false;
$relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
$wbXml    = $zip->getFromName('xl/workbook.xml');
if ($relsXml !== false && $wbXml !== false) {
    // Build rId → target path map (strip namespaces for clean parsing)
    $rels = new SimpleXMLElement(stripDefaultNs($relsXml));
    $ridToPath = [];
    foreach ($rels->xpath('//Relationship') as $rel) {
        $ridToPath[(string)$rel['Id']] = 'xl/' . ltrim((string)$rel['Target'], '/');
    }
    // Find the rId for the sheet named 'Sheet4'
    $wb = new SimpleXMLElement(stripDefaultNs($wbXml));
    $targetRid = null;
    foreach ($wb->xpath('//sheet') as $sheet) {
        if (strtolower((string)$sheet['name']) === 'sheet4') {
            // The r:id attribute loses its prefix after stripping; try both attribute names
            $targetRid = (string)($sheet['id'] ?? $sheet['r:id'] ?? '');
            break;
        }
    }
    if ($targetRid && isset($ridToPath[$targetRid])) {
        $wsContent = $zip->getFromName($ridToPath[$targetRid]);
    }
}
// Fallback: try every physical sheet file and use the one with 3-column data
if ($wsContent === false) {
    for ($i = 1; $i <= 10; $i++) {
        $candidate = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
        if ($candidate !== false && strpos($candidate, 'spans="1:3"') !== false) {
            $wsContent = $candidate;
            // Prefer the sheet with the most rows (largest file)
            break;
        }
    }
}

$zip->close();

if ($wsContent === false) {
    fwrite(STDERR, "Error: Could not read any worksheet from the XLSX file.\n");
    exit(1);
}

$wsXml = new SimpleXMLElement(stripDefaultNs($wsContent));

/**
 * Convert a cell reference column letters (e.g. "A", "BC") to a 0-based column index.
 */
function cellColIndex(string $ref): int
{
    preg_match('/^([A-Z]+)/i', $ref, $m);
    $letters = strtoupper($m[1] ?? 'A');
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $idx - 1; // 0-based
}

/**
 * Resolve a cell's string value, handling shared strings and inline strings.
 */
function cellValue(SimpleXMLElement $c, array $sharedStrings): string
{
    $t = (string)($c['t'] ?? '');
    if ($t === 's') {
        // Shared string reference
        $idx = (int)(string)$c->v;
        return $sharedStrings[$idx] ?? '';
    } elseif ($t === 'inlineStr') {
        return (string)($c->is->t ?? '');
    } else {
        return (string)($c->v ?? '');
    }
}

// --- Parse rows: Col A = Constitutional Area, Col C = Country ---
// Each entry: lowercase key → ['display' => 'Original Name', 'ca' => 'Constitutional Area X']
$map = [];

foreach ($wsXml->xpath('//row') as $row) {
    $rowNum = (int)($row['r'] ?? 0);
    if ($rowNum <= 1) {
        continue; // skip header row
    }

    $cells = [];
    foreach ($row->xpath('c') as $c) {
        $ref = (string)($c['r'] ?? '');
        $col = cellColIndex($ref);
        $cells[$col] = cellValue($c, $sharedStrings);
    }

    $ca      = trim($cells[0] ?? ''); // Column A — Constitutional Area
    $country = trim($cells[2] ?? ''); // Column C — Country

    if ($ca !== '' && $country !== '') {
        $map[strtolower($country)] = ['display' => $country, 'ca' => $ca];
    }
}

if (empty($map)) {
    fwrite(STDERR, "Warning: No entries were found. Verify that:\n");
    fwrite(STDERR, "  - Column A contains the Constitutional Area name\n");
    fwrite(STDERR, "  - Column C contains the Country name\n");
    fwrite(STDERR, "  - Row 1 is the header (skipped automatically)\n");
}

// --- Write forms/ca-map.php ---
$lines   = [];
$lines[] = '<?php';
$lines[] = '/**';
$lines[] = ' * Country → Constitutional Area mapping.';
$lines[] = ' * Auto-generated by forms/parse-ca-xlsx.php — do not edit manually.';
$lines[] = ' * Re-run the parser if the source XLSX changes.';
$lines[] = ' * Keys are lowercased country names for case-insensitive lookup.';
$lines[] = ' * Each value: ["display" => original country name, "ca" => Constitutional Area name]';
$lines[] = ' */';
$lines[] = 'return [';
ksort($map);
foreach ($map as $key => $entry) {
    $lines[] = '    ' . var_export($key, true) . ' => ['
        . '"display" => ' . var_export($entry['display'], true) . ', '
        . '"ca" => ' . var_export($entry['ca'], true)
        . '],';
}
$lines[] = '];';

file_put_contents($outputPath, implode("\n", $lines) . "\n");

echo "Done. Wrote " . count($map) . " entries to {$outputPath}\n";
echo "Spot-check entries:\n";
$samples = array_slice($map, 0, 5, true);
foreach ($samples as $key => $entry) {
    echo "  {$entry['display']} → {$entry['ca']}\n";
}
