<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Svuota i dati applicativi dalla sessione (utente_id, ruolo, ecc.)
//    ma NON distruggiamo ancora la sessione PHP: SimpleSAML ne ha bisogno
//    per costruire il LogoutRequest verso l'IdP
$_SESSION = [];

// 2. SAML Single Log-Out: invalida la sessione SSO sull'IdP di Ateneo
//    $as->logout() avvia il flusso SLO e fa un redirect → non ritorna mai
$simplesaml_path = '/opt/simplesamlphp/lib/_autoload.php';
if (file_exists($simplesaml_path)) {
    require_once $simplesaml_path;
    $as = new \SimpleSAML\Auth\Simple('default-sp');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $return_url = $proto . '://' . $_SERVER['HTTP_HOST'] . '/eventi/logout-success.php';
    $as->logout($return_url);
    // Non arriva mai qui se SAML era attivo
}

// 3. Fallback (SimpleSAML non installato o utente non autenticato via SAML):
//    distruggi manualmente la sessione PHP e reindirizza
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header("Location: logout-success.php");
exit;
