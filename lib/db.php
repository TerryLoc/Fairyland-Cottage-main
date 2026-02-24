<?php
// Simple SQLite DB helper for token storage
function get_db_path() {
    return __DIR__ . '/../data/tokens.sqlite';
}

function get_db() {
    $path = get_db_path();
    if (!file_exists(dirname($path))) mkdir(dirname($path), 0755, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Enable foreign keys
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

function init_db() {
    $pdo = get_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
        token TEXT PRIMARY KEY,
        email TEXT,
        expiry INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS token_files (
        token TEXT,
        filename TEXT,
        remaining INTEGER,
        PRIMARY KEY(token, filename),
        FOREIGN KEY(token) REFERENCES tokens(token) ON DELETE CASCADE
    )");
}

function insert_token_with_files($token, $email, $expiry, $files_map) {
    $pdo = get_db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO tokens (token,email,expiry) VALUES (:t,:e,:x)');
    $stmt->execute([':t'=>$token, ':e'=>$email, ':x'=>$expiry]);
    $ins = $pdo->prepare('INSERT OR REPLACE INTO token_files (token,filename,remaining) VALUES (:t,:f,:r)');
    foreach ($files_map as $filename => $count) {
        $ins->execute([':t'=>$token, ':f'=>$filename, ':r'=>max(0,(int)$count)]);
    }
    $pdo->commit();
}

function get_token_record($token) {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT token,email,expiry FROM tokens WHERE token = :t');
    $stmt->execute([':t'=>$token]);
    $token_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$token_row) return null;
    $files = [];
    $fstmt = $pdo->prepare('SELECT filename,remaining FROM token_files WHERE token = :t');
    $fstmt->execute([':t'=>$token]);
    while ($r = $fstmt->fetch(PDO::FETCH_ASSOC)) {
        $files[$r['filename']] = (int)$r['remaining'];
    }
    $token_row['files'] = $files;
    return $token_row;
}

function decrement_file_count($token, $filename) {
    $pdo = get_db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT remaining FROM token_files WHERE token = :t AND filename = :f');
    $stmt->execute([':t'=>$token, ':f'=>$filename]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $pdo->commit(); return false; }
    $rem = max(0, (int)$row['remaining'] - 1);
    if ($rem > 0) {
        $ust = $pdo->prepare('UPDATE token_files SET remaining = :r WHERE token = :t AND filename = :f');
        $ust->execute([':r'=>$rem, ':t'=>$token, ':f'=>$filename]);
    } else {
        $dst = $pdo->prepare('DELETE FROM token_files WHERE token = :t AND filename = :f');
        $dst->execute([':t'=>$token, ':f'=>$filename]);
        // delete token row if no files remain
        $chk = $pdo->prepare('SELECT COUNT(*) as c FROM token_files WHERE token = :t');
        $chk->execute([':t'=>$token]);
        $c = (int)$chk->fetch(PDO::FETCH_ASSOC)['c'];
        if ($c === 0) {
            $pdo->prepare('DELETE FROM tokens WHERE token = :t')->execute([':t'=>$token]);
        }
    }
    $pdo->commit();
    return true;
}
