<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Svuota i dati applicativi dalla sessione e distruggi subito la sessione PHP.
//    SimpleSAML usa la propria sessione (cookie "SimpleSAML"), separata da PHPSESSID,
//    quindi distruggere qui la sessione PHP non impedisce il flusso SLO.
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// 2. SAML Single Log-Out: invalida la sessione SSO sull'IdP di Ateneo
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

header("Location: logout-success.php");
exit;
