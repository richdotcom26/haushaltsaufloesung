<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Nur POST erlaubt.'], 405);

$in     = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($in['action'] ?? '');
$itemId = (int)($in['item_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM items WHERE id = ? AND hidden = 0');
$stmt->execute([$itemId]);
$item = $stmt->fetch();
if (!$item) json_out(['error' => 'Artikel nicht gefunden.'], 404);

if ($action === 'reserve') {
    if (!empty($in['website'])) json_out(['error' => 'Ungültige Anfrage.'], 400); // Honeypot

    $name = clean_name((string)($in['name'] ?? ''));
    $len  = mb_strlen($name);
    if ($len < 2 || $len > 60) json_out(['error' => 'Bitte einen Namen angeben (2–60 Zeichen).'], 422);

    $c = db()->prepare("SELECT COUNT(*) FROM log WHERE action = 'reserviert' AND ip_hash = ? AND at > ?");
    $c->execute([ip_hash(), date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$c->fetchColumn() >= MAX_RESERVATIONS_PER_HOUR) {
        json_out(['error' => 'Zu viele Reservierungen in kurzer Zeit. Bitte per WhatsApp melden.'], 429);
    }

    if ($item['status'] === 'gone') json_out(['error' => 'Dieser Artikel ist leider schon vergeben.'], 409);

    $token = bin2hex(random_bytes(16));
    try {
        db()->prepare('INSERT INTO reservations (item_id, name, token_hash, created_at) VALUES (?,?,?,?)')
            ->execute([$itemId, $name, hash('sha256', $token), now()]);
    } catch (PDOException $e) {
        json_out(['error' => 'Dieser Artikel wurde gerade schon reserviert.'], 409);
    }
    add_log($itemId, $item['title'], 'reserviert', $name);
    json_out(['ok' => true, 'token' => $token]);
}

if ($action === 'cancel') {
    $token = (string)($in['token'] ?? '');
    $r = db()->prepare('SELECT name FROM reservations WHERE item_id = ? AND token_hash = ?');
    $r->execute([$itemId, hash('sha256', $token)]);
    $res = $r->fetch();
    if (!$res) json_out(['error' => 'Reservierung nicht gefunden – vielleicht schon aufgehoben.'], 404);
    db()->prepare('DELETE FROM reservations WHERE item_id = ?')->execute([$itemId]);
    add_log($itemId, $item['title'], 'zurückgenommen', $res['name']);
    json_out(['ok' => true]);
}

json_out(['error' => 'Unbekannte Aktion.'], 400);
