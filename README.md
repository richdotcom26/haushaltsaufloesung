# haushaltsaufloesung.wuelbeck.de

Kleine PHP-Seite (PHP 8.1+, SQLite, GD) zum Verschenken von Einrichtungsgegenständen.

- `/`: öffentliche Seite mit Artikeln, Reservieren mit Namen, Zurücknehmen auf demselben Gerät
- `/admin.php`: Artikel anlegen/bearbeiten, Fotos hochladen, Reservierungen mit Namen sehen, aufheben oder selbst vergeben, Artikel als „vergeben“ markieren

## Vor dem Hochladen: `config.php` anpassen
- `WHATSAPP_NUMBER`: deine Handynummer international ohne +, z. B. `491711234567`
- Admin-Passwort ändern (optional):
  `php -r 'echo password_hash("NEUES-PASSWORT", PASSWORD_DEFAULT), "\n";'` → Ergebnis bei `ADMIN_PASSWORD_HASH` eintragen

## Variante A: Netcup-VPS mit Docker
1. DNS bei deinem Domain-Anbieter: A-Record `haushaltsaufloesung` → IP des VPS
2. Ordner auf den Server kopieren, z. B. nach `/opt/haushaltsaufloesung`
3. ```bash
   cd /opt/haushaltsaufloesung
   chown -R 33:33 data uploads        # www-data im Container
   docker compose up -d --build
   ```
4. Reverse Proxy (mit HTTPS) auf `127.0.0.1:8087` zeigen lassen, z. B. Caddy:
   ```
   haushaltsaufloesung.wuelbeck.de {
       reverse_proxy 127.0.0.1:8087
   }
   ```
   Bei nginx: `proxy_pass http://127.0.0.1:8087;` plus `proxy_set_header X-Forwarded-Proto $scheme;` und `client_max_body_size 15m;`

## Variante B: IONOS-Webspace (Apache + PHP)
1. Im IONOS-Kundenbereich Subdomain `haushaltsaufloesung.wuelbeck.de` anlegen und auf einen neuen Ordner zeigen lassen, SSL aktivieren
2. Alle Dateien per SFTP in diesen Ordner hochladen (inkl. `.htaccess`, `data/.htaccess`, `uploads/.htaccess`, `.user.ini`)
3. PHP-Version ≥ 8.1 einstellen; `data/` und `uploads/` müssen beschreibbar sein

## Prüfen nach der Installation
- `https://haushaltsaufloesung.wuelbeck.de/data/app.sqlite` muss **403/404** liefern (Datenbank geschützt)
- `/admin.php` → anmelden → ersten Artikel anlegen

## Nach der Aktion
Im Admin unten: „Nach der Aktion: alle Namen & Verlauf löschen“. Damit ist der Datenschutzhinweis auf der Seite erfüllt.

## Backup
Alles steckt in `data/app.sqlite` und `uploads/`.
