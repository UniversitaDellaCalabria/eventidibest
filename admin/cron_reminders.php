<?php
// Script per l'invio massivo dei solleciti (Cron Job o Trigger Manuale)
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../config.php';
if (!function_exists('flash_set')) { require_once __DIR__ . '/../functions.php'; }

// AUTO-PATCH colonna reminder_inviato
$conn->query("ALTER TABLE prenotazioni ADD COLUMN IF NOT EXISTS reminder_inviato TINYINT(1) DEFAULT 0");

// Funzione di invio Email (via Socket SMTP per evitare blocchi)
function inviaNotificaReminder($to, $subject, $body_html, $conn) {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
    if (!$sys) return false;

    $host   = $sys['smtp_host'] ?? '';
    $port   = (int)($sys['smtp_port'] ?? 587);
    $user   = $sys['smtp_username'] ?? '';
    $pass   = $sys['smtp_password'] ?? '';
    $from_e = !empty($sys['smtp_from_email']) ? $sys['smtp_from_email'] : 'noreply.eventi@unical.it';
    $from_n = !empty($sys['smtp_from_name']) ? $sys['smtp_from_name'] : 'Eventi DiBEST';
    $secure = strtolower($sys['smtp_secure'] ?? 'tls');

    if (empty($host)) {
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\nFrom: $from_n <$from_e>\r\n";
        return @mail($to, $subject, $body_html, $headers);
    }

    $transport = ($secure === 'ssl') ? 'ssl://' : '';
    $socket = @fsockopen($transport . $host, $port, $errno, $errstr, 5);
    if (!$socket) {
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\nFrom: $from_n <$from_e>\r\n";
        return @mail($to, $subject, $body_html, $headers);
    }

    @fgets($socket, 512); @fputs($socket, "EHLO " . gethostname() . "\r\n");
    while ($str = @fgets($socket, 512)) { if (substr($str, 3, 1) == " ") break; }

    if ($secure === 'tls') {
        @fputs($socket, "STARTTLS\r\n"); @fgets($socket, 512);
        @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        @fputs($socket, "EHLO " . gethostname() . "\r\n");
        while ($str = @fgets($socket, 512)) { if (substr($str, 3, 1) == " ") break; }
    }

    if (!empty($user) && !empty($pass)) {
        @fputs($socket, "AUTH LOGIN\r\n"); @fgets($socket, 512);
        @fputs($socket, base64_encode($user) . "\r\n"); @fgets($socket, 512);
        @fputs($socket, base64_encode($pass) . "\r\n"); @fgets($socket, 512);
    }

    @fputs($socket, "MAIL FROM: <$from_e>\r\n"); @fgets($socket, 512);
    @fputs($socket, "RCPT TO: <$to>\r\n"); @fgets($socket, 512);
    @fputs($socket, "DATA\r\n"); @fgets($socket, 512);

    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($from_n) . "?= <$from_e>\r\nTo: <$to>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";

    @fputs($socket, $headers . "\r\n" . $body_html . "\r\n.\r\nQUIT\r\n");
    @fclose($socket);
    return true;
}

// 1. Estrai template dal database
$sys_email = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
$obj_tpl  = $sys_email['email_reminder_oggetto'] ?: 'Promemoria Evento Imminente - DiBEST';
$body_tpl = $sys_email['email_reminder_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ricordiamo che l'evento <strong>{TITOLO_EVENTO}</strong> si terrà a breve.</p><p><strong>Dettagli:</strong><br>📅 Data: {DATA_TURNO}<br>🕒 Orario: {ORARIO_TURNO}<br>📍 Luogo: {LUOGO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p><p style='color:red;'>Se non potrai più partecipare, ti preghiamo di accedere alla tua Area Personale e <strong>annullare la prenotazione</strong>, così da cedere il posto a chi è in lista d'attesa.</p>";

// 2. Cerca le prenotazioni CONFERMATE per eventi che iniziano nelle prossime 72 ore a cui NON è stato inviato il reminder
$sql_target = "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo 
               FROM prenotazioni pr 
               JOIN turni t ON pr.turno_id = t.id 
               JOIN eventi e ON t.evento_id = e.id 
               WHERE (pr.stato = 'confermata' OR pr.stato IS NULL) 
                 AND pr.reminder_inviato = 0 
                 AND CONCAT(t.data_turno, ' ', t.orario_inizio) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 72 HOUR)";

$res_target = $conn->query($sql_target);
$inviati = 0;

if ($res_target && $res_target->num_rows > 0) {
    while ($p_data = $res_target->fetch_assoc()) {
        $data_formatted = date('d/m/Y', strtotime($p_data['data_turno']));
        $ora_formatted = substr($p_data['orario_inizio'], 0, 5) . ' - ' . substr($p_data['orario_fine'], 0, 5);

        $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}'];
        $r_repl = [$p_data['nome'], $p_data['cognome'], $p_data['matricola'], $p_data['evento_titolo'], $data_formatted, $ora_formatted, $p_data['luogo'], $p_data['codice_prenotazione']];

        // Invia email
        $mail_ok = inviaNotificaReminder($p_data['email'], str_replace($r_find, $r_repl, $obj_tpl), str_replace($r_find, $r_repl, $body_tpl), $conn);
        
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
