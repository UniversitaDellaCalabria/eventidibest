# EventiDiBEST CMS

Portale open-source per la gestione eventi, prenotazioni e presenze del **Dipartimento DiBEST** — Università della Calabria.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://www.mysql.com/)
[![Demo](https://img.shields.io/badge/Demo-Live-green.svg)](https://dibest2.unical.it/eventi/)

**Demo live:** https://dibest2.unical.it/eventi/

---

## Funzionalita

- **Gestione eventi multi-area** con sezioni (Pagine) personalizzabili per colori, layout e accessi
- **Prenotazioni con turni**: apertura/chiusura automatica, lista d'attesa, multi-posto, approvazione manuale
- **Autenticazione SSO** via SimpleSAMLphp (integrazione SSO Unical) + accesso esterno (CIE/SPID)
- **RBAC** a 3 livelli: Super Admin, Gestore Area/Evento, Utente
- **Check-in** tramite QR code (scanner da browser, self check-in studente) con email attestato automatica post-check-in
- **Attestati** PDF generati automaticamente al completamento dell'evento
- **Sondaggi/questionari** collegabili agli eventi con export XLS
- **Dashboard amministrativa** con KPI, grafici (Chart.js), messaggi non letti, export CSV/Excel e stampa PDF
- **Profilo utente**: pagina dedicata con dati SSO e modifica email personale
- **Form builder** per campi prenotazione personalizzati per area/evento
- **Email automatiche**: conferma, cancellazione, promemoria (via SMTP configurabile)
- **Badge posti disponibili** in tempo reale sulle card eventi (liberi / lista d'attesa / esauriti / concluso)
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
| PHP | 7.4+ (testato fino a 8.2) |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Web server | Apache (mod_rewrite) o Nginx |
| SimpleSAMLphp | 1.19+ (solo per SSO istituzionale) |

**Librerie PHP utilizzate** (incluse via CDN, nessun Composer richiesto):
- Bootstrap 5.3
- Font Awesome 6.4
- DataTables
- Chart.js
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

### 6. Configura il SSO (opzionale)

Se usi SimpleSAMLphp per l'autenticazione istituzionale, modifica le impostazioni in `saml_login.php` e `functions.php` puntando alla tua istanza SimpleSAML.

La funzione `sync_sso_user()` in `functions.php` gestisce automaticamente la mappatura degli attributi SAML per i diversi tipi di utente:

| Tipo utente | Attributo email cercato | Fallback |
|---|---|---|
| Studente | `mail` / OID `0.9.2342.19200300.100.1.3` | `CF@studenti.unical.it` |
| Dipendente | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno |
| Esterno (CIE/SPID) | `mail` / OID `0.9.2342.19200300.100.1.3` | nessuno |

Il tipo viene riconosciuto tramite gli attributi `matricola_studente` e `matricola_dipendente` dell'IdP. Le email errate salvate in precedenza vengono corrette automaticamente al login successivo.

---

## Struttura del progetto

```
eventidibest-cms/
├── admin/              # Pannello di amministrazione
│   ├── admin_header.php    # Autenticazione, RBAC, sidebar
│   ├── dashboard.php       # Dashboard con KPI e grafici
│   ├── eventi.php          # CRUD eventi e turni
│   ├── iscritti.php            # Gestione prenotazioni (ricerca, presenza, attestati)
│   ├── stampa_lista_iscritti.php # Vista stampabile/PDF lista iscritti
│   ├── messaggi.php            # Sistema messaggistica admin<->utente
│   ├── sondaggi.php            # Questionari e feedback
│   ├── statistiche.php         # KPI, grafici, export CSV/Excel, stampa PDF
│   ├── audit_log.php           # Log attivita sistema
│   └── ...
├── database/
│   └── schema.sql          # Schema completo del database (18 tabelle)
├── uploads/            # File caricati (escluso da git)
├── cache/              # Cache runtime (escluso da git)
├── assets/             # Icone PWA
├── config.php          # Connessione DB, session, security headers, CSP
├── functions.php       # Funzioni core (CSRF, rate limit, email, log)
├── mailer.php          # Wrapper PHPMailer
├── install.php         # Installer guidato (da eliminare dopo l'uso)
├── index.php           # Homepage pubblica
├── prenota.php         # Form prenotazione pubblica
├── checkin.php         # Check-in via QR (admin)
├── self_checkin.php    # Self check-in studente
├── area_personale.php  # Area utente loggato (prenotazioni, messaggi)
├── profilo.php         # Profilo utente: dati SSO e modifica email
├── sw.js               # Service Worker (PWA)
└── manifest.json       # Web App Manifest (PWA)
```

---

## Schema Database

Il database e` composto da **18 tabelle**:

| Tabella | Descrizione |
|---|---|
| `ruoli` | Ruoli utente (Admin, Gestore, Studente, Dipendente, Ospite) |
| `utenti` | Profili utente sincronizzati da SSO |
| `pagine_eventi` | Sezioni/aree del portale (Welcome Week, OpenLab, ...) |
| `sottocategorie` | Sottocategorie eventi per area |
| `eventi` | Singoli eventi con locandina e accesso per ruolo |
| `turni` | Slot orari con posti, apertura/chiusura, lista attesa |
| `prenotazioni` | Prenotazioni con QR code univoco e stato |
| `campi_form` | Campi custom del form prenotazione per area/evento |
| `messaggi_prenotazioni` | Chat admin ↔ utente per ogni prenotazione |
| `sondaggi` | Questionari collegati agli eventi |
| `sondaggi_domande` | Domande del questionario |
| `sondaggi_risposte` | Risposte anonime |
| `template_email` | Template email personalizzabili |
| `impostazioni_sistema` | Config SMTP e template email di sistema |
| `configurazione_portale` | Impostazioni grafiche del portale |
| `menu_voci` | Voci del menu di navigazione principale |
| `log_attivita` | Audit trail di tutte le operazioni admin |
| `rate_limit_attempts` | Protezione anti-flood endpoint pubblici |

---

## Sicurezza

- Tutti i parametri utente sono passati tramite **prepared statements** (MySQLi)
- **CSRF token** su tutti i form
- **Rate limiting** sugli endpoint di prenotazione e sondaggio
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
