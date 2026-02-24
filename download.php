<?php
// File: download.php
// Purpose: Validate token and redirect to Google Drive links

$token = isset($_GET['token']) ? $_GET['token'] : '';
$valid = false;
$buyer_email = '';
// allowed_files_map will be an associative array: basename => remaining_count
$allowed_files_map = [];
$allowed_files = [];
// If SQLite helper exists, use it; otherwise fallback to tokens.txt
$use_db = file_exists(__DIR__ . '/lib/db.php');
if ($use_db) {
    require_once __DIR__ . '/lib/db.php';
    init_db();
    $record = get_token_record($token);
    if ($record && time() < (int)$record['expiry']) {
        $valid = true;
        $buyer_email = $record['email'];
        foreach ($record['files'] as $fname => $cnt) {
            $allowed_files_map[basename($fname)] = (int)$cnt;
        }
    }
} else {
    $tokens_file = __DIR__ . '/tokens.txt';
    $tokens = file_exists($tokens_file) ? file($tokens_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
}

if (!$use_db) {
    foreach ($tokens as $line) {
        $parts = explode('|', $line);
        if (count($parts) < 4) continue;
        list($stored_token, $email, $expiry, $files_field) = $parts;
        if ($stored_token === $token && time() < (int)$expiry) {
            $valid = true;
            $buyer_email = $email;
            // Parse files_field robustly.
            $files_field = trim($files_field);
            // Parse files_field into an associative map of basename => count
            $allowed_files_map = [];
            $files_field = trim($files_field);
            // 1) If JSON, try to decode. Accept either list of names or map name=>count
            if ((strpos($files_field, '[') === 0) || (strpos($files_field, '{') === 0)) {
                $decoded = json_decode($files_field, true);
                if (is_array($decoded)) {
                    // If decoded is an indexed array of names
                    if (array_values($decoded) === $decoded) {
                        foreach ($decoded as $name) {
                            $name = preg_replace('#^private_downloads/+#', '', $name);
                            $allowed_files_map[basename($name)] = 1;
                        }
                    } else {
                        // associative: name => count
                        foreach ($decoded as $name => $cnt) {
                            $name = preg_replace('#^private_downloads/+#', '', $name);
                            $allowed_files_map[basename($name)] = (int)$cnt;
                        }
                    }
                }
            }
            // 2) If not JSON or blank, handle legacy comma-separated or single filename formats.
            if (empty($allowed_files_map)) {
                // Attempt to treat whole field as single filename first
                $maybe_basename = preg_replace('#^private_downloads/+#', '', $files_field);
                $maybe_path = __DIR__ . DIRECTORY_SEPARATOR . 'private_downloads' . DIRECTORY_SEPARATOR . $maybe_basename;
                if ($maybe_basename !== '' && file_exists($maybe_path)) {
                    $allowed_files_map[basename($maybe_basename)] = 1;
                } else {
                    // Split on commas for multiple entries. Each entry may be "filename:count" or just "filename"
                    $parts = array_map('trim', explode(',', $files_field));
                    $parts = array_filter($parts, function($p){ return $p !== ''; });
                    foreach ($parts as $p) {
                        $p = preg_replace('#^private_downloads/+#', '', $p);
                        if (strpos($p, ':') !== false) {
                            list($n, $c) = array_map('trim', explode(':', $p, 2));
                            $allowed_files_map[basename($n)] = max(0, (int)$c);
                        } else {
                            $allowed_files_map[basename($p)] = 1;
                        }
                    }
                }
            }
            // expose a simple list form for the landing page and diagnostics
            $allowed_files = array_keys($allowed_files_map);
            break;
        }
    }
}

if ($valid && empty($allowed_files)) {
    $allowed_files = array_keys($allowed_files_map);
}

// If a file parameter is present and token is valid, stream the file directly
if ($valid && isset($_GET['file'])) {
    $requested = basename($_GET['file']);
    $allowed_basenames = array_keys($allowed_files_map);
    if (!in_array($requested, $allowed_basenames, true)) {
        http_response_code(403);
        exit('Unauthorized file');
    }
    $file_path = __DIR__ . DIRECTORY_SEPARATOR . 'private_downloads' . DIRECTORY_SEPARATOR . $requested;
    if (!file_exists($file_path)) {
        http_response_code(404);
        exit('File not found');
    }
    // Update token storage: decrement count in DB or fallback file
    if ($use_db) {
        decrement_file_count($token, $requested);
    } else {
        $new_lines = [];
        foreach ($tokens as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (strpos($line, $token . '|') !== 0) {
                $new_lines[] = $line;
                continue;
            }
            // This is the matching token line. Parse and remove the requested file.
            $parts = explode('|', $line, 4);
            if (count($parts) < 4) continue; // malformed, skip
            list($stored_token, $email, $expiry, $files_field) = $parts;
            // parse files_field into associative map name=>count
            $current_map = [];
            $files_field = trim($files_field);
            if ((strpos($files_field, '[') === 0) || (strpos($files_field, '{') === 0)) {
                $decoded = json_decode($files_field, true);
                if (is_array($decoded)) {
                    if (array_values($decoded) === $decoded) {
                        foreach ($decoded as $n) {
                            $n = preg_replace('#^private_downloads/+#', '', $n);
                            $current_map[basename($n)] = 1;
                        }
                    } else {
                        foreach ($decoded as $n => $c) {
                            $n = preg_replace('#^private_downloads/+#', '', $n);
                            $current_map[basename($n)] = (int)$c;
                        }
                    }
                }
            }
            if (empty($current_map)) {
                $maybe_basename = preg_replace('#^private_downloads/+#', '', $files_field);
                $maybe_path = __DIR__ . DIRECTORY_SEPARATOR . 'private_downloads' . DIRECTORY_SEPARATOR . $maybe_basename;
                if ($maybe_basename !== '' && file_exists($maybe_path)) {
                    $current_map[basename($maybe_basename)] = 1;
                } else {
                    $parts_f = array_map('trim', explode(',', $files_field));
                    $parts_f = array_filter($parts_f, function($p){ return $p !== ''; });
                    foreach ($parts_f as $p) {
                        $p = preg_replace('#^private_downloads/+#', '', $p);
                        if (strpos($p, ':') !== false) {
                            list($n, $c) = array_map('trim', explode(':', $p, 2));
                            $current_map[basename($n)] = max(0, (int)$c);
                        } else {
                            $current_map[basename($p)] = 1;
                        }
                    }
                }
            }
            // Decrement the requested file's count
            if (isset($current_map[$requested])) {
                $current_map[$requested] = max(0, $current_map[$requested] - 1);
            }
            // Rebuild token line if any files remain with count > 0
            $remaining_pairs = [];
            foreach ($current_map as $name => $count) {
                if ($count > 0) $remaining_pairs[] = $name . ':' . $count;
            }
            if (count($remaining_pairs) > 0) {
                $new_files_field = implode(',', $remaining_pairs);
                $new_lines[] = $stored_token . '|' . $email . '|' . $expiry . '|' . $new_files_field;
            } else {
                // no remaining files: token is consumed (do not add back)
            }
        }
        file_put_contents(__DIR__ . '/tokens.txt', implode("\n", $new_lines) . (count($new_lines) ? "\n" : ""), LOCK_EX);
    }

    // Determine MIME type where possible
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $det = finfo_file($finfo, $file_path);
            if ($det) $mime = $det;
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type')) {
        $det = mime_content_type($file_path);
        if ($det) $mime = $det;
    }

    // Force download (send headers before any output)
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Transfer-Encoding: binary');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . filesize($file_path));

    // Clear (turn off) output buffering to avoid partial files
    while (ob_get_level()) { ob_end_clean(); }

    // Stream file
    readfile($file_path);
    exit;
}

if ($valid) {
    // serve files from private_downloads directory
    $private_dir = __DIR__ . DIRECTORY_SEPARATOR . 'private_downloads';

    // Simple download landing page listing allowed files with secure links
    $links_html = '';
    foreach ($allowed_files as $fname) {
        $safe = basename($fname); // prevent directory traversal
        $file_path = $private_dir . DIRECTORY_SEPARATOR . $safe;
        if (file_exists($file_path)) {
            // link to same script with download parameter to stream file
            $links_html .= "<a href='download.php?token=" . urlencode($token) . "&file=" . urlencode($safe) . "' class='btn btn-primary download-btn' download>$safe</a>\n";
        }
    }

    // Optional developer diagnostics (enabled with ?dev=1)
    $diag_html = '';
    if (isset($_GET['dev']) && $_GET['dev'] == '1') {
        $diag_lines = [];
        foreach ($allowed_files as $fname) {
            $safe = basename($fname);
            $path = $private_dir . DIRECTORY_SEPARATOR . $safe;
            $diag_lines[] = "$safe -> $path -> " . (file_exists($path) ? 'exists' : 'missing');
        }
        $diag_html = '<pre style="text-align:left; margin:20px auto; max-width:900px;">' . implode("\n", $diag_lines) . '</pre>';
    }

    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Download Your Files</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>
            body { padding: 20px; text-align: center; }
            .download-btn { margin: 10px; }
        </style>
    </head>
    <body>
        <h2>Fairyland Cottage Downloads</h2>
        <p>Thank you for your purchase! Click below to download your files.</p>
    <div>$links_html</div>
    $diag_html
        <p>This link expires in 24 hours. Contact <a href='mailto:info@fairylandcottage.com'>support</a> if you need help.</p>
    </body>
    </html>";
} else {
    http_response_code(403);
    echo "<h2>Invalid or expired link.</h2><p>Please contact <a href='mailto:info@fairylandcottage.com'>info@fairylandcottage.com</a>.</p>";
}
?>