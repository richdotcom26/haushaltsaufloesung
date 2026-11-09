(() => {
  const KEY = 'hha_tokens';
  const NAME_KEY = 'hha_name';
  const store = {
    get() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch { return {}; } },
    set(v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch {} },
  };

  const toastEl = document.getElementById('toast');
  let toastTimer;
  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 3500);
  }

  async function api(payload) {
    const r = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    let data = {};
    try { data = await r.json(); } catch {}
    if (!r.ok) throw Object.assign(new Error(data.error || 'Es ist ein Fehler aufgetreten.'), { status: r.status });
    return data;
  }

  function waLink(title) {
    const text = `Hallo ${APP.owner}, ich habe „${title}“ reserviert und würde es gern abholen.`;
    return `${APP.waBase}?text=${encodeURIComponent(text)}`;
  }

  // ---------- Karten rendern ----------
  const cards = [...document.querySelectorAll('.card')];
  const tokens = store.get();

  function render(card) {
    const id = card.dataset.id;
    const state = card.dataset.state;
    const mine = state === 'reserved' && tokens[id];
    const actions = card.querySelector('.actions');
    const flag = card.querySelector('.flag');
    card.classList.toggle('mine', !!mine);

    if (state === 'gone') {
      flag.textContent = 'Vergeben';
      actions.innerHTML = '<span class="status">Schon vergeben</span>';
    } else if (mine) {
      flag.textContent = 'Von Ihnen reserviert';
      actions.innerHTML = `
        <a class="btn btn-wa btn-sm" target="_blank" rel="noopener" href="${waLink(card.dataset.title)}">Per WhatsApp melden</a>
        <button type="button" class="btn btn-ghost btn-sm" data-act="cancel">Reservierung zurücknehmen</button>`;
    } else if (state === 'reserved') {
      flag.textContent = 'Reserviert';
      actions.innerHTML = '<span class="status">Bereits reserviert</span>';
    } else {
      flag.textContent = '';
      actions.innerHTML = '<button type="button" class="btn btn-primary" data-act="reserve">Reservieren</button>';
    }
  }

  // Tokens aufräumen, deren Reservierung nicht mehr existiert (z. B. vom Admin aufgehoben)
  for (const id of Object.keys(tokens)) {
    const card = cards.find(c => c.dataset.id === id);
    if (!card || card.dataset.state !== 'reserved') delete tokens[id];
  }
  store.set(tokens);
  cards.forEach(render);

  function updateFreeCount() {
    const el = document.getElementById('free-count');
    if (el) el.textContent = cards.filter(c => c.dataset.state === 'free').length;
  }

  // ---------- Reservieren ----------
  const dlg = document.getElementById('dlg-reserve');
  const form = document.getElementById('form-reserve');
  const err = form.querySelector('.err');
  let current = null;

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const card = btn.closest('.card');

    if (btn.dataset.act === 'reserve') {
      current = card;
      dlg.querySelector('.dlg-title').textContent = card.dataset.title;
      err.hidden = true;
      form.name.value = localStorage.getItem(NAME_KEY) || '';
      dlg.showModal();
      form.name.focus();
    }

    if (btn.dataset.act === 'cancel') {
      if (!confirm(`Reservierung für „${card.dataset.title}“ zurücknehmen?`)) return;
      btn.disabled = true;
      try {
        await api({ action: 'cancel', item_id: +card.dataset.id, token: tokens[card.dataset.id] });
        toast('Reservierung zurückgenommen.');
      } catch (ex) {
        toast(ex.message);
      }
      delete tokens[card.dataset.id];
      store.set(tokens);
      card.dataset.state = 'free';
      render(card);
      updateFreeCount();
    }
  });

  dlg.querySelector('[data-close]').addEventListener('click', () => dlg.close());

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = form.name.value.trim();
    if (name.length < 2) { err.textContent = 'Bitte einen Namen angeben.'; err.hidden = false; return; }
    const submit = form.querySelector('[type=submit]');
    submit.disabled = true;
    try {
      const res = await api({ action: 'reserve', item_id: +current.dataset.id, name, website: form.website.value });
      tokens[current.dataset.id] = res.token;
      store.set(tokens);
      try { localStorage.setItem(NAME_KEY, name); } catch {}
      current.dataset.state = 'reserved';
      render(current);
      updateFreeCount();
      dlg.close();
      toast('Reserviert! Melden Sie sich gern kurz per WhatsApp.');
    } catch (ex) {
      err.textContent = ex.message;
      err.hidden = false;
      if (ex.status === 409) { current.dataset.state = 'reserved'; render(current); updateFreeCount(); }
    } finally {
      submit.disabled = false;
    }
  });

  // ---------- Filter ----------
  const onlyFree = document.getElementById('only-free');
  onlyFree?.addEventListener('change', () => {
    document.body.classList.toggle('only-free', onlyFree.checked);
  });

  // ---------- Galerie ----------
  document.querySelectorAll('.gallery').forEach(g => {
    const slides = g.querySelector('.slides');
    if (!slides) return;
    const imgs = [...slides.querySelectorAll('img')];
    const counter = g.querySelector('.counter');
    const idx = () => Math.round(slides.scrollLeft / slides.clientWidth);
    const go = (i) => slides.scrollTo({ left: Math.max(0, Math.min(imgs.length - 1, i)) * slides.clientWidth, behavior: 'smooth' });
    g.querySelector('.prev')?.addEventListener('click', () => go(idx() - 1));
    g.querySelector('.next')?.addEventListener('click', () => go(idx() + 1));
    slides.addEventListener('scroll', () => { if (counter) counter.textContent = `${idx() + 1} / ${imgs.length}`; }, { passive: true });
    imgs.forEach((img, i) => img.addEventListener('click', () => openLightbox(imgs, i)));
  });

  // ---------- Lightbox ----------
  const lb = document.getElementById('lightbox');
  const lbImg = lb.querySelector('img');
  let lbList = [], lbIdx = 0;
  function showLb() {
    lbImg.src = lbList[lbIdx].dataset.large;
    lbImg.alt = lbList[lbIdx].alt;
    lb.classList.toggle('single', lbList.length < 2);
  }
  function openLightbox(list, i) { lbList = list; lbIdx = i; showLb(); lb.showModal(); }
  const step = (d) => { lbIdx = (lbIdx + d + lbList.length) % lbList.length; showLb(); };
  lb.querySelector('.lb-close').addEventListener('click', () => lb.close());
  lb.querySelector('.lb-prev').addEventListener('click', () => step(-1));
  lb.querySelector('.lb-next').addEventListener('click', () => step(1));
  lb.addEventListener('click', (e) => { if (e.target === lb) lb.close(); });
  lb.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') step(-1);
    if (e.key === 'ArrowRight') step(1);
  });
  let tx = null;
  lb.addEventListener('touchstart', (e) => { tx = e.touches[0].clientX; }, { passive: true });
  lb.addEventListener('touchend', (e) => {
    if (tx === null) return;
    const dx = e.changedTouches[0].clientX - tx;
    if (Math.abs(dx) > 40 && lbList.length > 1) step(dx < 0 ? 1 : -1);
    tx = null;
  });
})();
