<?php
require __DIR__ . '/../lib/db.php';
// Recreate DB for a clean import
$dbpath = __DIR__ . '/../data/tokens.sqlite';
if (file_exists($dbpath)) unlink($dbpath);
init_db();

$lines = @file(__DIR__ . '/../tokens.txt', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if (!$lines) {
    echo "No tokens.txt lines to import\n";
    exit(0);
}
foreach ($lines as $l) {
    $parts = explode('|', $l, 4);
    if (count($parts) < 4) continue;
    list($t,$email,$expiry,$files_field) = $parts;
    $files_field = trim($files_field);
    $map = [];
    if ((strpos($files_field,'[')===0) || (strpos($files_field,'{')===0)) {
        $dec = json_decode($files_field, true);
        if (is_array($dec)) {
            if (array_values($dec) === $dec) {
                foreach ($dec as $n) { $n = preg_replace('#^private_downloads/+#','',$n); $map[basename($n)] = 1; }
            } else {
                foreach ($dec as $n => $c) { $n = preg_replace('#^private_downloads/+#','',$n); $map[basename($n)] = (int)$c; }
            }
        }
    } else {
        // More robust parser: split on commas but try to combine parts until a matching file exists
        $parts_f = array_map('trim', explode(',', $files_field));
        $parts_f = array_filter($parts_f, function($p){ return $p !== ''; });
        $i = 0; $n = count($parts_f);
        while ($i < $n) {
            $part = $parts_f[$i];
            // If part contains a colon, assume it's filename:count or part thereof. We'll try to parse using last colon.
            if (strpos($part, ':') !== false) {
                // Try greedy combination until we find a filename that exists when stripped
                $j = $i;
                $combined = $part;
                $found = false;
                while ($j < $n) {
                    // split on last colon to separate count
                    $pos = strrpos($combined, ':');
                    if ($pos !== false) {
                        $fname = substr($combined, 0, $pos);
                        $cnt = substr($combined, $pos + 1);
                        $fname_clean = preg_replace('#^private_downloads/+#','',$fname);
                        $try_path = __DIR__ . '/../private_downloads/' . $fname_clean;
                        if (file_exists($try_path)) {
                            $map[basename($fname_clean)] = max(0, (int)$cnt);
                            $found = true;
                            break;
                        }
                    }
                    $j++;
                    if ($j < $n) $combined .= ',' . $parts_f[$j];
                }
                if ($found) {
                    $i = $j + 1; continue;
                }
                // fallback: treat whole files_field as single filename
                $map[basename(preg_replace('#^private_downloads/+#','',$files_field))] = 1;
                break;
            } else {
                // No colon: try to combine subsequent parts until a matching file exists
                $j = $i; $combined = $part; $found = false;
                while ($j < $n) {
                    $fname_clean = preg_replace('#^private_downloads/+#','',$combined);
                    $try_path = __DIR__ . '/../private_downloads/' . $fname_clean;
                    if (file_exists($try_path)) {
                        $map[basename($fname_clean)] = 1;
                        $found = true; break;
                    }
                    $j++;
                    if ($j < $n) $combined .= ',' . $parts_f[$j];
                }
                if ($found) { $i = $j + 1; continue; }
                // fallback: use entire files_field as filename
                $map[basename(preg_replace('#^private_downloads/+#','',$files_field))] = 1;
                break;
            }
        }
    }
    insert_token_with_files($t, $email, (int)$expiry, $map);
    echo "imported $t -> " . json_encode($map) . "\n";
}
echo "done\n";
