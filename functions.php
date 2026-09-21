<?php
// functions.php - Contiene tutte le logiche condivise

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Utility: output escaping ──────────────────────────────────────────────────
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// ── Flash messages (sessione) ─────────────────────────────────────────────────
if (!function_exists('flash_set')) {
    function flash_set($msg, $type = 'success') {
        $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
    }
    function flash_get() {
        if (isset($_SESSION['_flash'])) {
            $f = $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $f;
        }
        return null;
    }
    function flash_html() {
        $f = flash_get();
        if (!$f) return '';
        $type = $f['type'];
        if ($type === 'danger')       { $cls = 'danger';  $icon = 'fa-times-circle'; }
        elseif ($type === 'warning')  { $cls = 'warning'; $icon = 'fa-exclamation-triangle'; }
        elseif ($type === 'info')     { $cls = 'info';    $icon = 'fa-info-circle'; }
        else                          { $cls = 'success'; $icon = 'fa-check-circle'; }
        return '<div class="alert alert-' . $cls . ' fw-bold text-center border-' . $cls
             . ' shadow-sm alert-dismissible fade show" role="alert">'
             . '<i class="fa ' . $icon . ' me-1"></i> '
             . h($f['msg'])
             . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

// ── Rate limiting (protezione endpoint da flooding) ───────────────────────────
if (!function_exists('check_rate_limit')) {
    /**
     * Verifica se l'IP corrente ha superato il limite di tentativi.
     * Ritorna true se la richiesta è permessa, false se bloccata.
     * L'IP viene hashato prima di salvarlo (privacy GDPR).
     */
    function check_rate_limit($conn, $endpoint, $max = 10, $window_sec = 300) {
        $ip_hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . $endpoint);

        // Crea tabella se non esiste
        $conn->query("CREATE TABLE IF NOT EXISTS rate_limit_attempts (
            id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash   CHAR(64)     NOT NULL,
            endpoint  VARCHAR(80)  NOT NULL,
            hit_at    DATETIME     NOT NULL,
            INDEX idx_ip_ep (ip_hash, endpoint),
            INDEX idx_hit  (hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Pulisce record vecchi (>1 ora) per tenere la tabella piccola
        $conn->query("DELETE FROM rate_limit_attempts WHERE hit_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        // Conta i tentativi nella finestra temporale corrente
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS hits FROM rate_limit_attempts
             WHERE ip_hash = ? AND endpoint = ? AND hit_at > DATE_SUB(NOW(), INTERVAL ? SECOND)"
        );
        $stmt->bind_param("ssi", $ip_hash, $endpoint, $window_sec);
        $stmt->execute();
        $hits = (int)$stmt->get_result()->fetch_assoc()['hits'];
        $stmt->close();

        if ($hits >= $max) {
            return false; // bloccato
        }

        // Registra questo tentativo
        $stmt2 = $conn->prepare("INSERT INTO rate_limit_attempts (ip_hash, endpoint, hit_at) VALUES (?, ?, NOW())");
        $stmt2->bind_param("ss", $ip_hash, $endpoint);
        $stmt2->execute();
        $stmt2->close();

        return true;
    }
}

// 1. GESTIONE AUTENTICAZIONE SSO UNIFICATA (Con Auto-Riparazione Email)
if (!function_exists('sync_sso_user')) {
    function sync_sso_user($conn) {
        if (!empty($_SESSION['utente_id'])) return true;

        $simplesaml_path = '/opt/simplesamlphp/lib/_autoload.php';
        if (!file_exists($simplesaml_path)) return false;

        try {
            require_once($simplesaml_path);
            $as = new \SimpleSAML\Auth\Simple('default-sp');
            if (!$as->isAuthenticated()) return false;

            $attributes = $as->getAttributes();
            $cf_saml = $attributes['codice_fiscale'][0] ?? null;

            if (!$cf_saml && !empty($attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0])) {
                $parts = explode(':', $attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0]);
                $cf_saml = end($parts);
            }

            if (!$cf_saml) return false;

            $cf_clean = strtoupper(trim($cf_saml));
            $stmt_cf = $conn->prepare("SELECT u.*, r.nome as ruolo_nome FROM utenti u LEFT JOIN ruoli r ON u.ruolo_id = r.id WHERE u.codice_fiscale = ? LIMIT 1");
            $stmt_cf->bind_param("s", $cf_clean);
            $stmt_cf->execute();
            $res_chk = $stmt_cf->get_result();

            if (!$res_chk || $res_chk->num_rows === 0) return false;

            $u_info = $res_chk->fetch_assoc();

            if (empty(trim($u_info['email'] ?? ''))) {
                $saml_email = strtolower(trim($attributes['mail'][0] ?? $attributes['email'][0] ?? ($cf_clean . '@studenti.unical.it')));
                $stmt_em = $conn->prepare("UPDATE utenti SET email = ? WHERE id = ?");
                $stmt_em->bind_param("si", $saml_email, $u_info['id']);
                $stmt_em->execute();
                $u_info['email'] = $saml_email;
            }

            session_regenerate_id(true);

            $_SESSION['utente_id']              = (int)$u_info['id'];
            $_SESSION['utente_cf']              = $u_info['codice_fiscale'];
            $_SESSION['utente_nome']            = trim($u_info['nome'] . ' ' . $u_info['cognome']);
            $_SESSION['utente_email']           = $u_info['email'];
            $_SESSION['utente_ruolo_id']        = (int)$u_info['ruolo_id'];
            $_SESSION['utente_ruoli_secondari'] = $u_info['ruoli_secondari'] ?? '';
            return true;

        } catch (\Throwable $e) {
            // Errore SimpleSAML: logga ma NON distruggere la sessione,
            // che potrebbe contenere dati validi scritti da saml_login.php.
            error_log('[SSO] sync_sso_user exception: ' . $e->getMessage());
            return false;
        }
    }
}

// 2. INVIO EMAIL UNIFICATO
if (!function_exists('inviaNotificaEmail')) {
    function inviaNotificaEmail($to, $subject, $body_html, $conn) {
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
}

// 3. HELPER DATE E CALENDARI (Ora Ripristinati!)
if (!function_exists('formattaDataItaliano')) {
    function formattaDataItaliano($data_str) {
        if (empty($data_str) || $data_str == '0000-00-00' || $data_str == '9999-12-31') return 'Date da definire';
        $giorni = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
        $mesi   = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        $timestamp = strtotime($data_str);
        return $giorni[date('w', $timestamp)] . ' <span class="text-danger fw-bold">' . date('j', $timestamp) . ' ' . ($mesi[date('n', $timestamp)] ?? '') . '</span>';
    }
}

if (!function_exists('getGoogleCalendarUrl')) {
    function getGoogleCalendarUrl($title, $data_turno, $ora_inizio, $ora_fine, $location, $details) {
        $st = date('Ymd\THis', strtotime($data_turno . ' ' . $ora_inizio));
        $et = date('Ymd\THis', strtotime($data_turno . ' ' . $ora_fine));
        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text=" . urlencode($title) . "&dates=" . $st . "/" . $et . "&details=" . urlencode($details) . "&location=" . urlencode($location);
    }
}

if (!function_exists('getPostiOccupati')) {
    function getPostiOccupati($conn, $turno_id) {
        // Aggiornato per conteggiare anche i posti in 'richiesta_conferma' come temporaneamente occupati
        $res = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as tot FROM prenotazioni WHERE turno_id = " . (int)$turno_id . " AND stato IN ('confermata', 'richiesta_conferma')");
        return ($res && $row = $res->fetch_assoc()) ? (int)$row['tot'] : 0;
    }
}

// =======================================================================
// MOTORE INTELLIGENTE LISTE D'ATTESA (NUOVO MODULO)
// =======================================================================
if (!function_exists('promuovi_lista_attesa')) {
    function promuovi_lista_attesa($conn, $turno_id) {
        $res_t = $conn->query("SELECT max_posti, data_turno, orario_inizio FROM turni WHERE id = " . (int)$turno_id . " LIMIT 1");
        if (!$res_t || $res_t->num_rows == 0) return;
        $turno = $res_t->fetch_assoc();
        
        // REGOLA: Se mancano meno di 24 ore all'evento, NON promuoviamo più nessuno.
        $inizio_evento = $turno['data_turno'] . ' ' . $turno['orario_inizio'];
        if (strtotime($inizio_evento) <= strtotime('+24 hours')) return;

        // Quanti posti liberi ci sono?
        $occ_res = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as tot FROM prenotazioni WHERE turno_id = $turno_id AND stato IN ('confermata', 'richiesta_conferma')");
        $occupati = $occ_res->fetch_assoc()['tot'];
        $posti_liberi = $turno['max_posti'] - $occupati;
        
        // Ciclo sicuro per promuovere utenti finché c'è spazio
        while ($posti_liberi > 0) {
            $res_promo = $conn->query("SELECT p.*, e.titolo as evento_titolo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE p.turno_id = $turno_id AND p.stato = 'in_attesa' ORDER BY p.data_prenotazione ASC LIMIT 1");
            
            if ($res_promo && $u_promo = $res_promo->fetch_assoc()) {
                if ($posti_liberi >= $u_promo['num_posti']) {
                    $id_promo = $u_promo['id'];
                    $scadenza = date('Y-m-d H:i:s', strtotime('+24 hours')); // +24 Ore esatte
                    
                    // Cambia stato e imposta timer
                    $update_ok = $conn->query("UPDATE prenotazioni SET stato = 'richiesta_conferma', scadenza_conferma = '$scadenza' WHERE id = $id_promo");
                    
                    if ($update_ok && $conn->affected_rows > 0) {
                        // Prepara e invia l'email
                        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                        $link_conferma = $proto . $domain . $base_dir . "/area_personale?conferma_posto=" . $id_promo;
                        
                        $obj_tpl = "Azione Richiesta: Si è liberato un posto per " . $u_promo['evento_titolo'];
                        $body_tpl = "<p>Ottime notizie <strong>" . htmlspecialchars($u_promo['nome']) . "</strong>!</p>
                                     <p>Si è appena liberato un posto per l'evento <strong>" . htmlspecialchars($u_promo['evento_titolo']) . "</strong>.</p>
                                     <div style='background-color:#fff3cd; color:#856404; padding:15px; border-left:5px solid #ffeeba; margin:20px 0;'>
                                       <strong>ATTENZIONE:</strong> Hai esattamente <strong>24 ore</strong> di tempo per confermare la tua presenza. Se non confermi entro il " . date('d/m/Y H:i', strtotime($scadenza)) . ", il posto verrà riassegnato allo studente successivo.
                                     </div>
                                     <p><a href='$link_conferma' style='background-color:#198754; color:white; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>CONFERMA IL MIO POSTO</a></p>";
                        
                        inviaNotificaEmail($u_promo['email'], $obj_tpl, $body_tpl, $conn);
                        
                        $posti_liberi -= $u_promo['num_posti']; // Sottrae i posti e continua il ciclo
                    } else {
                        break; // Se fallisce il database, ferma tutto in sicurezza
                    }
                } else {
                    break; // Il primo in lista d'attesa chiede più posti di quelli disponibili, ci fermiamo
                }
            } else {
                break; // Nessun altro in coda
            }
        }
    }
}

if (!function_exists('check_automazioni_sistema')) {
    function check_automazioni_sistema($conn) {
        $now = date('Y-m-d H:i:s');
        
        // 1. SCADENZA 24H: Annulla chi non ha confermato il link in tempo
        $sql_scadute = "SELECT id, turno_id FROM prenotazioni WHERE stato = 'richiesta_conferma' AND scadenza_conferma < '$now'";
        $res_scadute = $conn->query($sql_scadute);
        if ($res_scadute && $res_scadute->num_rows > 0) {
            while($row = $res_scadute->fetch_assoc()) {
                $pr_id = $row['id'];
                $t_id = $row['turno_id'];
                // Mettilo fuori gioco
                $conn->query("UPDATE prenotazioni SET stato = 'scaduta' WHERE id = $pr_id");
                // Pesca subito il prossimo fortunato per questo turno
                promuovi_lista_attesa($conn, $t_id);
            }
        }
        
        // 2. TAGLIOLA A -24H DALL'EVENTO: Chiudi e annulla definitivamente tutte le liste d'attesa pendenti
        $limite_chiusura = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $sql_chiusura = "SELECT id FROM turni WHERE CONCAT(data_turno, ' ', orario_inizio) <= '$limite_chiusura' AND CONCAT(data_turno, ' ', orario_inizio) > '$now'";
        $res_chiusura = $conn->query($sql_chiusura);
        if ($res_chiusura && $res_chiusura->num_rows > 0) {
            while($t = $res_chiusura->fetch_assoc()) {
                $t_id = $t['id'];
                $conn->query("UPDATE prenotazioni SET stato = 'scaduta' WHERE turno_id = $t_id AND stato IN ('in_attesa', 'richiesta_conferma')");
            }
        }
    }
}

// ESECUZIONE SILENTE (Sostituto del Cron Job)
// Esegue il controllo solo una volta ogni 5 minuti
if (!isset($_SESSION['last_cron_run']) || (time() - $_SESSION['last_cron_run']) > 300) {
    check_automazioni_sistema($conn);
    $_SESSION['last_cron_run'] = time();
}
// =======================================================================
// AUDIT LOG (Registrazione delle attività degli amministratori)
// =======================================================================
if (!function_exists('registra_log_audit')) {
    function registra_log_audit($conn, $azione, $dettagli_array = []) {
        if (empty($_SESSION['utente_id'])) return false;
        
        $u_id = (int)$_SESSION['utente_id'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Sconosciuto';
        $dettagli_json = !empty($dettagli_array) ? json_encode($dettagli_array, JSON_UNESCAPED_UNICODE) : null;
        $stmt = $conn->prepare("INSERT INTO log_attivita (utente_id, azione, dettagli_json, indirizzo_ip) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $u_id, $azione, $dettagli_json, $ip);
        return $stmt->execute();
    }
}

// =======================================================================
// PROTEZIONE CSRF (Fase 1 - Messa in Sicurezza Silenziosa)
// Un token per sessione, valido per tutti i form della sessione corrente.
// =======================================================================

// Restituisce il token corrente, generandolo se non esiste ancora
if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Stampa il campo hidden pronto da inserire in un <form>
if (!function_exists('csrf_field')) {
    function csrf_field() {
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

// Verifica il token ricevuto da un form POST (o GET per i link "azione" dell'admin).
// In caso di esito negativo interrompe l'esecuzione con errore 403.
if (!function_exists('csrf_verify')) {
    function csrf_verify($token_ricevuto) {
        if (empty($_SESSION['csrf_token']) || empty($token_ricevuto) || !hash_equals($_SESSION['csrf_token'], $token_ricevuto)) {
            http_response_code(403);
            die("Richiesta non valida o sessione scaduta. Torna indietro, ricarica la pagina e riprova.");
        }
        return true;
    }
}

if (!function_exists('secure_upload')) {
    function secure_upload(array $file, string $upload_dir, array $allowed_exts, array $allowed_mimes): ?string {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) return null;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed_mimes, true)) return null;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $upload_dir . $filename) ? $filename : null;
    }
}

// =======================================================================
// CACHE CONFIGURAZIONE PORTALE (Fase 3 - Ottimizzazione Prestazioni)
// header.php e footer.php interrogavano configurazione_portale ad OGNI
// caricamento pagina. Qui salviamo il risultato in un file JSON locale
// (cache/configurazione_portale.json), valido finché un admin non salva
// nuove impostazioni da testata.php (invalidazione esplicita) o comunque
// non oltre 5 minuti (rete di sicurezza, in caso di modifiche dirette a DB).
// Se la cartella cache/ non è scrivibile, la funzione ricade in modo
// trasparente sulla query diretta: nessun malfunzionamento, solo niente cache.
// =======================================================================
if (!function_exists('get_configurazione_portale')) {
    function get_configurazione_portale($conn) {
        // Memoizzazione per-request: evita anche solo di riaprire il file
        // se header.php e footer.php vengono eseguiti nella stessa request.
        static $cfg_memo = null;
        if ($cfg_memo !== null) {
            return $cfg_memo;
        }

        $cache_file = __DIR__ . '/cache/configurazione_portale.json';
        $ttl_secondi = 300;

        if (is_file($cache_file) && (time() - filemtime($cache_file)) < $ttl_secondi) {
            $json = @file_get_contents($cache_file);
            $decoded = ($json !== false) ? json_decode($json, true) : null;
            if (is_array($decoded)) {
                $cfg_memo = $decoded;
                return $cfg_memo;
            }
        }

        // Cache assente, scaduta o corrotta: rileggi dal database
        $cfg = [];
        if ($conn instanceof mysqli) {
            $res = @$conn->query("SELECT * FROM configurazione_portale WHERE id = 1");
            if ($res && $res->num_rows > 0) {
                $cfg = $res->fetch_assoc();
            }
        }

        // Riscrittura cache best-effort: se cache/ manca o non è scrivibile,
        // l'app continua a funzionare interrogando il DB ad ogni richiesta.
        $cache_dir = dirname($cache_file);
        if (!is_dir($cache_dir)) { @mkdir($cache_dir, 0755, true); }
        if (is_dir($cache_dir) && is_writable($cache_dir)) {
            @file_put_contents($cache_file, json_encode($cfg, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        $cfg_memo = $cfg;
        return $cfg_memo;
    }
}

// =======================================================================
// RICERCA GLOBALE
// =======================================================================
if (!function_exists('cerca_eventi')) {
    function cerca_eventi($conn, string $q): array {
        $q_like = '%' . $q . '%';
        $sql = "SELECT e.*,
                    pe.titolo as nome_area, pe.slug as slug_area, pe.colore_primario,
                    (SELECT MIN(data_turno) FROM turni WHERE evento_id = e.id AND data_turno >= CURDATE()) as prossima_data
                FROM eventi e
                JOIN pagine_eventi pe ON e.pagina_id = pe.id
                WHERE pe.visibile = 1
                  AND (e.titolo LIKE ? OR e.descrizione LIKE ? OR e.luogo LIKE ?)
                ORDER BY e.archiviato ASC, prossima_data ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $q_like, $q_like, $q_like);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

// Da chiamare subito dopo ogni UPDATE/INSERT su configurazione_portale
// (oggi solo in admin/testata.php), così le nuove impostazioni sono visibili
// immediatamente invece di aspettare la scadenza naturale della cache.
if (!function_exists('invalidate_configurazione_portale_cache')) {
    function invalidate_configurazione_portale_cache() {
        $cache_file = __DIR__ . '/cache/configurazione_portale.json';
        if (is_file($cache_file)) { @unlink($cache_file); }
    }
}
?>
