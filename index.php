<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$items = load_items(false);
$free  = count(array_filter($items, fn($i) => $i['state'] === 'free'));
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h(SITE_TITLE) ?></title>
<link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>

<header class="hero">
  <div class="wrap">
    <p class="eyebrow">Zu verschenken</p>
    <h1><?= h(SITE_TITLE) ?></h1>
    <p class="badge"><?= h(EVENT_BADGE) ?></p>
    <div class="intro"><?= INTRO_HTML ?></div>
    <a class="btn btn-wa" href="<?= h(wa_link('Hallo ' . OWNER_NAME . ', ich interessiere mich für die Haushaltsauflösung.')) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.8-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>
      Nachricht per WhatsApp
    </a>
  </div>
</header>

<main class="wrap">
  <?php if (!$items): ?>
    <p class="empty">Die Fotos folgen in Kürze – schauen Sie bald wieder vorbei.</p>
  <?php else: ?>
    <div class="toolbar">
      <span><?= count($items) ?> Artikel · <span id="free-count"><?= $free ?></span> noch frei</span>
      <label class="toggle"><input type="checkbox" id="only-free"> nur freie anzeigen</label>
    </div>
    <p class="howto">Artikel reservieren, dann gern kurz per WhatsApp melden – und am Samstag abholen.</p>

    <div class="grid">
    <?php foreach ($items as $it): ?>
      <article class="card" data-id="<?= (int)$it['id'] ?>" data-state="<?= h($it['state']) ?>" data-title="<?= h($it['title']) ?>">
        <div class="gallery">
          <?php if ($it['images']): ?>
            <div class="slides">
              <?php foreach ($it['images'] as $n => $img): ?>
                <img src="<?= h(img_url($img['file'], 'm')) ?>" data-large="<?= h(img_url($img['file'], 'l')) ?>"
                     alt="<?= h($it['title']) ?> – Bild <?= $n + 1 ?>" loading="lazy">
              <?php endforeach; ?>
            </div>
            <?php if (count($it['images']) > 1): ?>
              <button class="nav prev" type="button" aria-label="Vorheriges Bild">‹</button>
              <button class="nav next" type="button" aria-label="Nächstes Bild">›</button>
              <span class="counter">1 / <?= count($it['images']) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <div class="noimg">Kein Foto</div>
          <?php endif; ?>
          <span class="flag"></span>
        </div>
        <div class="body">
          <h2><?= h($it['title']) ?></h2>
          <?php if (trim($it['description']) !== ''): ?>
            <p class="desc"><?= nl2br(h($it['description'])) ?></p>
          <?php endif; ?>
          <div class="actions"></div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer class="wrap foot">
  <p>Ihr Name wird nur für die Reservierung gespeichert, ist nur für <?= h(OWNER_NAME) ?> sichtbar und wird nach der Abholaktion gelöscht.</p>
</footer>

<dialog id="dlg-reserve">
  <form method="dialog" id="form-reserve">
    <h3>Reservieren: <span class="dlg-title"></span></h3>
    <label for="res-name">Ihr Name</label>
    <input id="res-name" name="name" required minlength="2" maxlength="60" autocomplete="name" placeholder="Vor- und Nachname">
    <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <p class="hint">Den Namen sieht nur <?= h(OWNER_NAME) ?>. Auf diesem Gerät können Sie die Reservierung jederzeit wieder zurücknehmen.</p>
    <p class="err" hidden></p>
    <div class="row">
      <button type="button" class="btn btn-ghost" data-close>Abbrechen</button>
      <button type="submit" class="btn btn-primary">Reservieren</button>
    </div>
  </form>
</dialog>

<dialog id="lightbox" class="lightbox">
  <img alt="">
  <button type="button" class="lb-close" aria-label="Schließen">×</button>
  <button type="button" class="lb-nav lb-prev" aria-label="Vorheriges Bild">‹</button>
  <button type="button" class="lb-nav lb-next" aria-label="Nächstes Bild">›</button>
</dialog>

<div id="toast" role="status" aria-live="polite"></div>

<script>
window.APP = { waBase: <?= json_encode(wa_link()) ?>, owner: <?= json_encode(OWNER_NAME) ?> };
</script>
<script src="assets/app.js?v=3"></script>
</body>
</html>
