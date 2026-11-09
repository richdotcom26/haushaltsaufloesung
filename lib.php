<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

const DATA_DIR   = __DIR__ . '/data';
const UPLOAD_DIR = __DIR__ . '/uploads';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $pdo = new PDO('sqlite:' . DATA_DIR . '/app.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            sort        INTEGER NOT NULL DEFAULT 0,
            status      TEXT NOT NULL DEFAULT 'available',  -- available | gone
            hidden      INTEGER NOT NULL DEFAULT 0,
            created_at  TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS images (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
            file    TEXT NOT NULL,
            sort    INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS reservations (
            item_id    INTEGER PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
            name       TEXT NOT NULL,
            token_hash TEXT NOT NULL,
            by_admin   INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS log (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER,
            title   TEXT,
            action  TEXT NOT NULL,
            name    TEXT,
            ip_hash TEXT,
            at      TEXT NOT NULL
        );
    ");
    return $pdo;
}

function now(): string { return date('Y-m-d H:i:s'); }

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ip_hash(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ADMIN_PASSWORD_HASH);
}

function add_log(?int $itemId, ?string $title, string $action, ?string $name = null): void
{
    db()->prepare('INSERT INTO log (item_id, title, action, name, ip_hash, at) VALUES (?,?,?,?,?,?)')
        ->execute([$itemId, $title, $action, $name, ip_hash(), now()]);
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_name(string $s): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

function wa_link(string $text = ''): string
{
    $url = 'https://wa.me/' . preg_replace('/\D/', '', WHATSAPP_NUMBER);
    return $text === '' ? $url : $url . '?text=' . rawurlencode($text);
}

/** Artikel inkl. Bilder und Reservierung laden. */
function load_items(bool $admin = false): array
{
    $where = $admin ? '' : 'WHERE i.hidden = 0';
    $items = db()->query("
        SELECT i.*, r.name AS res_name, r.created_at AS res_at, r.by_admin AS res_by_admin
        FROM items i LEFT JOIN reservations r ON r.item_id = i.id
        $where ORDER BY i.sort, i.id
    ")->fetchAll();
    $byItem = [];
    foreach (db()->query('SELECT * FROM images ORDER BY sort, id') as $img) {
        $byItem[$img['item_id']][] = $img;
    }
    foreach ($items as &$it) {
        $it['images'] = $byItem[$it['id']] ?? [];
        $it['state']  = $it['status'] === 'gone' ? 'gone' : ($it['res_name'] !== null ? 'reserved' : 'free');
        if (!$admin) unset($it['res_name'], $it['res_at'], $it['res_by_admin']); // Namen niemals öffentlich
    }
    return $items;
}

function img_url(string $base, string $size = 'm'): string
{
    return 'uploads/' . rawurlencode($base) . '_' . $size . '.jpg';
}

/**
 * Bild speichern: neu kodieren (entfernt EXIF/GPS), Ausrichtung korrigieren,
 * zwei Größen erzeugen. Gibt den Basisnamen zurück.
 */
function save_image(string $tmp): string
{
    $info = @getimagesize($tmp);
    if (!$info) throw new RuntimeException('Keine gültige Bilddatei.');
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
        default        => false,
    };
    if (!$src) throw new RuntimeException('Bildformat nicht unterstützt (JPG, PNG oder WebP).');

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)((@exif_read_data($tmp))['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) $src = imagerotate($src, $angle, 0);
    }

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    $base = bin2hex(random_bytes(8));
    $w = imagesx($src); $hgt = imagesy($src);
    foreach (['l' => 1800, 'm' => 800] as $suffix => $max) {
        $scale = min(1, $max / max($w, $hgt));
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($hgt * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hgt);
        imagejpeg($dst, UPLOAD_DIR . "/{$base}_{$suffix}.jpg", $suffix === 'l' ? 84 : 80);
        imagedestroy($dst);
    }
    imagedestroy($src);
    return $base;
}

function delete_image_files(string $base): void
{
    foreach (['l', 'm'] as $s) @unlink(UPLOAD_DIR . "/{$base}_{$s}.jpg");
}

// ---------- Session / Admin ----------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('hha_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
    session_start();
}

function is_admin(): bool { start_session(); return !empty($_SESSION['admin']); }

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(403);
        exit('Sitzung abgelaufen – bitte Seite neu laden.');
    }
}
