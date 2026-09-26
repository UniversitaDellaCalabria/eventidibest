<?php
// cron_attestati.php - Motore invio email automatiche per attestati
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';
require_once 'functions.php';
consenti_esecuzione_cron([1, 2]); // anche i gestori: pulsante "Attestati" in Iscritti

// FASE 3: MUTEX LOCK PER PREVENIRE ESECUZIONI SOVRAPPOSTE E INVIO DOPPIO
$cache_dir = __DIR__ . '/cache';
if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }

// Se la cartella cache/ non esiste e non è stato possibile crearla (permessi), è un problema
// di configurazione del server, non un "processo già in esecuzione": lo segnaliamo in modo chiaro.
if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
    die("ERRORE DI CONFIGURAZIONE: la cartella 'cache/' non esiste o non è scrivibile dal server web (" . htmlspecialchars($cache_dir) . "). Crearla manualmente con permessi 755 e riprovare.\n");
}

$lock_file = $cache_dir . '/cron_attestati.lock';

// Anti lock-orfano: se il file di lock esiste da più di 10 minuti, lo consideriamo
// residuo di un'esecuzione precedente interrotta in modo anomalo e lo rimuoviamo.
if (file_exists($lock_file) && (time() - filemtime($lock_file)) > 600) {
    @unlink($lock_file);
}

$lock_handle = fopen($lock_file, 'w+');

// LOCK_EX = Lock esclusivo | LOCK_NB = Non bloccante (se è già in uso, fallisce subito invece di accodarsi)
if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
    die("PROCESSO IN ESECUZIONE: Lo script cron_attestati.php è già in esecuzione in un altro processo (avviato meno di 10 minuti fa). Se il problema persiste oltre 10 minuti, il lock verrà rilasciato automaticamente al prossimo tentativo.\n");
}


$now = date('Y-m-d H:i:s');
$email_inviate = 0;

// Cerchiamo chi deve ricevere l'email (Evento finito, Presente, Mai inviata prima)
$sql = "SELECT p.id, p.turno_id, p.nome, p.cognome, p.email, p.codice_prenotazione, 
               t.data_turno, e.titolo 
        FROM prenotazioni p
        JOIN turni t ON p.turno_id = t.id
        JOIN eventi e ON t.evento_id = e.id
        WHERE p.stato = 'confermata' 
          AND p.presente = 1 
          AND p.attestato_inviato = 0 
          AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) <= '$now')";

$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    
    while ($row = $res->fetch_assoc()) {
        $oggetto = "Il tuo Attestato è pronto: " . $row['titolo'];
        $link_attestato = $domain . "/stampa_attestato.php?code=" . urlencode($row['codice_prenotazione']);
        $link_area = $domain . "/area_personale.php";
        
        $corpo = "<p>Gentile <strong>{$row['nome']} {$row['cognome']}</strong>,</p>";
        $corpo .= "<p>Grazie per aver partecipato all'evento <strong>" . htmlspecialchars($row['titolo']) . "</strong>" . (!empty($row['data_turno']) ? " del " . date('d/m/Y', strtotime($row['data_turno'])) : "") . ".</p>";
        $corpo .= "<p>Il tuo <strong>Attestato di Partecipazione</strong> è stato generato ed è ora disponibile per il download.</p>";
        $corpo .= "<p style='text-align: center; margin: 30px 0;'>
                    <a href='$link_attestato' style='background-color: #198754; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;'>📄 Scarica il tuo Attestato</a>
                  </p>";
        $corpo .= "<p>In alternativa, puoi sempre recuperarlo accedendo alla tua <a href='$link_area'>Area Personale</a>.</p>";
        $corpo .= "<p>Cordiali saluti,<br>Il team Eventi DiBEST</p>";
        
        inviaNotificaEmail($row['email'], $oggetto, $corpo, $conn, colore_area_turno($conn, $row['turno_id']));
        
        // FASE 1: Patch Iniezione SQL con casting a Intero
        $p_id = (int)$row['id'];
        $conn->query("UPDATE prenotazioni SET attestato_inviato = 1 WHERE id = $p_id");
        $email_inviate++;
    }
}

// Rilascio del lock (viene eseguito anche dal sistema alla chiusura del file)
flock($lock_handle, LOCK_UN);
fclose($lock_handle);

// Se l'admin ha cliccato il bottone, lo rimandiamo indietro con un messaggio
if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'admin') !== false) {
    flash_set("Missione compiuta! Sono state inviate $email_inviate nuove email di attestato agli studenti.");
    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

// Se invece viene avviato dal server in automatico, stampa a video
echo "Elaborazione completata. Email inviate: $email_inviate\n";
?>
