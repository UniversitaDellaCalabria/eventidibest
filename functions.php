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

        // Salviamo nome e ID sessione PRIMA di qualsiasi chiamata a SimpleSAML
        $our_session_name = session_name();
        $our_session_id   = session_id();

        try {
            require_once($simplesaml_path);
            $as = new \SimpleSAML\Auth\Simple('default-sp');
            if (!$as->isAuthenticated()) {
                // SimpleSAML può aver chiuso la sessione anche solo leggendo lo stato
                if (session_status() !== PHP_SESSION_ACTIVE && $our_session_id) {
                    session_name($our_session_name);
                    session_id($our_session_id);
                    session_start();
                }
                return false;
            }

            $attributes = $as->getAttributes();

            // Ripristina la nostra sessione PHP (pattern LibreBooking adSAML::Cleanup)
            \SimpleSAML\Session::getSessionFromRequest()->cleanup();

            // cleanup() può chiudere la sessione: la riapriamo esplicitamente
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_name($our_session_name);
                session_id($our_session_id);
                session_start();
            }

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

            $_SESSION['utente_id']              = (int)$u_info['id'];
            $_SESSION['utente_cf']              = $u_info['codice_fiscale'];
            $_SESSION['utente_nome']            = trim($u_info['nome'] . ' ' . $u_info['cognome']);
            $_SESSION['utente_email']           = $u_info['email'];
            $_SESSION['utente_ruolo_id']        = (int)$u_info['ruolo_id'];
            $_SESSION['utente_ruoli_secondari'] = $u_info['ruoli_secondari'] ?? '';

            if (empty($_SESSION['accesso_sso_loggato'])) {
                registra_accesso_sso($conn, (int)$u_info['id'], $u_info['email'], $u_info['nome'], $u_info['cognome'], 'sso');
                $_SESSION['accesso_sso_loggato'] = 1;
            }
            return true;

        } catch (\Throwable $e) {
            // Errore SimpleSAML: logga ma NON distruggere la sessione,
            // che potrebbe contenere dati validi scritti da saml_login.php.
            error_log('[SSO] sync_sso_user exception: ' . $e->getMessage());
            return false;
        }
    }
}

// 1b. LOG ACCESSI SSO
if (!function_exists('registra_accesso_sso')) {
    function registra_accesso_sso($conn, $utente_id, $email, $nome, $cognome, $tipo = 'sso') {
        @$conn->query("CREATE TABLE IF NOT EXISTS log_accessi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            utente_id INT DEFAULT NULL,
            email VARCHAR(255),
            nome VARCHAR(100),
            cognome VARCHAR(100),
            ip VARCHAR(45),
            user_agent VARCHAR(512),
            tipo VARCHAR(20) DEFAULT 'sso',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_uid (utente_id),
            INDEX idx_cat (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        $stmt = $conn->prepare("INSERT INTO log_accessi (utente_id, email, nome, cognome, ip, user_agent, tipo) VALUES (?,?,?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param("issssss", $utente_id, $email, $nome, $cognome, $ip, $ua, $tipo);
            $stmt->execute();
            $stmt->close();
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

// ── Attestato: invio immediato se evento concluso ─────────────────────────────
if (!function_exists('invia_email_attestato_se_concluso')) {
    function invia_email_attestato_se_concluso($conn, $pr_id) {
        $stmt = $conn->prepare(
            "SELECT p.id, p.nome, p.cognome, p.email, p.codice_prenotazione,
                    p.attestato_inviato, p.presente,
                    t.data_turno, t.orario_fine, e.titolo
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE p.id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $pr_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['presente'] != 1) return false;
        if (!empty($row['attestato_inviato'])) return false;
        if (empty($row['email'])) return false;

        $now = date('Y-m-d H:i:s');
        $dt_fine = $row['data_turno'] . ' ' . (!empty($row['orario_fine']) ? substr($row['orario_fine'], 0, 8) : '23:59:59');
        if ($now < $dt_fine) return false;

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'dibest2.unical.it';
        $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $func_root = realpath(__DIR__);
        $rel = ($doc_root && strpos($func_root, $doc_root) === 0) ? str_replace('\\', '/', substr($func_root, strlen($doc_root))) : '/eventi';
        $domain = $proto . $host . rtrim($rel, '/');

        $link_attestato = $domain . "/stampa_attestato.php?code=" . urlencode($row['codice_prenotazione']);
        $link_area = $domain . "/area_personale.php";
        $oggetto = "Il tuo Attestato è pronto: " . $row['titolo'];
        $corpo = "<p>Gentile <strong>" . htmlspecialchars($row['nome']) . " " . htmlspecialchars($row['cognome']) . "</strong>,</p>"
               . "<p>Grazie per aver partecipato all'evento <strong>" . htmlspecialchars($row['titolo']) . "</strong> del " . date('d/m/Y', strtotime($row['data_turno'])) . ".</p>"
               . "<p>Il tuo <strong>Attestato di Partecipazione</strong> è disponibile per il download.</p>"
               . "<p style='text-align:center; margin:30px 0;'>"
               . "<a href='" . $link_attestato . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px;'>📄 Scarica il tuo Attestato</a>"
               . "</p>"
               . "<p>In alternativa puoi recuperarlo dalla tua <a href='" . $link_area . "'>Area Personale</a>.</p>"
               . "<p>Cordiali saluti,<br>Il team Eventi DiBEST</p>";

        inviaNotificaEmail($row['email'], $oggetto, $corpo, $conn);
        $id_safe = (int)$pr_id;
        $conn->query("UPDATE prenotazioni SET attestato_inviato = 1 WHERE id = $id_safe");
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
// SONDAGGI
// =======================================================================
if (!function_exists('get_prenotazione_by_token_sondaggio')) {
    function get_prenotazione_by_token_sondaggio($conn, string $token): ?array {
        $stmt = $conn->prepare(
            "SELECT pr.*, t.evento_id, e.titolo as evento_titolo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.token_sondaggio = ? LIMIT 1"
        );
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('get_sondaggio_attivo')) {
    function get_sondaggio_attivo($conn, int $evento_id): ?array {
        $stmt = $conn->prepare("SELECT * FROM sondaggi WHERE evento_id = ? AND attivo = 1 LIMIT 1");
        $stmt->bind_param("i", $evento_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('get_domande_sondaggio')) {
    function get_domande_sondaggio($conn, int $sondaggio_id): array {
        $stmt = $conn->prepare("SELECT * FROM sondaggi_domande WHERE sondaggio_id = ? ORDER BY ordine ASC, id ASC");
        $stmt->bind_param("i", $sondaggio_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($d = $res->fetch_assoc()) { $rows[] = $d; } }
        return $rows;
    }
}

if (!function_exists('salva_risposte_sondaggio')) {
    function salva_risposte_sondaggio($conn, int $sond_id, array $risposte, int $pr_id): bool {
        $stmt_ins = $conn->prepare("INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES (?, ?, ?)");
        foreach ($risposte as $d_id => $valore) {
            $d_id_clean = (int)$d_id;
            $val = is_array($valore) ? json_encode($valore, JSON_UNESCAPED_UNICODE) : trim($valore);
            if ($val !== '' && $val !== '[]') {
                $stmt_ins->bind_param("iis", $sond_id, $d_id_clean, $val);
                $stmt_ins->execute();
            }
        }
        $stmt_upd = $conn->prepare("UPDATE prenotazioni SET sondaggio_completato = 1 WHERE id = ?");
        $stmt_upd->bind_param("i", $pr_id);
        return $stmt_upd->execute();
    }
}

// =======================================================================
// ARCHIVIO EVENTI
// =======================================================================
if (!function_exists('get_eventi_archivio')) {
    function get_eventi_archivio($conn, int $p_id): array {
        $stmt = $conn->prepare(
            "SELECT e.*, sc.nome as nome_sottocategoria,
             (SELECT YEAR(MIN(data_turno)) FROM turni WHERE evento_id = e.id) as anno_evento
             FROM eventi e
             LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id
             WHERE e.pagina_id = ? AND e.archiviato = 1
             ORDER BY anno_evento DESC, sc.ordine ASC, e.ordine ASC"
        );
        $stmt->bind_param("i", $p_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// FORM / CAMPI CUSTOM
// =======================================================================
if (!function_exists('get_campi_form')) {
    function get_campi_form($conn, int $evento_id): array {
        $stmt = $conn->prepare("SELECT * FROM campi_form WHERE evento_id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $evento_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// PRENOTAZIONI / RICEVUTA
// =======================================================================
if (!function_exists('get_attestato')) {
    function get_attestato($conn, string $code): ?array {
        $stmt = $conn->prepare(
            "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo, pe.firma_nome, pe.firma_titolo, pe.logo_attestato_path,
               cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva
            FROM prenotazioni pr
            JOIN turni t ON pr.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
            LEFT JOIN utenti u ON pr.utente_id = u.id
            JOIN configurazione_portale cp ON cp.id = 1
            WHERE pr.codice_prenotazione = ? LIMIT 1"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('get_prenotazione_ricevuta')) {
    function get_prenotazione_ricevuta($conn, string $code, int $id): ?array {
        $select = "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo,
               cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva
            FROM prenotazioni pr
            JOIN turni t ON pr.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
            LEFT JOIN utenti u ON pr.utente_id = u.id
            JOIN configurazione_portale cp ON cp.id = 1
            WHERE ";
        if ($id > 0) {
            $stmt = $conn->prepare($select . "pr.id = ? LIMIT 1");
            $stmt->bind_param("i", $id);
        } else {
            $stmt = $conn->prepare($select . "pr.codice_prenotazione = ? LIMIT 1");
            $stmt->bind_param("s", $code);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// PAGINE EVENTI
// =======================================================================
if (!function_exists('get_pagina_by_slug')) {
    function get_pagina_by_slug($conn, string $slug): ?array {
        $stmt = $conn->prepare("SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1");
        $stmt->bind_param("s", $slug);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// PAGINE EVENTI (HOME)
// =======================================================================
if (!function_exists('get_pagine_eventi_visibili')) {
    function get_pagine_eventi_visibili($conn): array {
        $res = $conn->query("SELECT * FROM pagine_eventi WHERE visibile = 1 ORDER BY ordine ASC, id ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// TURNI
// =======================================================================
if (!function_exists('get_turno_con_evento')) {
    function get_turno_con_evento($conn, int $turno_id): ?array {
        $stmt = $conn->prepare("SELECT t.*, e.titolo as evento_titolo, e.descrizione, e.luogo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? LIMIT 1");
        $stmt->bind_param("i", $turno_id);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// PROFILO UTENTE
// =======================================================================
if (!function_exists('aggiorna_email_utente')) {
    /**
     * Aggiorna l'email dell'utente e la marca come personalizzata.
     * Ritorna true in caso di successo, oppure una stringa di errore.
     */
    function aggiorna_email_utente($conn, int $u_id, string $nuova_email) {
        $stmt_chk = $conn->prepare("SELECT id FROM utenti WHERE LOWER(email) = ? AND id != ? LIMIT 1");
        $stmt_chk->bind_param("si", $nuova_email, $u_id);
        $stmt_chk->execute();
        if ($stmt_chk->get_result()->num_rows > 0) {
            return 'Questa email è già associata a un altro account.';
        }
        $stmt_upd = $conn->prepare("UPDATE utenti SET email = ?, email_personalizzata = 1 WHERE id = ?");
        $stmt_upd->bind_param("si", $nuova_email, $u_id);
        return $stmt_upd->execute() ? true : 'Errore durante il salvataggio. Riprova.';
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

// =======================================================================
// ADMIN — STATISTICHE
// =======================================================================
if (!function_exists('get_kpi_statistiche')) {
    function get_kpi_statistiche($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $kpi  = ['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'capienza' => 0];
        $res  = $conn->query(
            "SELECT
               SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
               SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
               SUM(CASE WHEN p.stato IN ('scaduta','rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac"
        );
        if ($res && $row = $res->fetch_assoc()) {
            $kpi['confermate'] = (int)$row['tot_confermate'];
            $kpi['attesa']     = (int)$row['tot_attesa'];
            $kpi['perse']      = (int)$row['tot_perse'];
        }
        $res_cap = $conn->query(
            "SELECT SUM(t.max_posti) as capienza_max
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac AND t.max_posti < 9000"
        );
        if ($res_cap && $row_cap = $res_cap->fetch_assoc()) {
            $kpi['capienza'] = (int)$row_cap['capienza_max'];
        }
        return $kpi;
    }
}

if (!function_exists('get_dati_grafico_eventi')) {
    function get_dati_grafico_eventi($conn, $p_id, $sql_filtro_rbac) {
        $p_id   = (int)$p_id;
        $nomi   = []; $occupati = []; $capienza = [];
        $res = $conn->query(
            "SELECT e.id, e.titolo,
               COALESCE((SELECT SUM(max_posti) FROM turni WHERE evento_id = e.id AND max_posti < 9000), 0) as cap_max
             FROM eventi e
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             ORDER BY e.id ASC"
        );
        if ($res) {
            while ($ev = $res->fetch_assoc()) {
                $ev_id   = $ev['id'];
                $res_occ = $conn->query(
                    "SELECT COALESCE(SUM(p.num_posti), 0) as occupati
                     FROM prenotazioni p JOIN turni t ON p.turno_id = t.id
                     WHERE t.evento_id = $ev_id AND p.stato IN ('confermata','richiesta_conferma')"
                );
                $occ = ($res_occ) ? (int)$res_occ->fetch_assoc()['occupati'] : 0;
                if ($occ > 0 || $ev['cap_max'] > 0) {
                    $titolo_corto = mb_strlen($ev['titolo']) > 25 ? mb_substr($ev['titolo'], 0, 22) . '...' : $ev['titolo'];
                    $nomi[]     = '"' . addslashes($titolo_corto) . '"';
                    $occupati[] = $occ;
                    $capienza[] = $ev['cap_max'];
                }
            }
        }
        return ['nomi' => $nomi, 'occupati' => $occupati, 'capienza' => $capienza];
    }
}

if (!function_exists('get_stats_turni')) {
    function get_stats_turni($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT e.titolo as evento_titolo, t.id as turno_id,
               t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             GROUP BY t.id
             ORDER BY t.data_turno ASC, t.orario_inizio ASC"
        );
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — LOOKUP (ruoli, sottocategorie)
// =======================================================================
if (!function_exists('get_sottocategorie')) {
    function get_sottocategorie($conn, $p_id) {
        $p_id = (int)$p_id;
        $res  = $conn->query("SELECT * FROM sottocategorie WHERE pagina_id = $p_id ORDER BY ordine ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

if (!function_exists('get_ruoli')) {
    function get_ruoli($conn) {
        $res  = $conn->query("SELECT * FROM ruoli ORDER BY id ASC");
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — CHECK-IN
// =======================================================================
if (!function_exists('get_prenotazione_per_checkin_admin')) {
    function get_prenotazione_per_checkin_admin($conn, string $code): ?array {
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.stato, pr.presente, pr.nome, pr.cognome,
               e.titolo as evento_titolo,
               e.gestori_utenti_ids as ev_gestori,
               e.permessi_gestori_json as ev_permessi_json,
               pe.gestore_utente_id as pg_gestore_singolo,
               pe.gestori_utenti_ids as pg_gestori,
               pe.permessi_gestori_json as pg_permessi_json
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE pr.codice_prenotazione = ? LIMIT 1"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $res = $stmt->get_result();
        return ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    }
}

// =======================================================================
// ADMIN — MESSAGGI
// =======================================================================
if (!function_exists('get_inbox_conversazioni')) {
    function get_inbox_conversazioni($conn, $p_id, $pr_filter_sql = '') {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT p.id as prenotazione_id, p.codice_prenotazione, p.nome, p.cognome, p.email,
               e.titolo as evento_titolo, e.pagina_id,
               MAX(m.data_invio) as ultimo_messaggio_data,
               COUNT(m.id) as totale_messaggi,
               SUM(CASE WHEN m.letto = 0 AND m.mittente_tipo = 'utente' THEN 1 ELSE 0 END) as messaggi_da_leggere
             FROM messaggi_prenotazioni m
             JOIN prenotazioni p ON m.prenotazione_id = p.id
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = $p_id $pr_filter_sql
             GROUP BY p.id
             ORDER BY messaggi_da_leggere DESC, ultimo_messaggio_data DESC"
        );
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

// =======================================================================
// ADMIN — ISCRITTI
// =======================================================================
if (!function_exists('get_prenotazione_con_turno_evento')) {
    function get_prenotazione_con_turno_evento($conn, $pr_id) {
        $pr_id = (int)$pr_id;
        $res = $conn->query(
            "SELECT pr.*, t.id as turno_id, t.data_turno, t.orario_inizio, t.orario_fine,
                    e.titolo as evento_titolo, e.luogo
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE pr.id = $pr_id LIMIT 1"
        );
        return ($res && $row = $res->fetch_assoc()) ? $row : null;
    }
}

if (!function_exists('get_destinatari_email_massiva')) {
    function get_destinatari_email_massiva($conn, $p_id, $turno_id = 0) {
        $p_id = (int)$p_id;
        $cond = $turno_id > 0 ? " AND t.id = " . (int)$turno_id : "";
        $res  = $conn->query(
            "SELECT pr.email, pr.nome, pr.cognome
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = $p_id
               AND IFNULL(pr.stato, 'confermata') = 'confermata'
               $cond
               AND pr.email != ''"
        );
        $dest = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $dest[] = $row; } }
        return $dest;
    }
}

if (!function_exists('get_turno_admin')) {
    function get_turno_admin($conn, $turno_id) {
        $turno_id = (int)$turno_id;
        $res = $conn->query(
            "SELECT t.*, e.titolo as evento_titolo, pe.slug
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE t.id = $turno_id LIMIT 1"
        );
        return ($res && $row = $res->fetch_assoc()) ? $row : null;
    }
}

if (!function_exists('get_campi_custom_export')) {
    function get_campi_custom_export($conn, $p_id) {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT DISTINCT nome_campo, etichetta FROM campi_form
             WHERE pagina_id = $p_id OR evento_id IN (SELECT id FROM eventi WHERE pagina_id = $p_id)
             ORDER BY id ASC"
        );
        $cols = [];
        if ($res) { while ($cf = $res->fetch_assoc()) { $cols[$cf['nome_campo']] = $cf['etichetta']; } }
        return $cols;
    }
}

if (!function_exists('get_eventi_con_turni_admin')) {
    function get_eventi_con_turni_admin($conn, $p_id, $is_archivio, $sql_filtro_rbac) {
        $p_id        = (int)$p_id;
        $is_archivio = (int)$is_archivio;
        $eventi = [];
        $res = $conn->query(
            "SELECT id, titolo FROM eventi e
             WHERE e.pagina_id = $p_id AND e.archiviato = $is_archivio $sql_filtro_rbac
             ORDER BY e.ordine ASC, e.id DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $turni = [];
                $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$row['id']} ORDER BY data_turno ASC, orario_inizio ASC");
                if ($res_t) { while ($t = $res_t->fetch_assoc()) { $turni[] = $t; } }
                $row['turni'] = $turni;
                $eventi[] = $row;
            }
        }
        return $eventi;
    }
}

if (!function_exists('get_messaggi_per_prenotazioni')) {
    function get_messaggi_per_prenotazioni($conn, array $pr_ids) {
        if (empty($pr_ids)) { return []; }
        $ids_str = implode(',', array_map('intval', $pr_ids));
        $res = $conn->query("SELECT * FROM messaggi_prenotazioni WHERE prenotazione_id IN ($ids_str) ORDER BY data_invio ASC");
        $messaggi = [];
        if ($res) { while ($m = $res->fetch_assoc()) { $messaggi[$m['prenotazione_id']][] = $m; } }
        return $messaggi;
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
