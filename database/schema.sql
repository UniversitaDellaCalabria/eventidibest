-- ============================================================
--  EventiDiBEST CMS — Schema Database Completo
--  Versione: 1.0  |  Charset: utf8mb4
--  Compatibile con: MySQL 5.7+ / MariaDB 10.3+
--
--  UTILIZZO:
--    1. Crea il database:  CREATE DATABASE eventidibest CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--    2. Importa:           mysql -u utente -p eventidibest < schema.sql
--    3. Oppure usa install.php via browser per la configurazione guidata.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Tabella: ruoli
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ruoli` (
    `id`   int(11) NOT NULL AUTO_INCREMENT,
    `nome` varchar(100) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `ruoli` (`id`, `nome`) VALUES
    (1, 'Super Amministratore'),
    (2, 'Gestore Area/Evento'),
    (3, 'Studente'),
    (4, 'Docente/Dipendente'),
    (5, 'Ospite / Esterno');

-- ------------------------------------------------------------
-- Tabella: utenti
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `utenti` (
    `id`                    int(11)      NOT NULL AUTO_INCREMENT,
    `ruolo_id`              int(11)      DEFAULT 5,
    `ruoli_secondari`       varchar(255) DEFAULT '',
    `nome`                  varchar(100) DEFAULT NULL,
    `cognome`               varchar(100) DEFAULT NULL,
    `email`                 varchar(150) DEFAULT NULL,
    `codice_fiscale`        varchar(20)  DEFAULT NULL,
    `matricola`             varchar(50)  DEFAULT NULL,
    `matricola_studente`    varchar(50)  DEFAULT NULL,
    `matricola_dipendente`  varchar(50)  DEFAULT NULL,
    `ultimo_accesso`        datetime     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: configurazione_portale
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `configurazione_portale` (
    `id`                    int(11)      NOT NULL AUTO_INCREMENT,
    `nome_portale`          varchar(255) DEFAULT 'UNIVERSITÀ DELLA CALABRIA',
    `sottotitolo_portale`   varchar(255) DEFAULT 'Dipartimento di Biologia, Ecologia e Scienze della Terra',
    `descrizione_portale`   text         DEFAULT NULL,
    `logo_path`             varchar(255) DEFAULT '',
    `favicon_path`          varchar(255) DEFAULT '',
    `colore_menu_bg`        varchar(20)  DEFAULT '#1e293b',
    `colore_menu_testo`     varchar(20)  DEFAULT '#ffffff',
    `widgets_home`          text         DEFAULT NULL,
    `annuncio_home`         text         DEFAULT NULL,
    `annuncio_colore`       varchar(20)  DEFAULT 'info',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `configurazione_portale` (`id`) VALUES (1);

-- ------------------------------------------------------------
-- Tabella: impostazioni_sistema
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `impostazioni_sistema` (
    `id`                          int(11)      NOT NULL AUTO_INCREMENT,
    `smtp_host`                   varchar(255) DEFAULT '',
    `smtp_port`                   int(11)      DEFAULT 587,
    `smtp_username`               varchar(255) DEFAULT '',
    `smtp_password`               varchar(255) DEFAULT '',
    `smtp_secure`                 varchar(10)  DEFAULT 'tls',
    `smtp_from_email`             varchar(255) DEFAULT '',
    `smtp_from_name`              varchar(255) DEFAULT 'EventiDiBEST',
    `email_conferma_oggetto`      varchar(255) DEFAULT 'Conferma Prenotazione',
    `email_conferma_corpo`        text         DEFAULT NULL,
    `email_canc_utente_oggetto`   varchar(255) DEFAULT 'Cancellazione Prenotazione',
    `email_canc_utente_corpo`     text         DEFAULT NULL,
    `email_canc_admin_oggetto`    varchar(255) DEFAULT 'Annullamento Evento',
    `email_canc_admin_corpo`      text         DEFAULT NULL,
    `email_reminder_oggetto`      varchar(255) DEFAULT 'Promemoria Evento',
    `email_reminder_corpo`        text         DEFAULT NULL,
    `email_attestato_oggetto`     varchar(255) DEFAULT '',
    `email_attestato_corpo`       text         DEFAULT NULL,
    `email_sondaggio_oggetto`     varchar(255) DEFAULT '',
    `email_sondaggio_corpo`       text         DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `impostazioni_sistema` (`id`) VALUES (1);

-- ------------------------------------------------------------
-- Tabella: menu_voci
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `menu_voci` (
    `id`                  int(11)      NOT NULL AUTO_INCREMENT,
    `genitore_id`         int(11)      DEFAULT 0,
    `etichetta`           varchar(100) NOT NULL,
    `url`                 varchar(255) NOT NULL,
    `ordine`              int(11)      DEFAULT 0,
    `apri_nuova_scheda`   tinyint(1)   DEFAULT 0,
    `ruolo_visibilita_id` int(11)      DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: pagine_eventi
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pagine_eventi` (
    `id`                        int(11)      NOT NULL AUTO_INCREMENT,
    `gestore_utente_id`         int(11)      DEFAULT 0,
    `gestori_utenti_ids`        varchar(255) DEFAULT '',
    `notifiche_gestori_ids`     text         DEFAULT NULL,
    `titolo`                    varchar(255) NOT NULL,
    `sottotitolo`               varchar(255) DEFAULT '',
    `slug`                      varchar(150) NOT NULL,
    `colore_primario`           varchar(20)  DEFAULT '#990000',
    `colore_secondario`         varchar(20)  DEFAULT '#0056b3',
    `larghezza_contenitore`     varchar(20)  DEFAULT '85%',
    `layout_template`           varchar(50)  DEFAULT 'advanced_list',
    `num_colonne`               int(11)      DEFAULT 2,
    `spazio_card`               int(11)      DEFAULT 30,
    `mostra_sidebar`            tinyint(1)   DEFAULT 1,
    `chiedi_matricola`          tinyint(1)   DEFAULT 1,
    `visibile`                  tinyint(1)   DEFAULT 1,
    `sidebar_titolo`            varchar(255) DEFAULT '',
    `sidebar_intervallo_date`   varchar(255) DEFAULT '',
    `sidebar_testo`             text         DEFAULT NULL,
    `posizione_box_info`        varchar(50)  DEFAULT 'top',
    `hero_descrizione`          text         DEFAULT NULL,
    `box_info_html`             text         DEFAULT NULL,
    `hero_banner_path`          varchar(255) DEFAULT '',
    `sidebar_immagine_path`     varchar(255) DEFAULT '',
    `ordine`                    int(11)      DEFAULT 0,
    `mostra_in_home`            tinyint(1)   NOT NULL DEFAULT 1,
    `limite_iscrizioni`         varchar(20)  NOT NULL DEFAULT 'nessuno',
    `permessi_gestori_json`     text         DEFAULT NULL,
    `firma_nome`                varchar(255) DEFAULT '',
    `firma_titolo`              varchar(255) DEFAULT '',
    `logo_attestato_path`       varchar(255) DEFAULT '',
    `allegati_box_info`         text         DEFAULT NULL,
    `allegati_sidebar`          text         DEFAULT NULL,
    `copertina_path`            varchar(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: sottocategorie
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sottocategorie` (
    `id`        int(11)      NOT NULL AUTO_INCREMENT,
    `pagina_id` int(11)      NOT NULL,
    `nome`      varchar(255) NOT NULL,
    `ordine`    int(11)      DEFAULT 0,
    `affiancata_in_alto` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'layout Griglia: sezione in alto, affiancata',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: eventi
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `eventi` (
    `id`                    int(11)      NOT NULL AUTO_INCREMENT,
    `pagina_id`             int(11)      NOT NULL,
    `sottocategoria_id`     int(11)      DEFAULT NULL,
    `titolo`                varchar(255) NOT NULL,
    `luogo`                 varchar(255) DEFAULT '',
    `descrizione`           text         DEFAULT NULL,
    `locandina_path`        varchar(255) DEFAULT '',
    `is_evidenza`           tinyint(1)   DEFAULT 0,
    `richiede_prenotazione` tinyint(1)   DEFAULT 1,
    `abilita_presenze`      tinyint(1)   NOT NULL DEFAULT 1,
    `blocca_auto_archivio`  tinyint(1)   NOT NULL DEFAULT 0,
    `ruolo_accesso_id`      int(11)      DEFAULT 0,
    `gestori_utenti_ids`    varchar(255) DEFAULT '',
    `permessi_gestori_json` text         DEFAULT NULL,
    `email_notifiche_extra` text         DEFAULT NULL COMMENT 'indirizzi aggiuntivi per le notifiche delle prenotazioni (CSV)',
    `allegato_pdf`          varchar(255) DEFAULT NULL,
    `ordine`                int(11)      DEFAULT 0,
    `archiviato`            tinyint(1)   DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: turni
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `turni` (
    `id`                    int(11)  NOT NULL AUTO_INCREMENT,
    `evento_id`             int(11)  NOT NULL,
    `nome_turno`            varchar(150) DEFAULT NULL,
    `data_turno`            date     DEFAULT NULL,
    `orario_inizio`         time     DEFAULT NULL,
    `orario_fine`           time     DEFAULT NULL,
    `max_posti`             int(11)  DEFAULT 30,
    `data_apertura`         datetime DEFAULT NULL,
    `data_chiusura`         datetime DEFAULT NULL,
    `token_checkin`         varchar(64) DEFAULT NULL,
    `abilita_lista_attesa`  tinyint(1) DEFAULT 0,
    `abilita_multi_posto`   tinyint(1) DEFAULT 0,
    `richiede_approvazione` tinyint(1) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: prenotazioni
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `prenotazioni` (
    `id`                  int(11)      NOT NULL AUTO_INCREMENT,
    `turno_id`            int(11)      NOT NULL,
    `utente_id`           int(11)      DEFAULT NULL,
    `codice_prenotazione` varchar(50)  NOT NULL,
    `stato`               varchar(50)  DEFAULT 'confermata',
    `presente`            int(11)      DEFAULT 0,
    `num_posti`           int(11)      DEFAULT 1,
    `nome`                varchar(100) DEFAULT NULL,
    `cognome`             varchar(100) DEFAULT NULL,
    `email`               varchar(150) DEFAULT NULL,
    `matricola`           varchar(50)  DEFAULT NULL,
    `dati_custom_json`    text         DEFAULT NULL,
    `data_prenotazione`   datetime     DEFAULT current_timestamp(),
    `data_presenza`             datetime    DEFAULT NULL,
    `scadenza_conferma`         datetime    DEFAULT NULL COMMENT 'posto offerto dalla lista d''attesa: termine per confermare',
    `reminder_inviato`          tinyint(1)  NOT NULL DEFAULT 0,
    `attestato_inviato`         tinyint(1)  NOT NULL DEFAULT 0,
    `email_post_evento_inviata` tinyint(1)  NOT NULL DEFAULT 0,
    `token_sondaggio`           varchar(64) DEFAULT NULL,
    `sondaggio_completato`      tinyint(1)  NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: campi_form  (campi personalizzati del form prenotazione)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campi_form` (
    `id`            int(11)      NOT NULL AUTO_INCREMENT,
    `pagina_id`     int(11)      NOT NULL,
    `evento_id`     int(11)      DEFAULT NULL,
    `nome_campo`    varchar(100) NOT NULL,
    `etichetta`     varchar(255) NOT NULL,
    `tipo_campo`    varchar(50)  DEFAULT 'text',
    `opzioni_select` text        DEFAULT NULL,
    `obbligatorio`  tinyint(1)   DEFAULT 0,
    `ordine`        int(11)      DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: messaggi_prenotazioni  (chat admin ↔ utente per prenotazione)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messaggi_prenotazioni` (
    `id`               int(11)     NOT NULL AUTO_INCREMENT,
    `prenotazione_id`  int(11)     NOT NULL,
    `mittente_tipo`    varchar(20) NOT NULL COMMENT 'admin | utente',
    `mittente_id`      int(11)     DEFAULT NULL,
    `messaggio`        text        NOT NULL,
    `letto`            tinyint(1)  DEFAULT 0,
    `data_invio`       datetime    DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: log_attivita  (audit trail azioni amministrative)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `log_attivita` (
    `id`            int(11)      NOT NULL AUTO_INCREMENT,
    `utente_id`     int(11)      DEFAULT NULL,
    `azione`        varchar(255) NOT NULL,
    `dettagli_json` text         DEFAULT NULL,
    `indirizzo_ip`  varchar(45)  DEFAULT NULL,
    `data_ora`      datetime     DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: sondaggi
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sondaggi` (
    `id`        int(11)      NOT NULL AUTO_INCREMENT,
    `evento_id` int(11)      NOT NULL,
    `titolo`    varchar(255) NOT NULL,
    `attivo`    tinyint(1)   DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: sondaggi_domande
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sondaggi_domande` (
    `id`            int(11)      NOT NULL AUTO_INCREMENT,
    `sondaggio_id`  int(11)      NOT NULL,
    `testo_domanda` varchar(500) NOT NULL,
    `tipo`          varchar(50)  DEFAULT 'text' COMMENT 'rating | nps | matrice | radio | select | checkboxes | text | textarea | number | date | email | tel | url | time | separator',
    `obbligatorio`  tinyint(1)   DEFAULT 0,
    `opzioni`       text         DEFAULT NULL,
    `condizione_json` text       DEFAULT NULL COMMENT '{"se_id": domanda_id, "se_val": "valore"}',
    `ordine`        int(11)      DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: sondaggi_risposte
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sondaggi_risposte` (
    `id`             int(11) NOT NULL AUTO_INCREMENT,
    `sondaggio_id`   int(11) NOT NULL,
    `domanda_id`     int(11) NOT NULL,
    `risposta`       text    DEFAULT NULL,
    `data_risposta`  datetime DEFAULT current_timestamp(),
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: template_email
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `template_email` (
    `id`        int(11)      NOT NULL AUTO_INCREMENT,
    `nome`      varchar(100) NOT NULL,
    `oggetto`   varchar(255) NOT NULL,
    `corpo`     text         NOT NULL,
    `attivo`    tinyint(1)   DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: rate_limit_attempts  (protezione flooding endpoint)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limit_attempts` (
    `id`       int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_hash`  char(64)         NOT NULL,
    `endpoint` varchar(100)     NOT NULL,
    `hit_at`   datetime         DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_ip_endpoint` (`ip_hash`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: slide_home  (carosello della home)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `slide_home` (
    `id`            int(11)      NOT NULL AUTO_INCREMENT,
    `immagine_path` varchar(255) NOT NULL,
    `titolo`        varchar(255) DEFAULT '',
    `sottotitolo`   varchar(255) DEFAULT '',
    `link`          varchar(500) DEFAULT '',
    `ordine`        int(11)      DEFAULT 0,
    `attiva`        tinyint(1)   DEFAULT 1,
    `created_at`    datetime     DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_ordine` (`ordine`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: log_accessi  (accessi SSO)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `log_accessi` (
    `id`         int(11)      NOT NULL AUTO_INCREMENT,
    `utente_id`  int(11)      DEFAULT NULL,
    `email`      varchar(255) DEFAULT NULL,
    `nome`       varchar(100) DEFAULT NULL,
    `cognome`    varchar(100) DEFAULT NULL,
    `ip`         varchar(45)  DEFAULT NULL,
    `user_agent` varchar(512) DEFAULT NULL,
    `tipo`       varchar(20)  DEFAULT 'sso',
    `created_at` datetime     DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_uid` (`utente_id`),
    KEY `idx_cat` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabella: log_email  (registro invii: accettate / rifiutate dal server SMTP)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `log_email` (
    `id`           int(11)      NOT NULL AUTO_INCREMENT,
    `destinatario` varchar(255) DEFAULT NULL,
    `oggetto`      varchar(255) DEFAULT NULL,
    `esito`        tinyint(1)   DEFAULT 0,
    `canale`       varchar(10)  DEFAULT 'smtp',
    `errore`       varchar(500) DEFAULT NULL,
    `created_at`   datetime     DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_cat` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
