<?php
// Script per l'invio massivo dei solleciti (Cron Job o Trigger Manuale)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config.php';
if (!function_exists('flash_set')) { require_once __DIR__ . '/../functions.php'; }
consenti_esecuzione_cron([1]); // solo crontab, chiave CRON_KEY o admin (pulsante in Sistema)


// Invio tramite la funzione unica di functions.php (verifica risposte SMTP e scrive log_email)
function inviaNotificaReminder($to, $subject, $body_html, $conn, $colore = null) {
    return inviaNotificaEmail($to, $subject, $body_html, $conn, $colore);
}

// 1. Estrai template dal database
$sys_email = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
$obj_tpl  = $sys_email['email_reminder_oggetto'] ?: 'Promemoria Evento Imminente - DiBEST';
$body_tpl = $sys_email['email_reminder_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ricordiamo che l'evento <strong>{TITOLO_EVENTO}</strong> si terrà a breve.</p><p><strong>Dettagli:</strong><br>📅 Data: {DATA_TURNO}<br>🕒 Orario: {ORARIO_TURNO}<br>📍 Luogo: {LUOGO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p><p style='color:red;'>Se non potrai più partecipare, ti preghiamo di accedere alla tua Area Personale e <strong>annullare la prenotazione</strong>, così da cedere il posto a chi è in lista d'attesa.</p>";

// 2. Cerca le prenotazioni CONFERMATE per eventi che iniziano nelle prossime 72 ore a cui NON è stato inviato il reminder
$sql_target = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo
               FROM prenotazioni pr
               JOIN turni t ON pr.turno_id = t.id
               JOIN eventi e ON t.evento_id = e.id
               WHERE (pr.stato = 'confermata' OR pr.stato IS NULL)
                 AND pr.reminder_inviato = 0
                 AND CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '00:00:00')) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 72 HOUR)";

$res_target = $conn->query($sql_target);
$inviati = 0;

if ($res_target && $res_target->num_rows > 0) {
    while ($p_data = $res_target->fetch_assoc()) {
        $data_formatted = implode(' · ', array_filter([$p_data['nome_turno'] ?? '', date('d/m/Y', strtotime($p_data['data_turno']))]));
        $ora_formatted = orario_turno($p_data) ?: 'da definire';

        $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}'];
        $r_repl = [$p_data['nome'], $p_data['cognome'], $p_data['matricola'], $p_data['evento_titolo'], $data_formatted, $ora_formatted, $p_data['luogo'], $p_data['codice_prenotazione']];

        // Invia email
        $mail_ok = inviaNotificaReminder($p_data['email'], str_replace($r_find, $r_repl, $obj_tpl), str_replace($r_find, $r_repl, $body_tpl), $conn, colore_area_turno($conn, $p_data['turno_id']));
        
        // Se inviata correttamente, segna come "reminder_inviato = 1" per non rimandarla
        if ($mail_ok) {
            $id_pr = (int)$p_data['id'];
            $conn->query("UPDATE prenotazioni SET reminder_inviato = 1 WHERE id = $id_pr");
            $inviati++;
        }
    }
}

// Redirect e Output
if (isset($_GET['manual'])) {
    flash_set(" Elaborazione Reminder completata! Sono stati inviati <strong>$inviati</strong> promemoria.");
    header("Location: index.php#tab-sistema");
    exit;
} else {
    // Se eseguito via server cron
    echo "Cron Eseguito: Inviati $inviati promemoria.\n";
}
