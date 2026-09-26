# EventiDiBEST CMS

Portale open-source per la gestione eventi, prenotazioni e presenze del **Dipartimento DiBEST** — Università della Calabria.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://www.mysql.com/)
[![Guardalo live](https://img.shields.io/badge/Guardalo-Live-green.svg)](https://dibest2.unical.it/eventi/)

**Guardalo live:** https://dibest2.unical.it/eventi/

---

## Funzionalita

- **Gestione eventi multi-area** con sezioni (Pagine) personalizzabili per colori, layout e accessi
- **8 layout di pagina**: griglia per sezioni, lista cronologica, elenco avanzato con ricerca, calendario, timeline, agenda a schede per giorno, gruppi/corsi, progetti — tutti gestiscono anche i turni senza data fissa
- **Progetti** (es. Formazione Scuola Lavoro): maschera dedicata con corso di laurea, periodo o "date da definire", requisiti di accesso, ore, studenti per scuola, articolazione in moduli/fasi/incontri, obiettivi, conoscenze e competenze, referenti con pagina personale; scheda pubblica di ogni progetto con link condivisibile; **edizioni** (repliche) da una scuola ciascuna con lista d'attesa in ordine di arrivo; iscrizione del docente referente con SSO, SPID o CIE e controllo del numero minimo e massimo di studenti
- **Prenotazioni con turni**: nome, data e orari facoltativi, apertura/chiusura automatica, multi-posto, approvazione manuale
- **Lista d'attesa**: posizione in coda visibile all'utente ("Sei 3° in lista"), posto liberato offerto con 24 ore per confermare o rinunciare, promozione automatica
- **Limite iscrizioni per area** (un solo evento o un solo turno per evento): le liste d'attesa non contano e decadono alla prima conferma
- **Autenticazione SSO** via SimpleSAMLphp (integrazione SSO Unical) + accesso esterno (CIE/SPID)
- **RBAC** a 3 livelli: Super Admin, Gestore Area/Evento, Utente — ogni azione verifica che evento o prenotazione appartengano all'area del gestore
- **Check-in** tramite QR code: scanner integrato nel pannello admin (scansione continua, contatore presenti in tempo reale, check-in manuale, lettori USB), self check-in studente, email attestato automatica post-check-in
- **Home configurabile a widget**: carosello, la mia prossima prenotazione (con ricevuta QR), bacheca annunci, card aree, ultimi posti disponibili, prossimi appuntamenti, numeri del dipartimento — ordine con drag & drop, colonne e numero di card regolabili
- **Gestione iscritti**: azioni di massa (presenze, approvazione, promozione dalla lista d'attesa, annullamento), prenotazione manuale
- **Duplicazione** di eventi e progetti (con turni, campi del form e sondaggi) e di singoli turni
- **Aree senza file da generare**: ogni area è servita da `area.php` tramite `.htaccess`, gli slug che coincidono con file del sito vengono rifiutati
- **Attestati** PDF generati automaticamente al completamento dell'evento
- **Sondaggi/questionari** collegabili agli eventi: 15 tipi di campo (rating, NPS, matrice, scelta, testo, data, email…), ordinamento drag & drop, logica condizionale ("mostra se…"), anteprima interattiva, statistiche NPS ed export XLS
- **Dashboard amministrativa** con KPI, grafici (Chart.js), messaggi non letti
- **Statistiche & report**: presenze effettive, tasso di presenza, annullate, riempimento, trend iscrizioni 30 giorni, presenti vs assenti per evento, vista Live/Storico, export CSV/Excel e stampa PDF
- **Menu di navigazione** a 3 livelli con ordinamento drag & drop
- **Profilo utente**: pagina dedicata con dati SSO e modifica email personale
- **Form builder** per campi prenotazione personalizzati per area/evento
- **Email automatiche**: conferma, cancellazione, promemoria (via SMTP configurabile), con layout nel colore dell'area, registro degli invii ed email di prova dal pannello
- **Notifiche delle prenotazioni** ai gestori, a indirizzi in copia scelti per ogni evento e ai referenti dei progetti, con il riepilogo completo della prenotazione (campi aggiuntivi compresi)
- **Badge e barre dei posti disponibili** in tempo reale sulle card eventi e in home (liberi / lista d'attesa / esauriti / concluso)
- **Colore dell'area coerente** su pagine, badge, ricevute ed email, con testo a contrasto calcolato automaticamente
- **Accessibilità**: struttura dei titoli, landmark, focus da tastiera visibile, contrasti verificati con axe-core
- **Stampa lista iscritti** in vista ottimizzata per stampa/PDF con filtri attivi
- **Ricerca testuale** iscritti per nome, cognome, email, codice prenotazione
- **Audit log** di tutte le operazioni amministrative
- **Rate limiting** anti-flood sugli endpoint pubblici
- **PWA-ready** (manifest + service worker + offline fallback)
- **Configurazione portale** da pannello admin (logo, colori, SMTP, email template)

---

## Requisiti

| Componente | Versione minima |
|---|---|
| PHP | 8.2+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Web server | Apache (mod_rewrite) o Nginx |
| SimpleSAMLphp | 1.19+ (solo per SSO istituzionale) |

**Librerie PHP utilizzate** (incluse via CDN, nessun Composer richiesto):
- Bootstrap 5.3
- Font Awesome 6.4
- DataTables
- Chart.js
- SortableJS (drag & drop)
- PHPMailer (incluso in `mailer.php`)

---

## Installazione

### 1. Scarica il codice

```bash
git clone https://github.com/TUO_USERNAME/eventidibest-cms.git
cd eventidibest-cms
```

### 2. Configura le variabili d'ambiente

```bash
cp .env.example .env
```

Modifica `.env` con i tuoi parametri MySQL:

```ini
DB_HOST=localhost
DB_USER=db_username
DB_PASS=db_password
DB_NAME=eventi_dibest
```

### 3. Crea il database

**Opzione A — Installer via browser (consigliata):**

Visita `http://tuo-dominio/install.php` e compila il form con le credenziali DB e il profilo del Super Admin. Al termine, **elimina `install.php`** dal server.

**Opzione B — Import SQL manuale:**

```bash
mysql -u utente -p eventi_dibest < database/schema.sql
```

Poi imposta manualmente le credenziali in `.env`.

### 4. Configura il web server

Il file `.htaccess` e` gia` predisposto per Apache. Per Nginx, aggiungi:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 5. Permessi cartelle

```bash
chmod 775 uploads/ cache/
```

La cartella `cache/` deve essere scrivibile da PHP: oltre alla cache della configurazione contiene i marcatori degli aggiornamenti del database (vedi sotto). La cartella del codice invece non deve essere scrivibile: creare una nuova area non genera file.

### Operazioni pianificate (cron)

Gli script `cron_background.php`, `cron_attestati.php`, `admin/cron_reminders.php` e `admin/cron_backup.php` si avviano da riga di comando (es. `php cron_background.php`) oppure via URL con la chiave `CRON_KEY` del file `.env` (almeno 16 caratteri), es. `https://tuo-dominio/eventi/cron_background.php?key=LA_TUA_CHIAVE`. Senza chiave rispondono 403.

### Aggiornamenti del database

Non servono script SQL manuali dopo un aggiornamento del codice. Al primo accesso la funzione `assicura_schema()` in `functions.php` crea le tabelle e le colonne mancanti e corregge i tipi di colonna dei database più vecchi, poi scrive un marcatore (es. `cache/schema_v10.ok`) e da quel momento non interroga più lo schema. Le pagine non modificano mai la struttura del database: ogni nuova colonna va aggiunta lì, cambiando il nome del marcatore.

### 6. Configura il SSO (opzionale)

Se usi SimpleSAMLphp per l'autenticazione istituzionale, modifica le impostazioni in `saml_login.php` e `functions.php` puntando alla tua istanza SimpleSAML.

La funzione `sync_sso_user()` in `functions.php` gestisce automaticamente la mappatura degli attributi SAML per i diversi tipi di utente:

| Tipo utente | Attributo email cercato | Fallback |
|---|---|---|
| Studente | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno (i nomi degli attributi ricevuti finiscono nel log) |
| Dipendente | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno |
| Esterno (CIE/SPID) | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno |

Il tipo viene riconosciuto tramite gli attributi `matricola_studente` e `matricola_dipendente` dell'IdP. Le email errate salvate in precedenza vengono corrette automaticamente al login successivo.

---

## Struttura del progetto

```
eventidibest-cms/
├── admin/              # Pannello di amministrazione
│   ├── admin_header.php        # Autenticazione, RBAC, sidebar
│   ├── dashboard.php           # Dashboard con KPI e grafici
│   ├── eventi.php              # CRUD eventi, turni e sezioni, duplicazione
│   ├── progetti.php            # Progetti: scheda completa, edizioni, iscrizione delle scuole
│   ├── iscritti.php            # Gestione prenotazioni (ricerca, presenza, azioni di massa)
│   ├── scanner.php             # Scanner check-in integrato con contatore in tempo reale
│   ├── impostazioni_area.php   # Colori, layout e regole di ogni area
│   ├── testata.php             # Testata, carosello e widget della home
│   ├── stampa_lista_iscritti.php # Vista stampabile/PDF lista iscritti
│   ├── messaggi.php            # Sistema messaggistica admin<->utente
│   ├── sondaggi.php            # Questionari: campi, logica condizionale, statistiche
│   ├── menu.php                # Menu a 3 livelli con drag & drop
│   ├── statistiche.php         # KPI presenze, trend, Live/Storico, export CSV/Excel
│   ├── audit_log.php           # Log attivita sistema
│   └── ...
├── database/
│   └── schema.sql          # Schema completo del database (22 tabelle)
├── uploads/            # File caricati (escluso da git)
├── cache/              # Cache runtime (escluso da git)
├── assets/             # Icone PWA
├── config.php          # Connessione DB, session, security headers, CSP
├── functions.php       # Funzioni core (CSRF, rate limit, email, log, aggiornamenti dello schema)
├── mailer.php          # Wrapper PHPMailer
├── install.php         # Installer guidato (da eliminare dopo l'uso)
├── index.php           # Homepage pubblica a widget
├── master_template.php # Motore dei layout delle pagine area (le pagine area lo includono)
├── checkin.php         # Esito del QR letto con la fotocamera del telefono (admin)
├── self_checkin.php    # Self check-in studente
├── area_personale.php  # Area utente loggato (prenotazioni, messaggi)
├── profilo.php         # Profilo utente: dati SSO e modifica email
├── sw.js               # Service Worker (PWA)
└── manifest.json       # Web App Manifest (PWA)
```

---

## Schema Database

Il database e` composto da **22 tabelle**:

| Tabella | Descrizione |
|---|---|
| `ruoli` | Ruoli utente (Admin, Gestore, Studente, Dipendente, Ospite) |
| `utenti` | Profili utente sincronizzati da SSO |
| `pagine_eventi` | Sezioni/aree del portale (Welcome Week, OpenLab, ...) |
| `sottocategorie` | Sezioni degli eventi per area (con opzione "affiancata in alto" nel layout Griglia) |
| `eventi` | Singoli eventi e progetti (campo `tipo`) con locandina e accesso per ruolo |
| `progetti_dettagli` | Scheda dei progetti: periodo, requisiti, studenti per scuola, referenti, moduli, obiettivi e competenze |
| `turni` | Slot orari con posti, apertura/chiusura, lista attesa |
| `prenotazioni` | Prenotazioni con QR code univoco e stato |
| `campi_form` | Campi custom del form prenotazione per area/evento |
| `messaggi_prenotazioni` | Chat admin ↔ utente per ogni prenotazione |
| `sondaggi` | Questionari collegati agli eventi |
| `sondaggi_domande` | Domande del questionario (ordine e condizione di visibilità) |
| `sondaggi_risposte` | Risposte anonime |
| `template_email` | Template email personalizzabili |
| `impostazioni_sistema` | Config SMTP e template email di sistema |
| `configurazione_portale` | Impostazioni grafiche del portale |
| `menu_voci` | Voci del menu di navigazione principale |
| `log_attivita` | Audit trail di tutte le operazioni admin |
| `rate_limit_attempts` | Protezione anti-flood endpoint pubblici |
| `slide_home` | Immagini del carosello della home |
| `log_accessi` | Registro degli accessi SSO |
| `log_email` | Registro degli invii email (accettate / rifiutate dal server SMTP) |

---

## Sicurezza

- Tutti i parametri utente sono passati tramite **prepared statements** (MySQLi)
- **CSRF token** su tutti i form e sulle richieste AJAX
- **Controllo dei permessi per ogni azione**: un gestore agisce solo su eventi, turni e prenotazioni della propria area o dei propri eventi
- Output HTML e dati passati a JavaScript sempre codificati; i codici QR letti dallo scanner non vengono mai aperti come link
- Colori personalizzati validati prima di essere usati negli stili
- **Rate limiting** sugli endpoint di prenotazione e sondaggio
- Ruolo richiesto per prenotare e appartenenza del turno all'area verificati anche dal server
- Script cron eseguibili solo da riga di comando, con `CRON_KEY` o da un utente con il ruolo adatto
- **Content Security Policy** (CSP) configurata in `config.php`
- **HTTP Security Headers**: HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- Cookie di sessione: `Secure`, `HttpOnly`, `SameSite=Lax`
- Password SMTP cifrate nel database, mai esposte nel codice sorgente
- `uploads/` e `.env` esclusi da git e protetti da `.htaccess`

---

## Contribuire

Pull request e segnalazioni di bug sono benvenute. Per modifiche sostanziali, apri prima una issue per discutere il cambiamento proposto.

---

## Licenza

Questo software e` distribuito sotto licenza **GNU Affero General Public License v3.0**.  
Vedi il file [LICENSE](LICENSE) per il testo completo.

In sintesi: puoi usare, modificare e distribuire il software liberamente, ma **qualsiasi versione modificata che esegui su un server deve rendere il codice sorgente disponibile agli utenti del servizio**.

---

## Autore

**Emanuele Dodaro** — Universita della Calabria, Dipartimento DiBEST  
Progetto sviluppato per la gestione eventi accademici.
