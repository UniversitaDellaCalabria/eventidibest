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

            // Email mancante in DB: la recupera dall'IdP (mai inventare un indirizzo: le notifiche finirebbero nel vuoto)
            if (empty(trim($u_info['email'] ?? ''))) {
                $saml_email = estrai_email_saml($attributes, tipo_utente_saml((string)($u_info['matricola_studente'] ?? ''), (string)($u_info['matricola_dipendente'] ?? '')));
                if ($saml_email !== '') {
                    $stmt_em = $conn->prepare("UPDATE utenti SET email = ? WHERE id = ?");
                    $stmt_em->bind_param("si", $saml_email, $u_info['id']);
                    $stmt_em->execute();
                    $u_info['email'] = $saml_email;
                }
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

// 1a. EMAIL DAGLI ATTRIBUTI SAML
if (!function_exists('estrai_email_saml')) {
    // L'IdP può inviare l'email col nome breve (mail/email) o in formato OID (urn:oid:0.9.2342.19200300.100.1.3),
    // anche con più valori. Sceglie l'indirizzo in base al tipo di utente:
    //   'studente'   → @studenti.unical.it
    //   'dipendente' → @unical.it
    //   'esterno'    → email personale (SPID/CIE), cioè non di Ateneo
    // Se l'indirizzo preferito non c'è, usa il primo valido. Restituisce '' se non ne trova.
    function estrai_email_saml(array $attributes, string $tipo = 'esterno'): string {
        $chiavi = ['mail', 'email', 'Email', 'emailAddress', 'urn:oid:0.9.2342.19200300.100.1.3',
                   'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'];
        $candidate = [];
        foreach ($chiavi as $k) {
            foreach ((array)($attributes[$k] ?? []) as $v) {
                $v = strtolower(trim((string)$v));
                if (filter_var($v, FILTER_VALIDATE_EMAIL) && !in_array($v, $candidate, true)) $candidate[] = $v;
            }
        }
        if (empty($candidate)) {
            // Logga i NOMI degli attributi ricevuti (non i valori) per capire cosa manda l'IdP
            error_log('[SSO] Email non trovata negli attributi SAML. Attributi ricevuti: ' . implode(', ', array_keys($attributes)));
            return '';
        }
        $is_studenti = fn($e) => substr($e, -strlen('@studenti.unical.it')) === '@studenti.unical.it';
        $is_unical   = fn($e) => substr($e, -strlen('@unical.it')) === '@unical.it';
        $filtri = [
            'studente'   => $is_studenti,
            'dipendente' => $is_unical,
            'esterno'    => fn($e) => !$is_studenti($e) && !$is_unical($e),
        ];
        foreach ($candidate as $e) { if (($filtri[$tipo] ?? $filtri['esterno'])($e)) return $e; }
        return $candidate[0];
    }
}

if (!function_exists('tipo_utente_saml')) {
    // Stessa priorità del ruolo di default in saml_login.php: matricola studente > matricola dipendente > esterno (SPID/CIE)
    function tipo_utente_saml(string $matr_stud, string $matr_dip): string {
        if (trim($matr_stud) !== '') return 'studente';
        if (trim($matr_dip) !== '')  return 'dipendente';
        return 'esterno';
    }
}

// 1b. LOG ACCESSI SSO
if (!function_exists('registra_accesso_sso')) {
    function registra_accesso_sso($conn, $utente_id, $email, $nome, $cognome, $tipo = 'sso') {
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
// Ultimo errore di invio (mostrato dal pulsante "Email di prova" in admin/sistema.php)
$GLOBALS['ultimo_errore_email'] = '';

if (!function_exists('registra_log_email')) {
    // Traccia ogni invio in log_email: serve a capire quali mail partono e quali vengono rifiutate dal server SMTP.
    function registra_log_email($conn, string $to, string $subject, bool $ok, string $errore = '', string $canale = 'smtp') {
        $stmt = @$conn->prepare("INSERT INTO log_email (destinatario, oggetto, esito, canale, errore) VALUES (?,?,?,?,?)");
        if ($stmt) {
            $to = mb_substr($to, 0, 255); $subject = mb_substr($subject, 0, 255); $errore = mb_substr($errore, 0, 500);
            $esito = $ok ? 1 : 0;
            $stmt->bind_param("ssiss", $to, $subject, $esito, $canale, $errore);
            @$stmt->execute();
            $stmt->close();
        }
        if (!$ok) error_log("[Email] Invio fallito a $to ($canale): $errore");
    }
}

if (!function_exists('smtp_risposta')) {
    // Legge una risposta SMTP completa (anche multi-riga "250-...") e ne restituisce [codice, testo].
    function smtp_risposta($socket): array {
        $testo = '';
        while (($riga = fgets($socket, 515)) !== false) {
            $testo .= $riga;
            if (strlen($riga) < 4 || $riga[3] !== '-') break;
        }
        return [(int)substr($testo, 0, 3), trim($testo)];
    }
}

if (!function_exists('smtp_comando')) {
    // Invia un comando (null = solo lettura) e verifica il codice di risposta; eccezione se il server rifiuta.
    function smtp_comando($socket, ?string $cmd, array $codici_ok, string $fase): string {
        if ($cmd !== null) fwrite($socket, $cmd . "\r\n");
        [$codice, $testo] = smtp_risposta($socket);
        if (!in_array($codice, $codici_ok, true)) {
            throw new RuntimeException("$fase: " . ($testo !== '' ? $testo : 'nessuna risposta dal server'));
        }
        return $testo;
    }
}

if (!function_exists('inviaNotificaEmail')) {
    // $colore: colore dell'area (es. colore_area_turno()); null = rosso istituzionale
    function inviaNotificaEmail($to, $subject, $body_html, $conn, $colore = null) {
        $GLOBALS['ultimo_errore_email'] = '';
        $to = trim((string)$to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $GLOBALS['ultimo_errore_email'] = 'Indirizzo destinatario non valido';
            return false;
        }

        $res_sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1");
        $sys = $res_sys ? $res_sys->fetch_assoc() : null;
        if (!$sys) {
            $GLOBALS['ultimo_errore_email'] = 'Impostazioni di sistema mancanti';
            registra_log_email($conn, $to, (string)$subject, false, $GLOBALS['ultimo_errore_email'], '-');
            return false;
        }

        $host   = trim($sys['smtp_host'] ?? '');
        $port   = (int)($sys['smtp_port'] ?? 587);
        $user   = $sys['smtp_username'] ?? '';
        $pass   = $sys['smtp_password'] ?? '';
        $from_e = !empty($sys['smtp_from_email']) ? trim($sys['smtp_from_email']) : 'noreply.eventi@unical.it';
        $from_n = !empty($sys['smtp_from_name']) ? $sys['smtp_from_name'] : 'Eventi DiBEST';
        $secure = strtolower($sys['smtp_secure'] ?? 'tls');

        $body_html = impagina_email((string)$body_html, $from_n, $colore);

        $dominio_from = substr(strrchr($from_e, '@') ?: '@unical.it', 1);
        $headers  = "Date: " . date('r') . "\r\n";
        $headers .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . $dominio_from . ">\r\n";
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n";
        $from_hdr = "From: =?UTF-8?B?" . base64_encode($from_n) . "?= <$from_e>\r\n";
        // base64 a righe da 76 caratteri: niente righe oltre il limite SMTP (998) e niente troncamenti su righe che iniziano con "."
        $body_b64 = chunk_split(base64_encode((string)$body_html), 76, "\r\n");
        $subject_enc = "=?UTF-8?B?" . base64_encode((string)$subject) . "?=";

        $invia_con_mail = function (string $motivo) use ($to, $subject, $subject_enc, $headers, $from_hdr, $body_b64, $conn) {
            $ok = @mail($to, $subject_enc, $body_b64, $from_hdr . $headers);
            $GLOBALS['ultimo_errore_email'] = $ok ? '' : "$motivo; anche la funzione mail() di PHP ha fallito";
            registra_log_email($conn, $to, (string)$subject, $ok, $ok ? $motivo : $GLOBALS['ultimo_errore_email'], 'mail()');
            return $ok;
        };

        if ($host === '') return $invia_con_mail('Host SMTP non configurato');

        $transport = ($secure === 'ssl') ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 10);
        if (!$socket) return $invia_con_mail("Connessione SMTP a $host:$port fallita ($errstr)");
        stream_set_timeout($socket, 15);

        try {
            $ehlo_host = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: 'localhost');
            smtp_comando($socket, null, [220], 'Benvenuto server');
            $caps = smtp_comando($socket, "EHLO $ehlo_host", [250], 'EHLO');

            if ($secure === 'tls') {
                smtp_comando($socket, "STARTTLS", [220], 'STARTTLS');
                $metodo = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($socket, true, $metodo)) throw new RuntimeException('Negoziazione TLS fallita');
                $caps = smtp_comando($socket, "EHLO $ehlo_host", [250], 'EHLO dopo TLS');
            }

            if ($user !== '' && $pass !== '') {
                if (stripos($caps, 'AUTH') === false) throw new RuntimeException('Il server non accetta autenticazione (AUTH) con questa porta/cifratura');
                smtp_comando($socket, "AUTH LOGIN", [334], 'AUTH LOGIN');
                smtp_comando($socket, base64_encode($user), [334], 'AUTH username');
                smtp_comando($socket, base64_encode($pass), [235], 'Autenticazione (credenziali errate?)');
            }

            smtp_comando($socket, "MAIL FROM:<$from_e>", [250], 'Mittente rifiutato');
            smtp_comando($socket, "RCPT TO:<$to>", [250, 251], 'Destinatario rifiutato');
            smtp_comando($socket, "DATA", [354], 'DATA');
            fwrite($socket, $from_hdr . "To: <$to>\r\nSubject: $subject_enc\r\n" . $headers . "\r\n" . $body_b64 . "\r\n.\r\n");
            smtp_comando($socket, null, [250], 'Messaggio rifiutato');
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            registra_log_email($conn, $to, (string)$subject, true);
            return true;
        } catch (Throwable $e) {
            @fwrite($socket, "QUIT\r\n");
            @fclose($socket);
            $GLOBALS['ultimo_errore_email'] = $e->getMessage();
            registra_log_email($conn, $to, (string)$subject, false, $e->getMessage());
            return false;
        }
    }
}

// ── Destinatari notifiche gestori ────────────────────────────────────────────
if (!function_exists('ids_gestori_da_campi')) {
    // Unisce gli ID gestore dai tre formati presenti nel DB: campo singolo, CSV legacy, JSON permessi (chiavi = ID utente).
    // admin/abilitazioni.php oggi salva SOLO nel JSON: leggere solo il CSV fa perdere i gestori.
    function ids_gestori_da_campi($singolo, $csv, $json): array {
        $ids = [];
        if ((int)$singolo > 0) $ids[] = (int)$singolo;
        foreach (explode(',', (string)$csv) as $v) { if ((int)trim($v) > 0) $ids[] = (int)trim($v); }
        $perm = json_decode((string)$json ?: '{}', true);
        if (is_array($perm)) { foreach (array_keys($perm) as $k) { if ((int)$k > 0) $ids[] = (int)$k; } }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('get_gestori_ids_area')) {
    // Tutti i gestori di un'area: quelli dell'intera area + quelli assegnati ai singoli eventi.
    function get_gestori_ids_area($conn, int $pagina_id): array {
        $ids = [];
        $res = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = $pagina_id LIMIT 1");
        if ($res && $r = $res->fetch_assoc()) $ids = ids_gestori_da_campi($r['gestore_utente_id'], $r['gestori_utenti_ids'], $r['permessi_gestori_json']);
        $res = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = $pagina_id");
        if ($res) while ($r = $res->fetch_assoc()) $ids = array_merge($ids, ids_gestori_da_campi(0, $r['gestori_utenti_ids'], $r['permessi_gestori_json']));
        return array_values(array_unique($ids));
    }
}

if (!function_exists('get_notifiche_gestori_attive')) {
    // ID dei gestori che ricevono le email sulle prenotazioni dell'area.
    // null = mai configurato dall'admin → le ricevono tutti i gestori.
    function get_notifiche_gestori_attive($conn, int $pagina_id): ?array {
        $res = @$conn->query("SELECT notifiche_gestori_ids FROM pagine_eventi WHERE id = $pagina_id LIMIT 1");
        $r = $res ? $res->fetch_assoc() : null;
        if (!$r || $r['notifiche_gestori_ids'] === null) return null;
        return array_values(array_filter(array_map('intval', explode(',', $r['notifiche_gestori_ids']))));
    }
}

if (!function_exists('set_notifica_gestore')) {
    function set_notifica_gestore($conn, int $pagina_id, int $utente_id, bool $attiva) {
        $attivi = get_notifiche_gestori_attive($conn, $pagina_id) ?? get_gestori_ids_area($conn, $pagina_id);
        $attivi = $attiva ? array_merge($attivi, [$utente_id]) : array_diff($attivi, [$utente_id]);
        $csv = implode(',', array_unique(array_map('intval', $attivi)));
        $stmt = $conn->prepare("UPDATE pagine_eventi SET notifiche_gestori_ids = ? WHERE id = ?");
        $stmt->bind_param("si", $csv, $pagina_id);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('get_email_gestori_evento')) {
    // Email dei gestori da avvisare per un evento: gestori dell'intera area + gestori del singolo evento.
    // $solo_notifiche_attive = true applica l'interruttore "Notifiche prenotazioni" di admin/abilitazioni.php.
    function get_email_gestori_evento($conn, int $evento_id, bool $solo_notifiche_attive = true): array {
        $res = $conn->query("SELECT e.pagina_id, e.gestori_utenti_ids AS ev_csv, e.permessi_gestori_json AS ev_json,
                                    pe.gestore_utente_id, pe.gestori_utenti_ids AS p_csv, pe.permessi_gestori_json AS p_json
                             FROM eventi e JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE e.id = $evento_id LIMIT 1");
        $r = $res ? $res->fetch_assoc() : null;
        if (!$r) return [];
        $ids = array_unique(array_merge(
            ids_gestori_da_campi($r['gestore_utente_id'], $r['p_csv'], $r['p_json']),
            ids_gestori_da_campi(0, $r['ev_csv'], $r['ev_json'])
        ));
        if ($solo_notifiche_attive) {
            $attivi = get_notifiche_gestori_attive($conn, (int)$r['pagina_id']);
            if ($attivi !== null) $ids = array_intersect($ids, $attivi);
        }
        if (empty($ids)) return [];
        $emails = [];
        $res_u = $conn->query("SELECT DISTINCT email FROM utenti WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") AND email IS NOT NULL AND email != ''");
        if ($res_u) while ($u = $res_u->fetch_assoc()) $emails[] = $u['email'];
        return $emails;
    }
}

// ── Attestato: invio immediato se evento concluso ─────────────────────────────
if (!function_exists('url_base_sito')) {
    // URL della radice del portale (es. https://dibest2.unical.it/eventi), senza slash finale.
    // Calcolato dalla posizione di functions.php: corretto anche se chiamato da /admin o da un cron.
    function url_base_sito(): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'dibest2.unical.it';
        $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '';
        $func_root = realpath(__DIR__);
        $rel = ($doc_root && strpos($func_root, $doc_root) === 0) ? str_replace('\\', '/', substr($func_root, strlen($doc_root))) : '/eventi';
        return $proto . $host . rtrim($rel, '/');
    }
}

// =======================================================================
// COLORE DELL'AREA (card, badge, email)
// =======================================================================
if (!function_exists('colore_valido')) {
    // Colore #RRGGBB sicuro da stampare negli attributi style; altrimenti il default.
    function colore_valido($hex, string $default = '#B30000'): string {
        $hex = trim((string)$hex);
        if (preg_match('/^#[0-9a-f]{6}$/i', $hex)) return strtoupper($hex);
        if (preg_match('/^#[0-9a-f]{3}$/i', $hex)) return strtoupper('#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3]);
        return $default;
    }
}

if (!function_exists('colore_testo_su')) {
    // Colore del testo leggibile su uno sfondo (regola di contrasto WCAG):
    // bianco o grigio quasi nero, quello con il contrasto più alto.
    function colore_testo_su($hex_sfondo): string {
        $h = ltrim(colore_valido($hex_sfondo), '#');
        $lin = function (int $c): float { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
        $L = 0.2126 * $lin(hexdec(substr($h, 0, 2))) + 0.7152 * $lin(hexdec(substr($h, 2, 2))) + 0.0722 * $lin(hexdec(substr($h, 4, 2)));
        $contrasto_bianco = 1.05 / ($L + 0.05);
        $contrasto_scuro  = ($L + 0.05) / (0.0216 + 0.05); // 0.0216 = luminanza di #1F2937
        return $contrasto_bianco >= $contrasto_scuro ? '#FFFFFF' : '#1F2937';
    }
}

if (!function_exists('colore_area_turno')) {
    // Colore primario dell'area a cui appartiene un turno (memorizzato per la richiesta).
    function colore_area_turno($conn, $turno_id): string {
        static $cache = [];
        $turno_id = (int)$turno_id;
        if (!isset($cache[$turno_id])) {
            $res = $conn->query("SELECT pe.colore_primario FROM turni t JOIN eventi e ON t.evento_id = e.id
                                 JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE t.id = $turno_id LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            $cache[$turno_id] = colore_valido($row['colore_primario'] ?? '');
        }
        return $cache[$turno_id];
    }
}

if (!function_exists('impagina_email')) {
    // Impaginazione comune delle email: intestazione con il colore dell'area, corpo, piè di pagina.
    // I pulsanti col rosso istituzionale nel corpo prendono il colore dell'area.
    // Se il corpo è già un documento HTML completo (template personalizzato) resta com'è.
    function impagina_email(string $corpo, string $titolo, ?string $colore = null): string {
        if (stripos($corpo, '<html') !== false || stripos($corpo, '<body') !== false) return $corpo;
        $col   = colore_valido($colore ?? '');
        $testo = colore_testo_su($col);
        if ($colore !== null) {
            // Pulsanti "sfondo rosso + testo bianco": sfondo dell'area e testo a contrasto
            $corpo = preg_replace('/background(-color)?\s*:\s*#B[38]0000\s*;\s*color\s*:\s*(#fff(fff)?|white)/i',
                                  'background$1:' . $col . '; color:' . $testo, $corpo);
            $corpo = str_ireplace(['#B30000', '#B80000'], $col, $corpo);
        }
        return '<div style="background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">'
             . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">'
             . '<tr><td style="background:' . $col . ';color:' . $testo . ';padding:16px 24px;font-size:18px;font-weight:bold;">' . htmlspecialchars($titolo) . '</td></tr>'
             . '<tr><td style="padding:24px;color:#1f2937;font-size:15px;line-height:1.6;">' . $corpo . '</td></tr>'
             . '<tr><td style="padding:12px 24px;background:#f9fafb;color:#6b7280;font-size:12px;">Messaggio automatico: non rispondere a questa email. Gestisci le tue prenotazioni dall\'Area Personale del portale.</td></tr>'
             . '</table></div>';
    }
}

if (!function_exists('invia_email_attestato_se_concluso')) {
    function invia_email_attestato_se_concluso($conn, $pr_id) {
        $stmt = $conn->prepare(
            "SELECT p.id, p.turno_id, p.nome, p.cognome, p.email, p.codice_prenotazione,
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

        // Turni senza data: l'attestato parte alla registrazione della presenza
        if (!empty($row['data_turno']) && !turno_concluso($row)) return false;

        $domain = url_base_sito();

        $link_attestato = $domain . "/stampa_attestato.php?code=" . urlencode($row['codice_prenotazione']);
        $link_area = $domain . "/area_personale.php";
        $oggetto = "Il tuo Attestato è pronto: " . $row['titolo'];
        $corpo = "<p>Gentile <strong>" . htmlspecialchars($row['nome']) . " " . htmlspecialchars($row['cognome']) . "</strong>,</p>"
               . "<p>Grazie per aver partecipato all'evento <strong>" . htmlspecialchars($row['titolo']) . "</strong>" . (!empty($row['data_turno']) ? " del " . date('d/m/Y', strtotime($row['data_turno'])) : "") . ".</p>"
               . "<p>Il tuo <strong>Attestato di Partecipazione</strong> è disponibile per il download.</p>"
               . "<p style='text-align:center; margin:30px 0;'>"
               . "<a href='" . $link_attestato . "' style='background-color:#198754; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; font-size:16px;'>📄 Scarica il tuo Attestato</a>"
               . "</p>"
               . "<p>In alternativa puoi recuperarlo dalla tua <a href='" . $link_area . "'>Area Personale</a>.</p>"
               . "<p>Cordiali saluti,<br>Il team Eventi DiBEST</p>";

        inviaNotificaEmail($row['email'], $oggetto, $corpo, $conn, colore_area_turno($conn, $row['turno_id']));
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
        return $giorni[date('w', $timestamp)] . ' <span class="text-danger fw-bold">' . date('j', $timestamp) . ' ' . ($mesi[date('n', $timestamp)] ?? '') . ' ' . date('Y', $timestamp) . '</span>';
    }
}

// Turni: nome, data e orari sono tutti facoltativi (almeno nome o data)
if (!function_exists('orario_turno')) {
    function orario_turno(array $t): string {
        if (empty($t['orario_inizio'])) return '';
        return substr($t['orario_inizio'], 0, 5) . (!empty($t['orario_fine']) ? '–' . substr($t['orario_fine'], 0, 5) : '');
    }
}

if (!function_exists('etichetta_turno')) {
    // Testo semplice (da passare a htmlspecialchars): "Gruppo 1 · 22/09/2026 · 09:30–11:00"
    function etichetta_turno(array $t): string {
        $parti = [];
        if (!empty($t['nome_turno'])) $parti[] = $t['nome_turno'];
        if (!empty($t['data_turno'])) $parti[] = date('d/m/Y', strtotime($t['data_turno']));
        $ora = orario_turno($t);
        if ($ora !== '') $parti[] = $ora;
        return $parti ? implode(' · ', $parti) : 'Turno';
    }
}

if (!function_exists('turno_concluso')) {
    // Un turno senza data non scade mai.
    function turno_concluso(array $t): bool {
        if (empty($t['data_turno'])) return false;
        $fine = $t['data_turno'] . ' ' . (!empty($t['orario_fine']) ? substr($t['orario_fine'], 0, 8) : '23:59:59');
        return date('Y-m-d H:i:s') > $fine;
    }
}

if (!function_exists('getGoogleCalendarUrl')) {
    function getGoogleCalendarUrl($title, $data_turno, $ora_inizio, $ora_fine, $location, $details) {
        $st = date('Ymd\THis', strtotime($data_turno . ' ' . $ora_inizio));
        $et = date('Ymd\THis', strtotime($data_turno . ' ' . $ora_fine));
        return "https://calendar.google.com/calendar/render?action=TEMPLATE&text=" . urlencode($title) . "&dates=" . $st . "/" . $et . "&details=" . urlencode($details) . "&location=" . urlencode($location);
    }
}

// getPostiOccupati() è definita in config.php (unica versione, con regola degli stati e FOR UPDATE)

// =======================================================================
// MOTORE INTELLIGENTE LISTE D'ATTESA (NUOVO MODULO)
// =======================================================================
if (!function_exists('promuovi_lista_attesa')) {
    function promuovi_lista_attesa($conn, $turno_id) {
        $res_t = $conn->query("SELECT max_posti, data_turno, orario_inizio FROM turni WHERE id = " . (int)$turno_id . " LIMIT 1");
        if (!$res_t || $res_t->num_rows == 0) return;
        $turno = $res_t->fetch_assoc();
        
        // REGOLA: Se mancano meno di 24 ore all'evento, NON promuoviamo più nessuno.
        // (i turni senza data non hanno scadenza)
        if (!empty($turno['data_turno'])) {
            $inizio_evento = $turno['data_turno'] . ' ' . ($turno['orario_inizio'] ?: '00:00:00');
            if (strtotime($inizio_evento) <= strtotime('+24 hours')) return;
        }

        // Quanti posti liberi ci sono?
        $turno_id = (int)$turno_id;
        $posti_liberi = (int)$turno['max_posti'] - getPostiOccupati($conn, $turno_id);
        
        // Ciclo sicuro per promuovere utenti finché c'è spazio
        while ($posti_liberi > 0) {
            $res_promo = $conn->query("SELECT p.*, e.titolo as evento_titolo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE p.turno_id = $turno_id AND p.stato = 'in_attesa' ORDER BY p.data_prenotazione ASC, p.id ASC LIMIT 1");
            
            if ($res_promo && $u_promo = $res_promo->fetch_assoc()) {
                if ($posti_liberi >= $u_promo['num_posti']) {
                    $id_promo = $u_promo['id'];
                    $scadenza = date('Y-m-d H:i:s', strtotime('+24 hours')); // +24 Ore esatte
                    
                    // Cambia stato e imposta timer
                    $update_ok = $conn->query("UPDATE prenotazioni SET stato = 'richiesta_conferma', scadenza_conferma = '$scadenza' WHERE id = $id_promo");
                    
                    if ($update_ok && $conn->affected_rows > 0) {
                        // Prepara e invia l'email
                        $link_conferma = url_base_sito() . "/area_personale.php?conferma_posto=" . $id_promo;
                        
                        $obj_tpl = "Azione Richiesta: Si è liberato un posto per " . $u_promo['evento_titolo'];
                        $body_tpl = "<p>Ottime notizie <strong>" . htmlspecialchars($u_promo['nome']) . "</strong>!</p>
                                     <p>Si è appena liberato un posto per l'evento <strong>" . htmlspecialchars($u_promo['evento_titolo']) . "</strong>.</p>
                                     <div style='background-color:#fff3cd; color:#856404; padding:15px; border-left:5px solid #ffeeba; margin:20px 0;'>
                                       <strong>ATTENZIONE:</strong> Hai esattamente <strong>24 ore</strong> di tempo per confermare la tua presenza. Se non confermi entro il " . date('d/m/Y H:i', strtotime($scadenza)) . ", il posto verrà riassegnato allo studente successivo.
                                     </div>
                                     <p><a href='$link_conferma' style='background-color:#198754; color:white; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>CONFERMA IL MIO POSTO</a></p>";
                        
                        inviaNotificaEmail($u_promo['email'], $obj_tpl, $body_tpl, $conn, colore_area_turno($conn, $turno_id));
                        
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
    function salva_risposte_sondaggio($conn, int $sond_id, array $risposte, int $pr_id, ?string &$errore = null): bool {
        $errore = null;

        // Accetta solo risposte a domande di QUESTO sondaggio
        $domande = [];
        foreach (get_domande_sondaggio($conn, $sond_id) as $d) { $domande[(int)$d['id']] = $d; }

        $valori = [];
        foreach ($risposte as $d_id => $valore) {
            $d_id = (int)$d_id;
            if (!isset($domande[$d_id])) continue;
            $val = is_array($valore) ? json_encode($valore, JSON_UNESCAPED_UNICODE) : trim((string)$valore);
            if ($val !== '' && $val !== '[]') $valori[$d_id] = $val;
        }

        // Obbligatorie (le condizionali restano verificate solo lato browser, perché possono essere nascoste)
        foreach ($domande as $d_id => $d) {
            if (!empty($d['obbligatorio']) && empty($d['condizione_json']) && $d['tipo'] !== 'separator' && !isset($valori[$d_id])) {
                $errore = "Rispondi a tutte le domande obbligatorie.";
                return false;
            }
        }

        $conn->begin_transaction();
        // Segna come completato per primo: blocca il doppio invio (doppio clic, due schede)
        $stmt_upd = $conn->prepare("UPDATE prenotazioni SET sondaggio_completato = 1 WHERE id = ? AND COALESCE(sondaggio_completato, 0) = 0");
        $stmt_upd->bind_param("i", $pr_id);
        if (!$stmt_upd->execute() || $stmt_upd->affected_rows !== 1) {
            $conn->rollback();
            $errore = "Hai già compilato questo questionario. Grazie per il tuo feedback!";
            return false;
        }
        $stmt_ins = $conn->prepare("INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES (?, ?, ?)");
        foreach ($valori as $d_id => $val) {
            $stmt_ins->bind_param("iis", $sond_id, $d_id, $val);
            if (!$stmt_ins->execute()) {
                $conn->rollback();
                $errore = "Errore durante il salvataggio. Riprova.";
                return false;
            }
        }
        $conn->commit();
        return true;
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
        $select = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo, pe.colore_primario,
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
// VINCOLO ISCRIZIONI PER AREA (limite_iscrizioni: nessuno | un_evento | un_turno)
// =======================================================================
// Le liste d'attesa NON contano per il vincolo: si può stare in attesa su più turni/eventi.
// Appena una prenotazione dello stesso ambito diventa 'confermata', le altre decadono
// (vedi decadi_attese_vincolate).

if (!function_exists('scope_vincolo_sql')) {
    // Condizione SQL (alias t, e) che delimita l'ambito del vincolo, oppure null se non c'è vincolo.
    function scope_vincolo_sql(string $limite, int $pagina_id, int $evento_id): ?string {
        if ($limite === 'un_evento') return "e.pagina_id = $pagina_id AND e.archiviato = 0";
        if ($limite === 'un_turno')  return "t.evento_id = $evento_id";
        return null;
    }
}

if (!function_exists('trova_iscrizione_vincolata')) {
    // Ritorna la prenotazione attiva (non in lista d'attesa) che blocca una nuova iscrizione, oppure null.
    function trova_iscrizione_vincolata($conn, string $limite, int $pagina_id, int $evento_id, int $utente_id, string $email, string $matricola): ?array {
        $scope_sql = scope_vincolo_sql($limite, $pagina_id, $evento_id);
        if ($scope_sql === null) return null;
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.codice_prenotazione, e.titolo AS evento_titolo
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE $scope_sql
               AND pr.stato NOT IN ('annullata', 'rifiutata', 'scaduta', 'in_attesa', 'richiesta_conferma')
               AND (LOWER(pr.email) = ? OR (? > 0 AND pr.utente_id = ?) OR (? != '' AND pr.matricola = ?))
             LIMIT 1"
        );
        $email = strtolower($email);
        $stmt->bind_param("siiss", $email, $utente_id, $utente_id, $matricola, $matricola);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }
}

if (!function_exists('get_mie_iscrizioni_area')) {
    // Mappa evento_id => [turno_id => stato] delle prenotazioni attive dell'utente nell'area.
    function get_mie_iscrizioni_area($conn, int $pagina_id, int $utente_id, string $email): array {
        $stmt = $conn->prepare(
            "SELECT t.evento_id, pr.turno_id, pr.stato
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = ?
               AND pr.stato NOT IN ('annullata', 'rifiutata', 'scaduta')
               AND (pr.utente_id = ? OR (? != '' AND LOWER(pr.email) = LOWER(?)))"
        );
        $stmt->bind_param("iiss", $pagina_id, $utente_id, $email, $email);
        $stmt->execute();
        $res = $stmt->get_result();
        $map = [];
        while ($r = $res->fetch_assoc()) { $map[(int)$r['evento_id']][(int)$r['turno_id']] = (string)$r['stato']; }
        return $map;
    }
}

if (!function_exists('decadi_attese_vincolate')) {
    // Da chiamare DOPO che una prenotazione è diventata 'confermata'. Se l'area ha un limite
    // iscrizioni, annulla le altre richieste pendenti della stessa persona nello stesso ambito
    // (liste d'attesa, posti offerti in attesa di conferma, richieste da approvare), avvisa
    // l'utente con una email e ripassa i posti liberati alla lista d'attesa. Ritorna quante ne annulla.
    function decadi_attese_vincolate($conn, int $pr_id): int {
        $res = $conn->query(
            "SELECT pr.stato, pr.turno_id, pr.email, pr.utente_id, pr.matricola, pr.nome, t.evento_id, e.pagina_id,
                    e.titolo AS evento_titolo, pe.limite_iscrizioni
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE pr.id = $pr_id LIMIT 1"
        );
        if (!$res || !($c = $res->fetch_assoc()) || $c['stato'] !== 'confermata') return 0;
        $scope_sql = scope_vincolo_sql((string)($c['limite_iscrizioni'] ?? 'nessuno'), (int)$c['pagina_id'], (int)$c['evento_id']);
        if ($scope_sql === null) return 0;

        $email = strtolower((string)$c['email']);
        $u_id  = (int)$c['utente_id'];
        $matr  = (string)($c['matricola'] ?? '');
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.turno_id, pr.stato, e.titolo AS evento_titolo, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE $scope_sql
               AND pr.id != ?
               AND pr.stato IN ('in_attesa', 'richiesta_conferma', 'da_approvare')
               AND (LOWER(pr.email) = ? OR (? > 0 AND pr.utente_id = ?) OR (? != '' AND pr.matricola = ?))"
        );
        $stmt->bind_param("isiiss", $pr_id, $email, $u_id, $u_id, $matr, $matr);
        $stmt->execute();
        $res_alt = $stmt->get_result();

        $annullate = [];
        $turni_da_ripassare = [];
        while ($a = $res_alt->fetch_assoc()) {
            $a_id = (int)$a['id'];
            $stato_old = $conn->real_escape_string($a['stato']);
            // "AND stato = ..." evita di annullare una riga cambiata nel frattempo
            $conn->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $a_id AND stato = '$stato_old'");
            if ($conn->affected_rows !== 1) continue;
            $annullate[] = $a;
            // richiesta_conferma / da_approvare tenevano un posto: va offerto al prossimo in coda
            if ($a['stato'] !== 'in_attesa') $turni_da_ripassare[(int)$a['turno_id']] = true;
        }
        if (!$annullate) return 0;

        foreach (array_keys($turni_da_ripassare) as $tid) { promuovi_lista_attesa($conn, $tid); }

        if (function_exists('registra_log_audit')) {
            registra_log_audit($conn, "Decadenza liste d'attesa (limite iscrizioni)", ["Prenotazione confermata" => $pr_id, "Annullate" => implode(',', array_column($annullate, 'id'))]);
        }

        if ($email !== '') {
            $voci = '';
            foreach ($annullate as $a) {
                $voci .= "<li><strong>" . htmlspecialchars($a['evento_titolo']) . "</strong> — " . htmlspecialchars(etichetta_turno($a)) . "</li>";
            }
            $corpo = "<p>Gentile <strong>" . htmlspecialchars((string)$c['nome']) . "</strong>,</p>"
                   . "<p>la tua prenotazione per <strong>" . htmlspecialchars($c['evento_titolo']) . "</strong> è <strong>confermata</strong>.</p>"
                   . "<p>Poiché in quest'area è consentita una sola iscrizione, le tue altre richieste in lista d'attesa sono state annullate automaticamente:</p>"
                   . "<ul>$voci</ul>";
            inviaNotificaEmail($email, "Liste d'attesa annullate: iscrizione confermata a " . $c['evento_titolo'], $corpo, $conn, colore_area_turno($conn, $c['turno_id']));
        }
        return count($annullate);
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
               t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             GROUP BY t.id
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.nome_turno ASC, t.id ASC"
        );
        $rows = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
        return $rows;
    }
}

if (!function_exists('get_kpi_statistiche_v2')) {
    function get_kpi_statistiche_v2($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $kpi  = ['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'annullate' => 0, 'presenti' => 0, 'capienza' => 0];
        $res  = $conn->query(
            "SELECT
               SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
               SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
               SUM(CASE WHEN p.stato IN ('scaduta','rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse,
               SUM(CASE WHEN p.stato IN ('annullata','annullato','cancelled') THEN p.num_posti ELSE 0 END) as tot_annullate,
               SUM(CASE WHEN p.presente = 1 THEN p.num_posti ELSE 0 END) as tot_presenti
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
            $kpi['annullate']  = (int)$row['tot_annullate'];
            $kpi['presenti']   = (int)$row['tot_presenti'];
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

if (!function_exists('get_trend_iscrizioni')) {
    function get_trend_iscrizioni($conn, $p_id, $sql_filtro_rbac, $days = 30) {
        $p_id = (int)$p_id;
        $days = (int)$days;
        $res  = $conn->query(
            "SELECT DATE(p.data_prenotazione) as giorno, COUNT(*) as cnt
             FROM prenotazioni p
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
               AND p.data_prenotazione >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
             GROUP BY DATE(p.data_prenotazione)
             ORDER BY giorno ASC"
        );
        $rows = [];
        if ($res) { while ($r = $res->fetch_assoc()) { $rows[] = $r; } }
        return $rows;
    }
}

if (!function_exists('get_stats_turni_ext')) {
    function get_stats_turni_ext($conn, $p_id, $sql_filtro_rbac) {
        $p_id = (int)$p_id;
        $res  = $conn->query(
            "SELECT e.titolo as evento_titolo, t.id as turno_id,
               t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti,
               COALESCE(SUM(CASE WHEN p.stato IN ('confermata','richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
               COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa,
               COALESCE(SUM(CASE WHEN p.stato IN ('annullata','annullato','cancelled') THEN p.num_posti ELSE 0 END), 0) as annullate,
               COALESCE(SUM(CASE WHEN p.presente = 1 THEN p.num_posti ELSE 0 END), 0) as presenti
             FROM turni t
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN prenotazioni p ON p.turno_id = t.id
             WHERE e.pagina_id = $p_id $sql_filtro_rbac
             GROUP BY t.id
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, t.nome_turno ASC, t.id ASC"
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
            "SELECT pr.*, t.id as turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
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
                $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$row['id']} ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC");
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

// =======================================================================
// WIDGET HOME: configurazione (JSON in configurazione_portale.widgets_home)
// =======================================================================
if (!function_exists('get_prenotazioni_attive_utente')) {
    // Prenotazioni ancora da vivere dell'utente (turno non concluso), la più urgente per prima:
    // prima i posti offerti da confermare, poi per data; i turni senza data in coda.
    function get_prenotazioni_attive_utente($conn, int $u_id, int $limite = 10): array {
        if ($u_id <= 0) return [];
        $stmt = $conn->prepare(
            "SELECT pr.id, pr.codice_prenotazione, IFNULL(pr.stato, 'confermata') AS stato, pr.num_posti, pr.scadenza_conferma,
                    t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                    e.titolo AS evento_titolo, e.luogo, e.locandina_path,
                    pe.titolo AS area_titolo, pe.colore_primario, pe.slug
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE (pr.utente_id = ? OR LOWER(pr.email) = (SELECT LOWER(u.email) FROM utenti u WHERE u.id = ? AND u.email != ''))
               AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare', 'in_attesa')
               AND e.archiviato = 0
               AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_fine, '23:59:59')) >= NOW())
             ORDER BY (IFNULL(pr.stato, 'confermata') = 'richiesta_conferma') DESC,
                      (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, pr.id ASC
             LIMIT ?"
        );
        // Mai bloccare la home per un widget: in caso di errore SQL, niente widget + log
        if (!$stmt) { error_log('[get_prenotazioni_attive_utente] ' . $conn->error); return []; }
        $stmt->bind_param("iii", $u_id, $u_id, $limite);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }
}

if (!function_exists('get_posizioni_lista_attesa')) {
    // Posizione in coda (1 = il prossimo a essere promosso) delle prenotazioni 'in_attesa' indicate.
    // Stesso ordine di promuovi_lista_attesa: data di prenotazione, a parità l'id.
    // Ritorna [pr_id => ['posizione' => n, 'totale' => persone in coda nel turno]].
    function get_posizioni_lista_attesa($conn, array $pr_ids): array {
        $pr_ids = array_filter(array_map('intval', $pr_ids));
        if (!$pr_ids) return [];
        $in = implode(',', $pr_ids);
        $res = $conn->query(
            "SELECT p.id,
                    1 + (SELECT COUNT(*) FROM prenotazioni q
                          WHERE q.turno_id = p.turno_id AND q.stato = 'in_attesa'
                            AND (q.data_prenotazione < p.data_prenotazione
                                 OR (q.data_prenotazione = p.data_prenotazione AND q.id < p.id))) AS posizione,
                    (SELECT COUNT(*) FROM prenotazioni r WHERE r.turno_id = p.turno_id AND r.stato = 'in_attesa') AS totale
             FROM prenotazioni p
             WHERE p.id IN ($in) AND p.stato = 'in_attesa'"
        );
        $out = [];
        if ($res) while ($r = $res->fetch_assoc()) $out[(int)$r['id']] = ['posizione' => (int)$r['posizione'], 'totale' => (int)$r['totale']];
        return $out;
    }
}

if (!function_exists('get_turni_ultimi_posti')) {
    // Turni prenotabili adesso con pochi posti (<= 10% della capienza, almeno 1)
    // o con iscrizioni che chiudono entro 48 ore. Un solo turno per evento, i più urgenti prima.
    function get_turni_ultimi_posti($conn, array $pagine_ids, int $limite = 4): array {
        $pagine_ids = array_filter(array_map('intval', $pagine_ids));
        if (!$pagine_ids) return [];
        $in = implode(',', $pagine_ids);
        // Conteggio posti in una sottoquery: MariaDB non accetta alias di aggregati
        // dentro espressioni di HAVING/ORDER BY, e così vale anche ONLY_FULL_GROUP_BY.
        $res = $conn->query(
            "SELECT x.* FROM (
                SELECT t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.max_posti, t.data_chiusura,
                       e.id AS evento_id, e.titolo, e.locandina_path,
                       pe.titolo AS area_titolo, pe.colore_primario, pe.slug,
                       (SELECT COALESCE(SUM(pr.num_posti), 0) FROM prenotazioni pr
                         WHERE pr.turno_id = t.id
                           AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS occupati
                FROM turni t
                JOIN eventi e ON t.evento_id = e.id
                JOIN pagine_eventi pe ON e.pagina_id = pe.id
                WHERE e.archiviato = 0 AND pe.visibile = 1 AND e.pagina_id IN ($in)
                  AND IFNULL(e.richiede_prenotazione, 1) = 1
                  AND t.max_posti > 0 AND t.max_posti < 9000
                  AND (t.data_apertura IS NULL OR t.data_apertura <= NOW())
                  AND (t.data_chiusura IS NULL OR t.data_chiusura >= NOW())
                  AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '23:59:59')) > NOW())
             ) x
             WHERE x.max_posti - x.occupati > 0
               AND (x.max_posti - x.occupati <= GREATEST(1, CEIL(x.max_posti * 0.10))
                    OR (x.data_chiusura IS NOT NULL AND x.data_chiusura <= NOW() + INTERVAL 48 HOUR))
             ORDER BY (x.max_posti - x.occupati) / x.max_posti ASC, x.data_chiusura IS NULL, x.data_chiusura ASC
             LIMIT 30"
        );
        $out = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                if (isset($out[(int)$r['evento_id']])) continue;
                $r['liberi'] = (int)$r['max_posti'] - (int)$r['occupati'];
                $out[(int)$r['evento_id']] = $r;
                if (count($out) >= $limite) break;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('get_riepilogo_posti')) {
    // Capienza e posti occupati dei turni ancora prenotabili, raggruppati per evento o per area.
    // $per = 'evento' | 'pagina'. Ritorna [id => ['capienza'=>, 'occupati'=>, 'liberi'=>]].
    // Esclusi: eventi senza prenotazione, turni illimitati (>= 9000), conclusi o con iscrizioni chiuse.
    function get_riepilogo_posti($conn, string $per, array $ids): array {
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) return [];
        $col = $per === 'pagina' ? 'e.pagina_id' : 'e.id';
        $in  = implode(',', $ids);
        $res = $conn->query(
            "SELECT x.chiave, SUM(x.max_posti) AS capienza, SUM(x.occupati) AS occupati FROM (
                SELECT $col AS chiave, t.max_posti,
                       (SELECT COALESCE(SUM(pr.num_posti), 0) FROM prenotazioni pr
                         WHERE pr.turno_id = t.id
                           AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')) AS occupati
                FROM turni t
                JOIN eventi e ON t.evento_id = e.id
                WHERE $col IN ($in) AND e.archiviato = 0
                  AND IFNULL(e.richiede_prenotazione, 1) = 1
                  AND t.max_posti > 0 AND t.max_posti < 9000
                  AND (t.data_chiusura IS NULL OR t.data_chiusura >= NOW())
                  AND (t.data_turno IS NULL OR CONCAT(t.data_turno, ' ', COALESCE(t.orario_inizio, '23:59:59')) > NOW())
             ) x
             GROUP BY x.chiave"
        );
        $out = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $cap = (int)$r['capienza'];
                $occ = min($cap, (int)$r['occupati']);
                $out[(int)$r['chiave']] = ['capienza' => $cap, 'occupati' => $occ, 'liberi' => $cap - $occ];
            }
        }
        return $out;
    }
}

if (!function_exists('widgets_home_default')) {
    function widgets_home_default(): array {
        return [
            'slideshow' => 1, 'mia_prenotazione' => 1, 'annunci' => 0, 'card_aree' => 1,
            'ultimi_posti' => 0, 'prossimi_eventi' => 1, 'statistiche' => 0,
            'ordine'        => ['slideshow', 'mia_prenotazione', 'annunci', 'card_aree', 'ultimi_posti', 'prossimi_eventi', 'statistiche'],
            'aree_colonne'  => 2,         // 2 | 3 | 4 card per riga (desktop)
            'aree_max'      => 0,         // 0 = tutte; altrimenti le altre si aprono con "Mostra tutte"
            'eventi_num'    => 8,         // 4 | 8 | 12
            'eventi_layout' => 'scroll',  // scroll | griglia
        ];
    }
}

if (!function_exists('get_widgets_home')) {
    // Legge la configurazione salvata e la normalizza (valori non validi -> default).
    function get_widgets_home(?array $cfg_portale): array {
        $w = widgets_home_default();
        $dec = !empty($cfg_portale['widgets_home']) ? json_decode($cfg_portale['widgets_home'], true) : null;
        if (is_array($dec)) $w = array_merge($w, $dec);

        $chiavi = widgets_home_default()['ordine'];
        foreach ($chiavi as $k) $w[$k] = (int)!empty($w[$k]);

        // Ordine: solo chiavi note, senza duplicati. I widget nuovi (assenti in una
        // configurazione salvata prima che esistessero) vanno subito dopo il widget
        // che li precede nell'ordine predefinito, non in fondo alla pagina.
        $ordine = array_values(array_unique(array_intersect((array)$w['ordine'], $chiavi)));
        foreach ($chiavi as $i => $k) {
            if (in_array($k, $ordine, true)) continue;
            $pos = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $p = array_search($chiavi[$j], $ordine, true);
                if ($p !== false) { $pos = $p + 1; break; }
            }
            array_splice($ordine, $pos, 0, [$k]);
        }
        $w['ordine'] = $ordine;

        $w['aree_colonne']  = in_array((int)$w['aree_colonne'], [2, 3, 4], true) ? (int)$w['aree_colonne'] : 2;
        $w['aree_max']      = max(0, min(48, (int)$w['aree_max']));
        $w['eventi_num']    = in_array((int)$w['eventi_num'], [4, 8, 12], true) ? (int)$w['eventi_num'] : 8;
        $w['eventi_layout'] = $w['eventi_layout'] === 'griglia' ? 'griglia' : 'scroll';
        return $w;
    }
}

// Migrazioni una tantum dello schema (funziona sia su MySQL che su MariaDB).
// UNICO punto in cui il codice modifica la struttura del database: nessuna pagina deve
// eseguire ALTER/CREATE al volo. Il file marcatore evita di interrogare lo schema a ogni
// richiesta: quando aggiungi qualcosa qui, cambia anche il nome del marcatore.
if (!function_exists('assicura_schema')) {
    function assicura_schema($conn) {
        $marker = __DIR__ . '/cache/schema_v7.ok';
        if (is_file($marker)) return;

        // 1. Tabelle di servizio (prima create dalle singole pagine a ogni richiesta)
        $tabelle = [
            'slide_home' => "CREATE TABLE IF NOT EXISTS slide_home (
                id INT AUTO_INCREMENT PRIMARY KEY, immagine_path VARCHAR(255) NOT NULL, titolo VARCHAR(255) DEFAULT '',
                sottotitolo VARCHAR(255) DEFAULT '', link VARCHAR(500) DEFAULT '', ordine INT DEFAULT 0, attiva TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_ordine (ordine)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'log_accessi' => "CREATE TABLE IF NOT EXISTS log_accessi (
                id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT DEFAULT NULL, email VARCHAR(255), nome VARCHAR(100), cognome VARCHAR(100),
                ip VARCHAR(45), user_agent VARCHAR(512), tipo VARCHAR(20) DEFAULT 'sso', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_uid (utente_id), INDEX idx_cat (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'log_email' => "CREATE TABLE IF NOT EXISTS log_email (
                id INT AUTO_INCREMENT PRIMARY KEY, destinatario VARCHAR(255), oggetto VARCHAR(255), esito TINYINT(1) DEFAULT 0,
                canale VARCHAR(10) DEFAULT 'smtp', errore VARCHAR(500) DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_cat (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'rate_limit_attempts' => "CREATE TABLE IF NOT EXISTS rate_limit_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip_hash CHAR(64) NOT NULL, endpoint VARCHAR(80) NOT NULL, hit_at DATETIME NOT NULL,
                INDEX idx_ip_ep (ip_hash, endpoint), INDEX idx_hit (hit_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($tabelle as $nome => $ddl) {
            if (!$conn->query($ddl)) { error_log("[assicura_schema] CREATE fallito su $nome: " . $conn->error); return; }
        }

        // 2. Colonne: 'tabella' => ['colonna' => ALTER] oppure ['colonna' => [ALTER, SQL da eseguire subito dopo averla creata]]
        $colonne = [
            'turni' => [
                'nome_turno' => "ADD COLUMN nome_turno VARCHAR(150) DEFAULT NULL AFTER evento_id, MODIFY data_turno DATE NULL DEFAULT NULL, MODIFY orario_inizio TIME NULL DEFAULT NULL, MODIFY orario_fine TIME NULL DEFAULT NULL",
                'token_checkin' => "ADD COLUMN token_checkin VARCHAR(64) DEFAULT NULL",
            ],
            'sondaggi_domande' => [
                'condizione_json' => "ADD COLUMN condizione_json TEXT NULL",
                'ordine'          => "ADD COLUMN ordine INT DEFAULT 0",
                'obbligatorio'    => "ADD COLUMN obbligatorio TINYINT(1) DEFAULT 0",
            ],
            'campi_form' => [
                'condizione_json' => "ADD COLUMN condizione_json TEXT NULL",
            ],
            'configurazione_portale' => [
                'widgets_home'    => "ADD COLUMN widgets_home TEXT DEFAULT NULL",
                'annuncio_home'   => "ADD COLUMN annuncio_home TEXT DEFAULT NULL",
                'annuncio_colore' => "ADD COLUMN annuncio_colore VARCHAR(20) DEFAULT 'info'",
            ],
            'prenotazioni' => [
                'presente'                  => "ADD COLUMN presente INT DEFAULT 0 AFTER stato",
                'data_presenza'             => "ADD COLUMN data_presenza DATETIME DEFAULT NULL",
                'scadenza_conferma'         => "ADD COLUMN scadenza_conferma DATETIME DEFAULT NULL",
                'reminder_inviato'          => "ADD COLUMN reminder_inviato TINYINT(1) NOT NULL DEFAULT 0",
                'attestato_inviato'         => "ADD COLUMN attestato_inviato TINYINT(1) NOT NULL DEFAULT 0",
                'email_post_evento_inviata' => "ADD COLUMN email_post_evento_inviata TINYINT(1) NOT NULL DEFAULT 0",
                'token_sondaggio'           => "ADD COLUMN token_sondaggio VARCHAR(64) DEFAULT NULL",
                'sondaggio_completato'      => "ADD COLUMN sondaggio_completato TINYINT(1) NOT NULL DEFAULT 0",
            ],
            'eventi' => [
                'abilita_presenze'      => "ADD COLUMN abilita_presenze TINYINT(1) NOT NULL DEFAULT 1",
                'blocca_auto_archivio'  => "ADD COLUMN blocca_auto_archivio TINYINT(1) NOT NULL DEFAULT 0",
                'permessi_gestori_json' => "ADD COLUMN permessi_gestori_json TEXT DEFAULT NULL",
            ],
            'pagine_eventi' => [
                'copertina_path'        => "ADD COLUMN copertina_path VARCHAR(255) DEFAULT NULL",
                'mostra_in_home'        => "ADD COLUMN mostra_in_home TINYINT(1) NOT NULL DEFAULT 1",
                'limite_iscrizioni'     => "ADD COLUMN limite_iscrizioni VARCHAR(20) NOT NULL DEFAULT 'nessuno'",
                // CSV dei gestori che ricevono le email sulle prenotazioni; NULL = tutti
                'notifiche_gestori_ids' => "ADD COLUMN notifiche_gestori_ids TEXT DEFAULT NULL",
            ],
            'sottocategorie' => [
                // Sezione mostrata in alto, affiancata alle altre, nel layout Griglia (prima dedotto dal nome).
                // Alla creazione conserva l'aspetto attuale delle sezioni che prima venivano riconosciute dal nome.
                'affiancata_in_alto' => ["ADD COLUMN affiancata_in_alto TINYINT(1) NOT NULL DEFAULT 0",
                    "UPDATE sottocategorie SET affiancata_in_alto = 1 WHERE nome LIKE '%Online%' OR nome LIKE '%Generali%' OR nome LIKE '%Speciali%' OR nome LIKE '%Conclusive%'"],
            ],
            'utenti' => [
                'matricola_studente'   => "ADD COLUMN matricola_studente VARCHAR(50) DEFAULT NULL",
                'matricola_dipendente' => "ADD COLUMN matricola_dipendente VARCHAR(50) DEFAULT NULL",
                'ultimo_accesso'       => "ADD COLUMN ultimo_accesso DATETIME DEFAULT NULL",
                'ruoli_secondari'      => "ADD COLUMN ruoli_secondari VARCHAR(255) DEFAULT ''",
                'email_personalizzata' => "ADD COLUMN email_personalizzata TINYINT(1) NOT NULL DEFAULT 0",
            ],
        ];
        foreach ($colonne as $tabella => $cols) {
            foreach ($cols as $col => $def) {
                [$alter, $dopo] = is_array($def) ? $def : [$def, null];
                $chk = $conn->query("SHOW COLUMNS FROM `$tabella` LIKE '$col'");
                if (!$chk) return;
                if ($chk->num_rows > 0) continue;
                if (!$conn->query("ALTER TABLE `$tabella` $alter")) {
                    error_log("[assicura_schema] ALTER fallito su $tabella.$col: " . $conn->error);
                    return;
                }
                if ($dopo !== null) $conn->query($dopo);
            }
        }

        // 3. Tipi di colonna da correggere nei database più vecchi
        $tipi = [
            // stato era ENUM senza 'annullata' (prima controllato in config.php a ogni richiesta)
            ['prenotazioni', 'stato', fn($t) => str_contains($t, 'enum'), "MODIFY COLUMN stato VARCHAR(50) DEFAULT 'confermata'"],
            // matricola nata numerica, ma può contenere lettere (prima ALTER in saml_login.php a ogni login)
            ['utenti', 'matricola', fn($t) => !str_contains($t, 'varchar'), "MODIFY COLUMN matricola VARCHAR(50) DEFAULT NULL"],
        ];
        foreach ($tipi as [$tabella, $col, $da_correggere, $alter]) {
            $res = $conn->query("SHOW COLUMNS FROM `$tabella` LIKE '$col'");
            $riga = $res ? $res->fetch_assoc() : null;
            if ($riga && $da_correggere(strtolower((string)$riga['Type'])) && !$conn->query("ALTER TABLE `$tabella` $alter")) {
                error_log("[assicura_schema] MODIFY fallito su $tabella.$col: " . $conn->error);
                return;
            }
        }

        if (!is_dir(__DIR__ . '/cache')) @mkdir(__DIR__ . '/cache', 0755, true);
        @file_put_contents($marker, date('c'));
    }
}
if (isset($conn) && $conn instanceof mysqli) { assicura_schema($conn); }
?>
