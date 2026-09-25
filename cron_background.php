<?php
// cron_background.php - Motore Automazioni (Da richiamare ogni 15 minuti tramite crontab Ubuntu)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// =========================================================================
// FASE 3: MUTEX LOCK PER PREVENIRE ESECUZIONI SOVRAPPOSTE (stesso pattern già
// in uso in cron_attestati.php). Se il crontab lancia una nuova esecuzione
// mentre la precedente sta ancora girando (es. invio email lento, tanti
// destinatari), qui evitiamo doppio invio delle email post-evento.
// =========================================================================
$cache_dir = __DIR__ . '/cache';
if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }

if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
    die("ERRORE DI CONFIGURAZIONE: la cartella 'cache/' non esiste o non è scrivibile dal server web (" . $cache_dir . "). Crearla manualmente con permessi 755 e riprovare.\n");
}

$lock_file_bg = $cache_dir . '/cron_background.lock';

// Anti lock-orfano: se il lock esiste da più di 10 minuti lo consideriamo residuo
// di un'esecuzione precedente interrotta in modo anomalo e lo rimuoviamo.
if (file_exists($lock_file_bg) && (time() - filemtime($lock_file_bg)) > 600) {
    @unlink($lock_file_bg);
}

$lock_handle_bg = fopen($lock_file_bg, 'w+');

// LOCK_EX = Lock esclusivo | LOCK_NB = Non bloccante (se già in uso, fallisce subito)
if (!$lock_handle_bg || !flock($lock_handle_bg, LOCK_EX | LOCK_NB)) {
    die("PROCESSO IN ESECUZIONE: cron_background.php è già in esecuzione in un altro processo (avviato meno di 10 minuti fa).\n");
}

// AUTO-PATCH colonne che potrebbero mancare nei DB più vecchi
$conn->query("ALTER TABLE prenotazioni ADD COLUMN IF NOT EXISTS email_post_evento_inviata TINYINT(1) DEFAULT 0");

$now = date('Y-m-d H:i:s');
$sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

echo "Inizio Esecuzione CRON: $now\n";

// =========================================================================
// TASK 0: AUTO-ARCHIVIAZIONE EVENTI SCADUTI
// =========================================================================
$conn->query("UPDATE eventi e SET e.archiviato = 1 WHERE e.archiviato = 0 AND (e.blocca_auto_archivio IS NULL OR e.blocca_auto_archivio = 0) AND (SELECT MAX(data_turno) FROM turni t WHERE t.evento_id = e.id) < CURDATE() AND NOT EXISTS (SELECT 1 FROM turni t2 WHERE t2.evento_id = e.id AND t2.data_turno IS NULL)");
echo "- Auto-archiviati " . $conn->affected_rows . " eventi scaduti.\n";

// =========================================================================
// TASK 1: EMAIL POST-EVENTO (Attestati e Sondaggi)
// =========================================================================
// Seleziona i presenti a eventi finiti, a cui NON è ancora stata mandata l'email
$sql_post = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.presente = 1
             AND pr.email_post_evento_inviata = 0
             AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) < '$now')";

$res_post = $conn->query($sql_post);
$count_post = 0;

if ($res_post && $res_post->num_rows > 0) {
    // Calcoliamo l'URL di base dinamicamente (puoi anche hardcodarlo se preferisci)
    $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'il-tuo-sito.unical.it') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $link_area = "<a href='$domain/area_personale.php' style='color:#B30000; font-weight:bold;'>Area Personale</a>";

    while ($p = $res_post->fetch_assoc()) {
        $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{LINK_AREA_PERSONALE}'];
        $ora_f = orario_turno($p) ?: 'da definire';
        $data_f = implode(' · ', array_filter([$p['nome_turno'] ?? '', !empty($p['data_turno']) ? date('d/m/Y', strtotime($p['data_turno'])) : '']));
        $r_repl = [$p['nome'], $p['cognome'], $p['matricola'], $p['evento_titolo'], $data_f, $ora_f, $p['luogo'], $link_area];

        // 1. Invia Avviso Attestato Disponibile (se configurato)
        if (!empty($sys['email_attestato_corpo'])) {
            inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $sys['email_attestato_oggetto']), str_replace($r_find, $r_repl, $sys['email_attestato_corpo']), $conn);
        }
        // 2. Invia Sondaggio (se configurato)
        if (!empty($sys['email_sondaggio_corpo'])) {
            inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $sys['email_sondaggio_oggetto']), str_replace($r_find, $r_repl, $sys['email_sondaggio_corpo']), $conn);
        }

        // Segna come inviata per non spammare l'utente al prossimo giro di Cron
        $conn->query("UPDATE prenotazioni SET email_post_evento_inviata = 1 WHERE id = {$p['id']}");
        $count_post++;
    }
}
echo "- Inviate $count_post email post-evento (Attestati/Sondaggi).\n";

// =========================================================================
// TASK 2: PROMEMORIA PRE-EVENTO E ALTRE AUTOMAZIONI
// =========================================================================
$file_reminders = __DIR__ . '/admin/cron_reminders.php';
if (file_exists($file_reminders)) {
    ob_start();
    include $file_reminders;
    ob_end_clean();
    echo "- Promemoria (admin/cron_reminders.php) eseguiti.\n";
}

echo "Esecuzione CRON terminata con successo.\n";

// Rilascio esplicito del lock (viene comunque rilasciato dal sistema alla chiusura dello script)
flock($lock_handle_bg, LOCK_UN);
fclose($lock_handle_bg);
?>
