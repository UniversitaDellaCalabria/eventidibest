<?php
// config.php apre la sessione con i cookie params corretti (SameSite, Secure, HttpOnly)
require_once 'config.php';
require_once 'functions.php';

// Rate limiting: max 15 accessi alla pagina di login in 5 minuti per IP
if (!check_rate_limit($conn, 'saml_login', 15, 300)) {
    http_response_code(429);
    die("Troppi tentativi di accesso. Attendi qualche minuto e riprova.");
}

// ADEGUAMENTO STRUTTURA TABELLA UTENTI E AUTO-RIPRISTINO RUOLI
@$conn->query("ALTER TABLE utenti MODIFY COLUMN matricola VARCHAR(50) DEFAULT NULL");
@$conn->query("ALTER TABLE utenti ADD COLUMN matricola_studente VARCHAR(50) DEFAULT NULL AFTER matricola");
@$conn->query("ALTER TABLE utenti ADD COLUMN matricola_dipendente VARCHAR(50) AFTER matricola_studente");
@$conn->query("ALTER TABLE utenti ADD COLUMN ultimo_accesso DATETIME DEFAULT NULL");
@$conn->query("ALTER TABLE utenti ADD COLUMN ruoli_secondari VARCHAR(255) DEFAULT ''");
@$conn->query("ALTER TABLE utenti ADD COLUMN email_personalizzata TINYINT(1) NOT NULL DEFAULT 0");

$conn->query("INSERT IGNORE INTO ruoli (id, nome) VALUES 
    (1, 'Amministratore'), 
    (2, 'Gestore Prenotazioni'), 
    (3, 'Studenti'), 
    (4, 'Dipendenti'), 
    (5, 'Esterni / Ospiti')");

$_saml_env = parse_ini_file(__DIR__ . '/.env');
$simplesaml_path = $_saml_env['SIMPLESAML_PATH'] ?? '/opt/simplesamlphp/lib/_autoload.php';
unset($_saml_env);

if (file_exists($simplesaml_path)) {
    require_once($simplesaml_path);

    // Salviamo nome e ID della nostra sessione PHP (quella che il browser conosce)
    // PRIMA che requireAuth() la sostituisca con la sessione interna di SimpleSAML.
    $our_session_name = session_name();
    $our_session_id   = session_id();

    $as = new \SimpleSAML\Auth\Simple('default-sp');
    $as->requireAuth();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name($our_session_name);
    session_id($our_session_id);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $attributes = $as->getAttributes();
    
    // CODICE FISCALE
    $cf_saml = $attributes['codice_fiscale'][0] ?? null;
    if (!$cf_saml && !empty($attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0])) {
        $parts = explode(':', $attributes['urn:oid:1.3.6.1.4.1.25178.1.2.15'][0]);
        $cf_saml = end($parts);
    }
    if (!$cf_saml) {
        $cf_saml = $attributes['fiscalNumber'][0] ?? ($attributes['spidCode'][0] ?? null);
        if ($cf_saml && strpos($cf_saml, 'TINIT-') === 0) {
            $cf_saml = str_replace('TINIT-', '', $cf_saml);
        }
    }
    
    // NOME (ESTRAZIONE AVANZATA SPID / SAML)
    $nome_saml = $attributes['givenName'][0] 
        ?? $attributes['givenname'][0] 
        ?? $attributes['name'][0] 
        ?? $attributes['first_name'][0] 
        ?? $attributes['urn:oid:2.5.4.42'][0] 
        ?? '';

    // COGNOME (ESTRAZIONE AVANZATA SPID / SAML)
    $cognome_saml = $attributes['sn'][0] 
        ?? $attributes['surname'][0] 
        ?? $attributes['family_name'][0] 
        ?? $attributes['last_name'][0] 
        ?? $attributes['urn:oid:2.5.4.4'][0] 
        ?? '';

    // FALLBACK SU COMMON NAME (CN) / DISPLAYNAME
    if (empty($nome_saml) && !empty($attributes['cn'][0])) {
        $parts = explode(' ', trim($attributes['cn'][0]), 2);
        $nome_saml = $parts[0] ?? '';
        $cognome_saml = $parts[1] ?? '';
    } elseif (empty($nome_saml) && !empty($attributes['displayName'][0])) {
        $parts = explode(' ', trim($attributes['displayName'][0]), 2);
        $nome_saml = $parts[0] ?? '';
        $cognome_saml = $parts[1] ?? '';
    }

    if (empty($nome_saml)) { $nome_saml = 'Utente'; }

    $email_saml = $attributes['mail'][0] ?? ($attributes['email'][0] ?? '');
    $matr_stud  = $attributes['matricola_studente'][0] ?? ($attributes['schacPersonalUniqueCode'][0] ?? '');
    $matr_dip   = $attributes['matricola_dipendente'][0] ?? '';

    if ($cf_saml) {
        $cf_clean      = strtoupper(trim($cf_saml));
        $nome_clean    = trim($nome_saml);
        $cognome_clean = trim($cognome_saml);
        $email_clean   = strtolower(trim($email_saml));
        $matr_stud_c   = trim($matr_stud);
        $matr_dip_c    = trim($matr_dip);

        $stmt_sel = $conn->prepare("SELECT u.*, r.nome as ruolo_nome FROM utenti u LEFT JOIN ruoli r ON u.ruolo_id = r.id WHERE u.codice_fiscale = ? LIMIT 1");
        $stmt_sel->bind_param("s", $cf_clean);
        $stmt_sel->execute();
        $res_chk = $stmt_sel->get_result();

        if ($res_chk && $res_chk->num_rows > 0) {
            $u_info = $res_chk->fetch_assoc();
            $u_id = (int)$u_info['id'];

            $stmt_upd = $conn->prepare("UPDATE utenti SET ultimo_accesso = NOW(), email = IF(? != '' AND email_personalizzata = 0, ?, email), nome = IF(nome = 'Utente' AND ? != 'Utente', ?, nome), cognome = IF(cognome = '' AND ? != '', ?, cognome) WHERE id = ?");
            $stmt_upd->bind_param("ssssssi", $email_clean, $email_clean, $nome_clean, $nome_clean, $cognome_clean, $cognome_clean, $u_id);
            $stmt_upd->execute();
        } else {
            $default_role = !empty($matr_stud_c) ? 3 : (!empty($matr_dip_c) ? 4 : 5);
            $matr_gen = !empty($matr_stud_c) ? $matr_stud_c : $matr_dip_c;

            $stmt_ins = $conn->prepare("INSERT INTO utenti (codice_fiscale, nome, cognome, email, matricola, matricola_studente, matricola_dipendente, ruolo_id, ultimo_accesso) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt_ins->bind_param("sssssssi", $cf_clean, $nome_clean, $cognome_clean, $email_clean, $matr_gen, $matr_stud_c, $matr_dip_c, $default_role);
            $stmt_ins->execute();
            $u_id = $conn->insert_id;

            $stmt_info = $conn->prepare("SELECT u.*, r.nome as ruolo_nome FROM utenti u LEFT JOIN ruoli r ON u.ruolo_id = r.id WHERE u.id = ? LIMIT 1");
            $stmt_info->bind_param("i", $u_id);
            $stmt_info->execute();
            $u_info = $stmt_info->get_result()->fetch_assoc();
        }

        $_SESSION['utente_id']       = (int)$u_info['id'];
        $_SESSION['utente_cf']       = $u_info['codice_fiscale'];
        $_SESSION['utente_nome']     = trim($u_info['nome'] . ' ' . $u_info['cognome']);
        $_SESSION['utente_email']    = $u_info['email'];
        $_SESSION['utente_ruolo_id'] = (int)$u_info['ruolo_id'];

        session_write_close();
    }
}

// Blocca open redirect: accetta solo percorsi relativi (no schema http://, javascript:, né URL protocol-relative //)
$redirect_raw = $_GET['redirect'] ?? '';
if (
    !empty($redirect_raw) &&
    !preg_match('#^[a-z][a-z0-9+\-.]*:#i', $redirect_raw) &&
    strpos($redirect_raw, '//') !== 0
) {
    $redirect = $redirect_raw;
} else {
    $redirect = 'index.php';
}
$redirect_js = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
// JS redirect bypassa bfcache e cache HTTP: il browser ricarica sempre la pagina dal server
ob_end_clean();
?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>Accesso completato</title>
<script>window.location.replace('<?php echo $redirect_js; ?>');</script>
</head>
<body>Accesso effettuato. <a href="<?php echo $redirect_js; ?>">Clicca qui se non vieni reindirizzato.</a></body>
</html>
<?php
exit;
