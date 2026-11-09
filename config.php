<?php
// ------------------------------------------------------------
// Einstellungen – hier alles Anpassbare an einer Stelle
// ------------------------------------------------------------

const SITE_TITLE  = 'Haushaltsauflösung – alles zu verschenken';
const OWNER_NAME  = 'Rainer';

// WhatsApp-Nummer international, nur Ziffern, ohne + und ohne führende 0
// Beispiel: 0171 1234567  ->  491711234567
const WHATSAPP_NUMBER = '491714009226';

// Admin-Passwort (Hash). Neues Passwort setzen:
//   php -r 'echo password_hash("NEUES-PASSWORT", PASSWORD_DEFAULT), "\n";'
// und den Ausgabe-String hier eintragen.
const ADMIN_PASSWORD_HASH = '$2y$10$Kteuq/SrkPQG1rG2Gz4OA.dhd0wwWxf39M2qk0Wm42dhS0.w6MRne';

// Kopfbereich der Seite
const EVENT_BADGE = 'Samstag, 21. November · 11–15 Uhr · Heven';

const INTRO_HTML = <<<HTML
<p>Am <strong>Samstag, 21. November, zwischen 11 und 15 Uhr</strong> können alle Sachen
in Heven <strong>kostenlos abgeholt</strong> werden. Über eine freiwillige Spende freuen wir uns.</p>
<p>Bei Interesse gerne eine persönliche Nachricht per WhatsApp an mich.
Einzelne Sachen können nach Vereinbarung auch vorher abgeholt werden.</p>
HTML;

// Reservierungen pro Gerät/IP und Stunde (Schutz vor Spaß-Massenreservierungen)
const MAX_RESERVATIONS_PER_HOUR = 15;
