<?php
// setup_db.php - Eseguire questo file SOLO UNA VOLTA da browser per aggiornare il DB
require_once 'config.php';

echo "<h3>Aggiornamento Database in corso...</h3>";

$conn->query("CREATE TABLE IF NOT EXISTS ruoli (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(100) NOT NULL)");
$conn->query("INSERT IGNORE INTO ruoli (id, nome) VALUES (1, 'Amministratore'), (2, 'Gestore Prenotazioni'), (3, 'Studenti'), (4, 'Dipendenti'), (5, 'Esterni / Ospiti')");

$conn->query("CREATE TABLE IF NOT EXISTS utenti (id INT AUTO_INCREMENT PRIMARY KEY, codice_fiscale VARCHAR(50), nome VARCHAR(100), cognome VARCHAR(100), email VARCHAR(150), matricola VARCHAR(50) DEFAULT NULL, matricola_studente VARCHAR(50) DEFAULT NULL, matricola_dipendente VARCHAR(50) DEFAULT NULL, ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(255) DEFAULT '', ultimo_accesso DATETIME DEFAULT NULL)");
@$conn->query("ALTER TABLE utenti MODIFY COLUMN matricola VARCHAR(50) DEFAULT NULL");
@$conn->query("ALTER TABLE utenti ADD COLUMN matricola_studente VARCHAR(50) DEFAULT NULL AFTER matricola");
@$conn->query("ALTER TABLE utenti ADD COLUMN matricola_dipendente VARCHAR(50) AFTER matricola_studente");
@$conn->query("ALTER TABLE utenti ADD COLUMN ruoli_secondari VARCHAR(255) DEFAULT '' AFTER ruolo_id");
@$conn->query("ALTER TABLE utenti ADD COLUMN ultimo_accesso DATETIME DEFAULT NULL");

$conn->query("CREATE TABLE IF NOT EXISTS campi_form (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT DEFAULT 0, evento_id INT DEFAULT NULL, nome_campo VARCHAR(100), etichetta VARCHAR(255), tipo_campo VARCHAR(50) DEFAULT 'text', opzioni_select TEXT, obbligatorio INT DEFAULT 0, ordine INT DEFAULT 0)");
@$conn->query("ALTER TABLE campi_form ADD COLUMN pagina_id INT DEFAULT 0 AFTER id");
@$conn->query("ALTER TABLE campi_form MODIFY COLUMN evento_id INT DEFAULT NULL");
@$conn->query("ALTER TABLE campi_form MODIFY COLUMN tipo_campo VARCHAR(50) DEFAULT 'text'");

$conn->query("ALTER TABLE turni ADD COLUMN data_apertura DATETIME DEFAULT NULL AFTER max_posti");
$conn->query("ALTER TABLE turni ADD COLUMN data_chiusura DATETIME DEFAULT NULL AFTER data_apertura");
@$conn->query("ALTER TABLE turni ADD COLUMN abilita_lista_attesa INT DEFAULT 0 AFTER data_chiusura");
@$conn->query("ALTER TABLE turni ADD COLUMN abilita_multi_posto INT DEFAULT 0 AFTER abilita_lista_attesa");
@$conn->query("ALTER TABLE turni ADD COLUMN richiede_approvazione INT DEFAULT 0 AFTER abilita_multi_posto");

$conn->query("ALTER TABLE eventi ADD COLUMN archiviato INT DEFAULT 0 AFTER ruolo_accesso_id");
$conn->query("ALTER TABLE eventi ADD COLUMN locandina_path VARCHAR(255) DEFAULT '' AFTER descrizione");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN gestore_utente_id INT DEFAULT 0 AFTER ordine");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN gestori_utenti_ids VARCHAR(255) DEFAULT '' AFTER gestore_utente_id");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN num_colonne INT DEFAULT 2 AFTER layout_template");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN spazio_card INT DEFAULT 30 AFTER num_colonne");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN hero_descrizione TEXT AFTER layout_template");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN hero_banner_path VARCHAR(255) AFTER box_info_html");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN posizione_box_info VARCHAR(50) DEFAULT 'top' AFTER hero_banner_path");
$conn->query("ALTER TABLE pagine_eventi ADD COLUMN sidebar_immagine_path VARCHAR(255) DEFAULT '' AFTER sidebar_testo");
@$conn->query("ALTER TABLE pagine_eventi ADD COLUMN chiedi_matricola INT DEFAULT 1 AFTER mostra_sidebar");
@$conn->query("ALTER TABLE pagine_eventi ADD COLUMN visibile INT DEFAULT 1 AFTER chiedi_matricola");

$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_conferma_oggetto VARCHAR(255) DEFAULT 'Conferma Prenotazione Eventi'");
$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_conferma_corpo TEXT");
$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_canc_utente_oggetto VARCHAR(255) DEFAULT 'Cancellazione Prenotazione'");
$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_canc_utente_corpo TEXT");
$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_canc_admin_oggetto VARCHAR(255) DEFAULT 'Annullamento Prenotazione'");
$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_canc_admin_corpo TEXT");

@$conn->query("ALTER TABLE prenotazioni ADD COLUMN utente_id INT DEFAULT NULL AFTER turno_id");
@$conn->query("ALTER TABLE prenotazioni ADD COLUMN matricola VARCHAR(50) DEFAULT NULL AFTER email");
@$conn->query("ALTER TABLE prenotazioni ADD COLUMN stato VARCHAR(20) DEFAULT 'confermata' AFTER utente_id");
@$conn->query("ALTER TABLE prenotazioni ADD COLUMN num_posti INT DEFAULT 1 AFTER stato");
@$conn->query("ALTER TABLE prenotazioni ADD COLUMN reminder_inviato INT DEFAULT 0 AFTER num_posti");
@$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_reminder_oggetto VARCHAR(255) DEFAULT 'Promemoria Prenotazione Evento'");
@$conn->query("ALTER TABLE impostazioni_sistema ADD COLUMN email_reminder_corpo TEXT");

echo "<h3 style='color:green;'>Completato con successo! Puoi chiudere questa pagina.</h3>";
?>
