<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

function back(string $msg = '', string $to = 'admin.php'): never
{
    if ($msg !== '') $_SESSION['flash'] = $msg;
    header('Location: ' . $to);
    exit;
}
function item_title(int $id): ?string
{
    $s = db()->prepare('SELECT title FROM items WHERE id = ?');
    $s->execute([$id]);
    $t = $s->fetchColumn();
    return $t === false ? null : (string)$t;
}

// ---------- Login / Logout ----------
if (($_POST['action'] ?? '') === 'login') {
    if (password_verify((string)($_POST['password'] ?? ''), ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        back();
    }
    sleep(2);
    $_SESSION['flash'] = 'Passwort falsch.';
    header('Location: admin.php');
    exit;
}

if (!is_admin()) {
    $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
    ?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow"><title>Admin – Login</title><link rel="stylesheet" href="assets/style.css?v=3"></head>
    <body><div class="wrap"><form method="post" class="panel login form-grid">
      <h1 style="font-size:1.3rem">Admin-Bereich</h1>
      <?php if ($flash): ?><p class="err"><?= h($flash) ?></p><?php endif; ?>
      <label for="pw">Passwort</label>
      <input type="password" id="pw" name="password" autofocus required autocomplete="current-password">
      <input type="hidden" name="action" value="login">
      <button class="btn btn-primary">Anmelden</button>
    </form></div></body></html><?php
    exit;
}

// ---------- Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a  = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['item_id'] ?? 0);

    switch ($a) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            header('Location: admin.php');
            exit;

        case 'save_item':
            $title = trim((string)($_POST['title'] ?? ''));
            $desc  = trim(str_replace("\r\n", "\n", (string)($_POST['description'] ?? '')));
            $sort  = (int)($_POST['sort'] ?? 0);
            if ($title === '') {
                if ($isAjax) json_out(['error' => 'Titel fehlt.'], 422);
                back('Titel fehlt.');
            }
            if ($id) {
                db()->prepare('UPDATE items SET title = ?, description = ?, sort = ? WHERE id = ?')->execute([$title, $desc, $sort, $id]);
            } else {
                if (!isset($_POST['sort']) || $_POST['sort'] === '') {
                    $sort = (int)db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM items')->fetchColumn();
                }
                db()->prepare('INSERT INTO items (title, description, sort, created_at) VALUES (?,?,?,?)')->execute([$title, $desc, $sort, now()]);
                $id = (int)db()->lastInsertId();
            }
            if ($isAjax) json_out(['ok' => true, 'id' => $id]);
            back('Gespeichert.');

        case 'upload_image':
            if (!item_title($id)) json_out(['error' => 'Artikel nicht gefunden.'], 404);
            $f = $_FILES['image'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'Upload fehlgeschlagen (Code ' . ($f['error'] ?? '?') . ').'], 400);
            try {
                $base = save_image($f['tmp_name']);
            } catch (Throwable $e) {
                json_out(['error' => $e->getMessage()], 422);
            }
            $s = db()->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM images WHERE item_id = ?');
            $s->execute([$id]);
            db()->prepare('INSERT INTO images (item_id, file, sort) VALUES (?,?,?)')->execute([$id, $base, (int)$s->fetchColumn()]);
            json_out(['ok' => true]);

        case 'delete_image':
            $s = db()->prepare('SELECT * FROM images WHERE id = ?');
            $s->execute([(int)$_POST['image_id']]);
            if ($img = $s->fetch()) {
                db()->prepare('DELETE FROM images WHERE id = ?')->execute([$img['id']]);
                delete_image_files($img['file']);
                back('Bild gelöscht.', 'admin.php?edit=' . $img['item_id']);
            }
            back();

        case 'cover_image':
            $s = db()->prepare('SELECT * FROM images WHERE id = ?');
            $s->execute([(int)$_POST['image_id']]);
            if ($img = $s->fetch()) {
                db()->prepare('UPDATE images SET sort = (SELECT MIN(sort) - 1 FROM images WHERE item_id = ?) WHERE id = ?')
                    ->execute([$img['item_id'], $img['id']]);
                back('Titelbild gesetzt.', 'admin.php?edit=' . $img['item_id']);
            }
            back();

        case 'delete_item':
            $s = db()->prepare('SELECT file FROM images WHERE item_id = ?');
            $s->execute([$id]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $file) delete_image_files($file);
            db()->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
            back('Artikel gelöscht.');

        case 'cancel_res':
            $s = db()->prepare('SELECT name FROM reservations WHERE item_id = ?');
            $s->execute([$id]);
            if (($name = $s->fetchColumn()) !== false) {
                db()->prepare('DELETE FROM reservations WHERE item_id = ?')->execute([$id]);
                add_log($id, item_title($id), 'vom Admin aufgehoben', (string)$name);
            }
            back('Reservierung aufgehoben.', 'admin.php#item-' . $id);

        case 'admin_reserve':
            $name = clean_name((string)($_POST['name'] ?? ''));
            if ($name === '') back('Name fehlt.', 'admin.php#item-' . $id);
            try {
                db()->prepare('INSERT INTO reservations (item_id, name, token_hash, by_admin, created_at) VALUES (?,?,?,1,?)')
                    ->execute([$id, $name, hash('sha256', bin2hex(random_bytes(16))), now()]);
                add_log($id, item_title($id), 'vom Admin reserviert', $name);
                back('Reserviert für ' . $name . '.', 'admin.php#item-' . $id);
            } catch (PDOException $e) {
                back('Artikel ist bereits reserviert.', 'admin.php#item-' . $id);
            }

        case 'set_status':
            $st = ($_POST['status'] ?? '') === 'gone' ? 'gone' : 'available';
            db()->prepare('UPDATE items SET status = ? WHERE id = ?')->execute([$st, $id]);
            add_log($id, item_title($id), $st === 'gone' ? 'als vergeben markiert' : 'wieder verfügbar');
            back('', 'admin.php#item-' . $id);

        case 'toggle_hidden':
            db()->prepare('UPDATE items SET hidden = 1 - hidden WHERE id = ?')->execute([$id]);
            back('', 'admin.php#item-' . $id);

        case 'purge_names':
            db()->exec('DELETE FROM reservations');
            db()->exec('DELETE FROM log');
            back('Alle Reservierungen und der Verlauf wurden gelöscht.');
    }
    back();
}

// ---------- Ansichten ----------
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$csrf  = csrf_token();
$items = load_items(true);
$edit  = null;
if (isset($_GET['new'])) {
    $edit = ['id' => 0, 'title' => '', 'description' => '', 'sort' => '', 'images' => []];
} elseif (isset($_GET['edit'])) {
    foreach ($items as $it) if ((int)$it['id'] === (int)$_GET['edit']) $edit = $it;
}
$fmt = fn(?string $d) => $d ? date('d.m. H:i', strtotime($d)) : '';
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin – <?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>
<div class="wrap">
  <div class="admin-top">
    <h1><a href="admin.php" style="color:inherit;text-decoration:none">Admin · Haushaltsauflösung</a></h1>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="./" target="_blank">Öffentliche Seite ↗</a>
      <a class="btn btn-primary btn-sm" href="admin.php?new">+ Neuer Artikel</a>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn btn-ghost btn-sm" name="action" value="logout">Abmelden</button></form>
    </div>
  </div>

  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

<?php if ($edit !== null): ?>
  <div class="panel">
    <h2 style="margin-bottom:14px"><?= $edit['id'] ? 'Artikel bearbeiten' : 'Neuer Artikel' ?></h2>
    <form id="item-form" class="form-grid" method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="save_item">
      <input type="hidden" name="item_id" value="<?= (int)$edit['id'] ?>">
      <label for="f-title">Titel</label>
      <input id="f-title" name="title" required maxlength="120" value="<?= h($edit['title']) ?>" placeholder="z. B. Esstisch Eiche, 160 × 90 cm">
      <label for="f-desc">Beschreibung</label>
      <textarea id="f-desc" name="description" rows="5" placeholder="Maße, Zustand, Besonderheiten …"><?= h($edit['description']) ?></textarea>
      <label for="f-sort">Reihenfolge (kleiner = weiter oben)</label>
      <input id="f-sort" name="sort" type="number" value="<?= h((string)$edit['sort']) ?>" placeholder="automatisch" style="max-width:160px">

      <?php if ($edit['images']): ?>
        <label>Bilder</label>
        <div class="thumbs">
          <?php foreach ($edit['images'] as $n => $img): ?>
            <div class="thumb">
              <img src="<?= h(img_url($img['file'], 'm')) ?>" alt="">
              <?php if ($n === 0): ?><span class="cover">Titelbild</span>
              <?php else: ?><button class="mk" form="cover-<?= (int)$img['id'] ?>">als Titelbild</button><?php endif; ?>
              <button class="x" form="del-<?= (int)$img['id'] ?>" title="Bild löschen" onclick="return confirm('Bild löschen?')">×</button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <label for="f-files">Bilder hinzufügen</label>
      <div class="drop">
        <input id="f-files" type="file" accept="image/*" multiple>
        <p class="hint" style="margin-top:8px">Mehrere Fotos auf einmal möglich. Sie werden vor dem Hochladen verkleinert; Standortdaten werden entfernt.</p>
      </div>
      <p class="progress" id="progress" hidden></p>
      <p class="err" id="form-err" hidden></p>

      <div class="row" style="justify-content:flex-start">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="admin.php">Zurück zur Übersicht</a>
      </div>
    </form>
    <?php foreach ($edit['images'] as $img): ?>
      <form id="del-<?= (int)$img['id'] ?>" method="post" hidden><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>"></form>
      <form id="cover-<?= (int)$img['id'] ?>" method="post" hidden><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="cover_image"><input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>"></form>
    <?php endforeach; ?>
  </div>

  <script>
  (() => {
    const form = document.getElementById('item-form');
    const files = document.getElementById('f-files');
    const progress = document.getElementById('progress');
    const errEl = document.getElementById('form-err');
    const csrf = form.csrf.value;

    function resize(file, max = 2000) {
      return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
          const s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
          const c = document.createElement('canvas');
          c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
          const ctx = c.getContext('2d');
          ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
          ctx.drawImage(img, 0, 0, c.width, c.height);
          URL.revokeObjectURL(url);
          c.toBlob(b => b ? resolve(b) : reject(new Error('Bild konnte nicht verarbeitet werden.')), 'image/jpeg', 0.88);
        };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error(`„${file.name}“ kann der Browser nicht lesen (z. B. HEIC). Bitte als JPG exportieren.`)); };
        img.src = url;
      });
    }

    async function post(fd) {
      const r = await fetch('admin.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch', 'X-CSRF': csrf } });
      let d = {}; try { d = await r.json(); } catch {}
      if (!r.ok) throw new Error(d.error || `Fehler ${r.status}`);
      return d;
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      errEl.hidden = true;
      const btn = form.querySelector('[type=submit]');
      btn.disabled = true;
      try {
        const res = await post(new FormData(form));
        const list = [...files.files];
        const failed = [];
        for (let i = 0; i < list.length; i++) {
          progress.hidden = false;
          progress.textContent = `Bild ${i + 1} von ${list.length} wird hochgeladen …`;
          try {
            const fd = new FormData();
            fd.append('action', 'upload_image');
            fd.append('item_id', res.id);
            fd.append('image', await resize(list[i]), 'bild.jpg');
            await post(fd);
          } catch (ex) { failed.push(ex.message); }
        }
        if (failed.length) {
          errEl.textContent = failed.join(' · ');
          errEl.hidden = false;
          setTimeout(() => location.href = 'admin.php?edit=' + res.id, 4000);
        } else {
          location.href = list.length ? 'admin.php?edit=' + res.id : 'admin.php#item-' + res.id;
        }
      } catch (ex) {
        errEl.textContent = ex.message; errEl.hidden = false;
      } finally { btn.disabled = false; }
    });
  })();
  </script>

<?php else:
    $cnt = ['free' => 0, 'reserved' => 0, 'gone' => 0];
    foreach ($items as $it) $cnt[$it['state']]++;
?>
  <div class="panel stats">
    <div><b><?= count($items) ?></b>Artikel</div>
    <div><b><?= $cnt['free'] ?></b>frei</div>
    <div><b><?= $cnt['reserved'] ?></b>reserviert</div>
    <div><b><?= $cnt['gone'] ?></b>vergeben</div>
  </div>

  <?php if (!$items): ?>
    <p class="empty">Noch keine Artikel. <a href="admin.php?new">Ersten Artikel anlegen</a></p>
  <?php endif; ?>

  <div class="alist">
  <?php foreach ($items as $it): ?>
    <div class="arow <?= $it['state'] === 'gone' ? 'gone' : '' ?> <?= $it['hidden'] ? 'hidden-item' : '' ?>" id="item-<?= (int)$it['id'] ?>">
      <?php if ($it['images']): ?><img src="<?= h(img_url($it['images'][0]['file'], 'm')) ?>" alt=""><?php else: ?><div class="noimg"></div><?php endif; ?>
      <div>
        <h3><?= h($it['title']) ?><?= $it['hidden'] ? ' <span class="meta">(ausgeblendet)</span>' : '' ?></h3>
        <div class="meta">
          <?= count($it['images']) ?> Bild(er) ·
          <?php if ($it['state'] === 'gone'): ?>vergeben<?php elseif ($it['res_name'] !== null): ?>
            <span class="res">reserviert: <?= h($it['res_name']) ?></span> · <?= h($fmt($it['res_at'])) ?><?= $it['res_by_admin'] ? ' (von dir)' : '' ?>
          <?php else: ?>frei<?php endif; ?>
        </div>
        <?php if ($it['state'] === 'free'): ?>
          <form method="post" class="inline-res">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="admin_reserve"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <input name="name" placeholder="Reservieren für …" maxlength="60" required>
            <button class="btn btn-ghost btn-sm">OK</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="btns">
        <?php if ($it['res_name'] !== null): ?>
          <form method="post" onsubmit="return confirm('Reservierung von <?= h(addslashes($it['res_name'])) ?> aufheben?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
            <button class="btn btn-ghost btn-sm" name="action" value="cancel_res">Reservierung aufheben</button>
          </form>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="action" value="set_status">
          <?php if ($it['status'] === 'gone'): ?>
            <button class="btn btn-ghost btn-sm" name="status" value="available">Wieder verfügbar</button>
          <?php else: ?>
            <button class="btn btn-ghost btn-sm" name="status" value="gone">Vergeben ✓</button>
          <?php endif; ?>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <button class="btn btn-ghost btn-sm" name="action" value="toggle_hidden"><?= $it['hidden'] ? 'Einblenden' : 'Ausblenden' ?></button>
        </form>
        <a class="btn btn-ghost btn-sm" href="admin.php?edit=<?= (int)$it['id'] ?>">Bearbeiten</a>
        <form method="post" onsubmit="return confirm('Artikel samt Bildern endgültig löschen?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
          <button class="btn btn-danger btn-sm" name="action" value="delete_item">Löschen</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>

  <?php $log = db()->query('SELECT * FROM log ORDER BY id DESC LIMIT 100')->fetchAll(); ?>
  <div class="panel" style="margin-top:28px">
    <h2 style="font-size:1.1rem;margin-bottom:10px">Verlauf</h2>
    <?php if (!$log): ?><p class="hint">Noch keine Aktivität.</p><?php else: ?>
    <table class="log">
      <tr><th>Zeit</th><th>Artikel</th><th>Aktion</th><th>Name</th></tr>
      <?php foreach ($log as $l): ?>
        <tr><td><?= h($fmt($l['at'])) ?></td><td><?= h($l['title']) ?></td><td><?= h($l['action']) ?></td><td><?= h($l['name']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    <form method="post" style="margin-top:16px" onsubmit="return confirm('Alle Reservierungen und den gesamten Verlauf löschen? Das ist für nach der Abholaktion gedacht.')">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button class="btn btn-ghost btn-sm" name="action" value="purge_names">Nach der Aktion: alle Namen & Verlauf löschen</button>
    </form>
  </div>
<?php endif; ?>
</div>
</body>
</html>
