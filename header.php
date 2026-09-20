<?php
// header.php - Versione AGID ANTI-CRASH, SYNC GLOBALE E ANTI-ITP MOBILE
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

global $conn;

if (!isset($conn) || !($conn instanceof mysqli)) {
    if (file_exists(__DIR__ . '/config.php')) { require_once __DIR__ . '/config.php'; } 
    else { die("Errore critico: File config.php mancante!"); }
}

if (file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
    $pagina_corrente = basename($_SERVER['PHP_SELF']);
    if (function_exists('sync_sso_user') && $pagina_corrente !== 'logout-success.php' && $pagina_corrente !== 'esci.php') {
        sync_sso_user($conn);
    }
}

// Fase 3: lettura da cache locale invece di interrogare il DB ad ogni caricamento pagina
// (vedi get_configurazione_portale in functions.php). Fallback alla query diretta se
// functions.php non risultasse incluso per qualche motivo.
$cfg_portale_header = [];
if (isset($conn) && $conn instanceof mysqli) {
    if (function_exists('get_configurazione_portale')) {
        $cfg_portale_header = get_configurazione_portale($conn);
    } else {
        $res_cfg_portale = @$conn->query("SELECT * FROM configurazione_portale WHERE id = 1");
        if ($res_cfg_portale && $res_cfg_portale->num_rows > 0) {
            $cfg_portale_header = $res_cfg_portale->fetch_assoc();
        }
    }
}

$favicon_url = !empty($cfg_portale_header['favicon_path']) ? $cfg_portale_header['favicon_path'] : 'https://www.unical.it/favicon.ico';
$logo_url = !empty($cfg_portale_header['logo_path']) ? $cfg_portale_header['logo_path'] : '';
$titolo_portale = !empty($cfg_portale_header['nome_portale']) ? $cfg_portale_header['nome_portale'] : 'EventiDiBEST';
$sottotitolo_portale = !empty($cfg_portale_header['sottotitolo_portale']) ? $cfg_portale_header['sottotitolo_portale'] : 'Portale Eventi e Laboratori Dipartimentali';

$u_logged_header = !empty($_SESSION['utente_id']);
$u_ruolo_header = isset($_SESSION['utente_ruolo_id']) ? (int)$_SESSION['utente_ruolo_id'] : 5;
$u_sec_roles_header = !empty($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
$is_admin_header = ($u_ruolo_header === 1 || $u_ruolo_header === 2 || in_array('1', $u_sec_roles_header) || in_array('2', $u_sec_roles_header));

// --- TRAMPOLINO CHECK-IN NATIVO PHP (IMMUNE AI BLOCCHI SMARTPHONE) ---
if ($u_logged_header && !empty($_COOKIE['qrc_t'])) {
    $qr_t = (int)$_COOKIE['qrc_t'];
    $qr_k = $_COOKIE['qrc_k'] ?? '';
    
    // Distruggiamo subito il cookie così lo legge solo UNA volta
    setcookie('qrc_t', '', time() - 3600, '/');
    setcookie('qrc_k', '', time() - 3600, '/');
    unset($_COOKIE['qrc_t']);
    unset($_COOKIE['qrc_k']);
    
    $pagina_corrente = basename($_SERVER['PHP_SELF']);
    if ($pagina_corrente !== 'self_checkin.php' && $pagina_corrente !== 'esci.php') {
        header("Location: self_checkin.php?t=" . $qr_t . "&k=" . urlencode($qr_k));
        exit;
    }
}
// -----------------------------------------------------------------------

$nome_visualizzato = 'Il mio profilo';
if ($u_logged_header) {
    $u_id_safe = (int)$_SESSION['utente_id'];
    $res_u_name = @$conn->query("SELECT nome, cognome FROM utenti WHERE id = $u_id_safe LIMIT 1");
    if ($res_u_name && $row_u = $res_u_name->fetch_assoc()) {
        $nome_visualizzato = trim($row_u['nome'] . ' ' . $row_u['cognome']);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars(isset($page_cfg['titolo']) ? $page_cfg['titolo'] . ' - ' . $titolo_portale : $titolo_portale); ?></title>
    
    <!-- PWA / App Mobile -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#B30000">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="assets/icon-192.png">
    
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($favicon_url); ?>">
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Titillium+Web:ital,wght@0,300;0,400;0,600;0,700;1,400&family=Lora:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-italia@2.8.3/dist/css/bootstrap-italia.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        html { transition: font-size 0.2s ease; }
        body { font-family: 'Titillium Web', sans-serif; background-color: #ffffff !important; }
        
        :root {
            --bs-light: #f8f9fa !important;
            --bs-light-rgb: 248, 249, 250 !important;
            --bs-warning: #fd7e14 !important;
            --bs-warning-rgb: 253, 126, 20 !important;
        }
        .bg-light, .alert-light { background-color: #f8f9fa !important; border-color: #e2e8f0 !important; }
        .bg-warning, .badge.bg-warning, .text-bg-warning { background-color: #fd7e14 !important; color: #ffffff !important; }
        .text-warning { color: #fd7e14 !important; }
        .accordion-button { background-color: #f8f9fa !important; color: #1e293b !important; }
        .accordion-button:not(.collapsed) { background-color: #e9ecef !important; color: #B30000 !important; box-shadow: inset 0 -1px 0 rgba(0,0,0,.125) !important; }
        .accordion-item { border-color: #e2e8f0 !important; background-color: #ffffff !important; }
        .card-header { background-color: #f8f9fa !important; border-bottom: 1px solid #e2e8f0 !important; }
        .list-group-item-light { background-color: #f8f9fa !important; }

        .skip-link { position: absolute; top: -100px; left: 0; background: #000000; color: #ffff00; padding: 12px; z-index: 9999; font-weight: bold; text-decoration: none; border-bottom-right-radius: 8px; transition: top 0.2s; }
        .skip-link:focus { top: 0; outline: 3px solid #ffff00; }

        .top-bar-istituzionale { background-color: #333333; color: #ffffff; padding: 6px 0; font-size: 0.85rem; border-bottom: 2px solid #B30000; position: relative; z-index: 1100; }
        .top-bar-istituzionale a { color: #ffffff; text-decoration: none; transition: opacity 0.2s; }
        .top-bar-istituzionale a:hover { opacity: 0.8; }
        
        .a11y-btn { background: transparent; border: 1px solid rgba(255,255,255,0.3); color: #fff; padding: 2px 10px; border-radius: 4px; font-weight: bold; transition: all 0.2s; cursor: pointer; }
        .a11y-btn:hover, .a11y-btn:focus { background: #ffffff; color: #333333; outline: none; }

        .top-bar-btn, .top-bar-istituzionale a.top-bar-btn { background-color: #ffffff !important; color: #B30000 !important; border: 1px solid #ffffff !important; border-radius: 4px; padding: 5px 15px; font-weight: bold; text-decoration: none !important; transition: all 0.2s; font-size: 0.85rem; display: inline-block; }
        .top-bar-btn:hover, .top-bar-istituzionale a.top-bar-btn:hover { background-color: #B30000 !important; color: #ffffff !important; border-color: #B30000 !important; }

        .it-header-center-wrapper { background-color: #B30000 !important; border-bottom: 1px solid #7a0000; padding: 15px 0; }
        .it-brand-title { color: #ffffff !important; font-size: 2.2rem !important; font-weight: 700 !important; line-height: 1.1; letter-spacing: -0.5px; }
        .it-brand-tagline { color: #ffffff !important; font-size: 1.15rem !important; font-weight: 400 !important; opacity: 0.95; margin-top: 2px; }
        
        .header-search-box { background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 4px; overflow: hidden; display: flex; align-items: center; transition: all 0.3s; }
        .header-search-box:focus-within { background: rgba(255, 255, 255, 0.25); border-color: #ffffff; box-shadow: 0 0 0 0.2rem rgba(255,255,255,0.25); }
        .header-search-box input { background: transparent; border: none; color: #ffffff; padding: 8px 12px; font-size: 0.95rem; width: 180px; outline: none; box-shadow: none; }
        .header-search-box input::placeholder { color: rgba(255, 255, 255, 0.7); }
        .header-search-box button { background: transparent; border: none; color: #ffffff; padding: 8px 12px; cursor: pointer; }
        
        .dropdown-menu.agid-dropdown { border: 1px solid #e2e8f0; border-top: 4px solid #B30000; border-radius: 4px; box-shadow: 0 10px 20px rgba(0,0,0,0.1); padding: 0; min-width: 220px; font-size: 0.9rem; }
        .agid-dropdown .list-item { padding: 10px 20px; color: #1e293b; text-decoration: none; display: block; font-weight: 600; transition: background 0.2s; }
        .agid-dropdown .list-item:hover, .agid-dropdown .list-item:focus { background-color: #f8f9fa; color: #B30000; outline: none; }
        
        @media all and (min-width: 992px) {
            .navbar { position: relative; }
            .navbar .megamenu-li { position: static; }
            .navbar .megamenu { width: 100%; left: 0; right: 0; top: 100%; margin-top: 0; border-radius: 0 0 8px 8px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.08) !important; border: 1px solid #e2e8f0 !important; border-top: 4px solid #B30000 !important; }
            .hover-dropdown:hover > .dropdown-menu { display: block; }
        }
        .megamenu-header { color: #0f172a !important; font-weight: 700; font-size: 1.1rem; margin-bottom: 12px; display: block; text-decoration: none; }
        .megamenu-header:hover, .megamenu-header:focus { color: #B30000 !important; }
        .megamenu-link { color: #475569 !important; font-size: 0.95rem; text-decoration: none; display: block; padding: 5px 0; transition: color 0.2s; }
        .megamenu-link:hover, .megamenu-link:focus { color: #B30000 !important; text-decoration: underline; }

        body.high-contrast { background-color: #000000 !important; color: #ffff00 !important; }
        body.high-contrast * { background-color: transparent !important; color: #ffff00 !important; border-color: #ffff00 !important; }
        body.high-contrast a, body.high-contrast .nav-link, body.high-contrast .btn-primary { color: #00ffff !important; text-decoration: underline !important; }
        body.high-contrast .it-header-center-wrapper, body.high-contrast .top-bar-istituzionale, body.high-contrast nav, body.high-contrast .card { background-color: #000000 !important; border: 1px solid #ffff00 !important; }
        body.high-contrast img { filter: grayscale(100%) contrast(150%); border: 2px solid #ffff00; }
        body.high-contrast .badge { border: 1px solid #ffff00; }
    </style>
</head>
<body>

<a href="#main-content" class="skip-link">Salta al contenuto principale</a>

<script>
    (function() {
        let isHighContrast = localStorage.getItem('hc_dibest') === 'true';
        let zoomLevel = parseInt(localStorage.getItem('zoom_dibest'));
        if (isHighContrast) document.body.classList.add('high-contrast');
        if (zoomLevel) document.documentElement.style.fontSize = zoomLevel + '%';
    })();
</script>

<div class="top-bar-istituzionale d-none d-lg-block">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            
            <div class="d-flex align-items-center gap-4">
                <a href="https://dibest.unical.it" target="_blank" class="fw-bold" aria-label="Sito ufficiale Dipartimento DiBEST">
                    <i class="fa fa-university me-1" aria-hidden="true"></i> UNICAL - Dipartimento di Biologia, Ecologia e Scienze della Terra
                </a>
                
                <div class="d-flex align-items-center gap-1 border-start ps-3 border-secondary" role="group" aria-label="Strumenti di accessibilità visiva">
                    <button class="a11y-btn" id="btnZoomIn" title="Ingrandisci testo" aria-label="Ingrandisci testo">A+</button>
                    <button class="a11y-btn" id="btnZoomOut" title="Riduci testo" aria-label="Riduci testo">A-</button>
                    <button class="a11y-btn ms-2" id="btnContrast" title="Attiva/Disattiva Alto Contrasto" aria-label="Attiva Alto Contrasto"><i class="fa fa-adjust" aria-hidden="true"></i></button>
                </div>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <?php if ($u_logged_header): ?>
                    <div class="dropdown">
                        <button class="top-bar-btn dropdown-toggle d-flex align-items-center" type="button" id="userTopDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu utente">
                            <i class="fa fa-user-circle me-1" aria-hidden="true"></i> <?php echo htmlspecialchars($nome_visualizzato); ?>
                            <i class="fa fa-chevron-down ms-2" style="font-size: 0.7rem;" aria-hidden="true"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end agid-dropdown" aria-labelledby="userTopDropdown">
                            <ul class="list-unstyled m-0 p-0">
                                <li><a class="list-item" href="area_personale.php"><i class="fa fa-id-card text-primary me-2" aria-hidden="true"></i> Area Personale</a></li>
                                <?php if ($is_admin_header): ?>
                                    <li><a class="list-item" href="admin/index.php"><i class="fa fa-cogs text-danger me-2" aria-hidden="true"></i> Pannello Gestori</a></li>
                                    <li><a class="list-item" href="checkin.php" target="_blank"><i class="fa fa-qrcode text-success me-2" aria-hidden="true"></i> Scanner Check-in</a></li>
                                <?php endif; ?>
                                <li><div class="divider" aria-hidden="true"></div></li>
                                <li><a class="list-item text-danger" href="esci.php"><i class="fa fa-sign-out-alt me-2" aria-hidden="true"></i> Esci</a></li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="saml_login.php" class="top-bar-btn">
                        <i class="fa fa-sign-in-alt me-1" aria-hidden="true"></i> Accedi
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<header class="it-header-wrapper" style="position: relative; z-index: 1050;" role="banner">
    <div class="it-header-center-wrapper">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="it-header-center-content-wrapper align-items-center">
                        <div class="it-brand-wrapper">
                            <a href="/" class="text-decoration-none d-flex align-items-center" aria-label="Home page <?php echo htmlspecialchars($titolo_portale); ?>">
                                <?php if (!empty($logo_url)): ?>
                                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo <?php echo htmlspecialchars($titolo_portale); ?>" style="max-height: 75px; margin-right: 20px;">
                                <?php else: ?>
                                    <i class="fa fa-university text-white fs-1 me-3" aria-hidden="true"></i>
                                <?php endif; ?>
                                <div class="it-brand-text">
                                    <div class="it-brand-title" aria-hidden="true"><?php echo htmlspecialchars($titolo_portale); ?></div>
                                    <div class="it-brand-tagline d-none d-md-block" aria-hidden="true"><?php echo htmlspecialchars($sottotitolo_portale); ?></div>
                                </div>
                            </a>
                        </div>
                        
                        <div class="it-right-zone">
                            <div class="it-search-wrapper d-flex align-items-center">
                                <form action="ricerca.php" method="GET" class="header-search-box d-none d-md-flex" role="search">
                                    <input type="text" name="q" placeholder="Cerca eventi, aule..." aria-label="Cerca nel portale" required>
                                    <button type="submit" aria-label="Avvia ricerca"><i class="fa fa-search" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="shadow-sm" style="background-color: #ffffff !important; border-bottom: 1px solid #e2e8f0; position: relative; z-index: 999;">
    <div class="container" style="position: relative;">
        <nav class="navbar navbar-expand-lg px-0 py-1" aria-label="Menu principale" style="background-color: #ffffff !important; position: static;">
            
            <button class="navbar-toggler border-0 shadow-none w-100 text-start py-2 d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Mostra/Nascondi menu">
                <div class="d-flex align-items-center" style="color: #000000 !important;">
                    <i class="fa fa-bars fs-3 me-2" aria-hidden="true"></i>
                    <span class="fs-6" style="font-weight: 400 !important;">Menu di Navigazione</span>
                </div>
            </button>

            <div class="collapse navbar-collapse" id="mainNavbar">
                <form action="ricerca.php" method="GET" class="d-flex d-md-none p-3 bg-light border-bottom" role="search">
                    <div class="input-group">
                        <input type="text" name="q" class="form-control border-primary" placeholder="Cerca eventi..." aria-label="Cerca eventi" required>
                        <button class="btn btn-primary" type="submit" aria-label="Avvia ricerca"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </form>

                <ul class="navbar-nav me-auto mb-2 mb-lg-0 w-100 py-2 py-lg-0">
                   
                    
                    <?php
                    if (isset($conn) && $conn instanceof mysqli) {
                        $res_menu = @$conn->query("SELECT * FROM menu_voci WHERE genitore_id = 0 AND (visibile IS NULL OR visibile = 1) ORDER BY ordine ASC");
                        if ($res_menu && $res_menu->num_rows > 0):
                            while ($m = $res_menu->fetch_assoc()):
                                $v_id = (int)$m['ruolo_visibilita_id'];
                                $show_menu = true;
                                if ($v_id === -1 && !$u_logged_header) $show_menu = false;
                                elseif ($v_id > 0 && (!$u_logged_header || ($u_ruolo_header !== $v_id && !in_array((string)$v_id, $u_sec_roles_header) && !$is_admin_header))) $show_menu = false;
                                
                                if ($show_menu):
                                    $m_id = $m['id'];
                                    $res_sub = @$conn->query("SELECT * FROM menu_voci WHERE genitore_id = $m_id AND (visibile IS NULL OR visibile = 1) ORDER BY ordine ASC");
                                    $has_sub = ($res_sub && $res_sub->num_rows > 0);
                                    $target = $m['apri_nuova_scheda'] ? 'target="_blank"' : '';
                                    
                                    if ($has_sub):
                        ?>
                                        <li class="nav-item dropdown hover-dropdown megamenu-li">
                                            <a class="nav-link px-lg-3" href="#" id="drop<?php echo $m_id; ?>" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="color: #000000 !important; font-weight: 400 !important;">
                                                <?php echo htmlspecialchars($m['etichetta']); ?> <i class="fa fa-chevron-down ms-1" style="font-size: 0.7rem; color: #B30000;" aria-hidden="true"></i>
                                            </a>
                                            <div class="dropdown-menu megamenu bg-white" aria-labelledby="drop<?php echo $m_id; ?>">
                                                <div class="row g-4">
                                                    <?php while ($sub = $res_sub->fetch_assoc()): ?>
                                                        <?php 
                                                            $v_id_sub = (int)$sub['ruolo_visibilita_id'];
                                                            $show_sub = true;
                                                            if ($v_id_sub === -1 && !$u_logged_header) $show_sub = false;
                                                            elseif ($v_id_sub > 0 && (!$u_logged_header || ($u_ruolo_header !== $v_id_sub && !in_array((string)$v_id_sub, $u_sec_roles_header) && !$is_admin_header))) $show_sub = false;
                                                            
                                                            if ($show_sub):
                                                                $sub_id = $sub['id'];
                                                                $res_subsub = @$conn->query("SELECT * FROM menu_voci WHERE genitore_id = $sub_id AND (visibile IS NULL OR visibile = 1) ORDER BY ordine ASC");
                                                                $has_subsub = ($res_subsub && $res_subsub->num_rows > 0);
                                                                $target_sub = $sub['apri_nuova_scheda'] ? 'target="_blank"' : '';
                                                                $header_url = (!empty($sub['url']) && $sub['url'] !== '#') ? htmlspecialchars($sub['url']) : 'javascript:void(0);';
                                                        ?>
                                                                <div class="col-md-6 col-lg-3 mb-3">
                                                                    <a href="<?php echo $header_url; ?>" <?php echo $target_sub; ?> class="megamenu-header pb-2 mb-2" style="border-bottom: 1px solid #e2e8f0;">
                                                                        <?php echo htmlspecialchars($sub['etichetta']); ?>
                                                                    </a>
                                                                    <?php if ($has_subsub): ?>
                                                                        <ul class="list-unstyled m-0 p-0">
                                                                            <?php while ($subsub = $res_subsub->fetch_assoc()): ?>
                                                                                <?php
                                                                                    $v_id_subsub = (int)$subsub['ruolo_visibilita_id'];
                                                                                    $show_subsub = true;
                                                                                    if ($v_id_subsub === -1 && !$u_logged_header) $show_subsub = false;
                                                                                    elseif ($v_id_subsub > 0 && (!$u_logged_header || ($u_ruolo_header !== $v_id_subsub && !in_array((string)$v_id_subsub, $u_sec_roles_header) && !$is_admin_header))) $show_subsub = false;
                                                                                    
                                                                                    if ($show_subsub):
                                                                                        $target_subsub = $subsub['apri_nuova_scheda'] ? 'target="_blank"' : '';
                                                                                ?>
                                                                                    <li>
                                                                                        <a href="<?php echo htmlspecialchars($subsub['url']); ?>" <?php echo $target_subsub; ?> class="megamenu-link">
                                                                                            <?php echo htmlspecialchars($subsub['etichetta']); ?>
                                                                                        </a>
                                                                                    </li>
                                                                                <?php endif; endwhile; ?>
                                                                        </ul>
                                                                    <?php endif; ?>
                                                                </div>
                                                        <?php endif; endwhile; ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php else: ?>
                                        <li class="nav-item">
                                            <a class="nav-link px-lg-3" href="<?php echo htmlspecialchars($m['url']); ?>" <?php echo $target; ?> style="color: #000000 !important; font-weight: 400 !important;"><?php echo htmlspecialchars($m['etichetta']); ?></a>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endwhile; ?>
                        <?php endif; 
                    } ?>
                    
                    <li class="nav-item d-lg-none border-top mt-3 pt-3">
                        
                        <div class="px-3 mb-2 mt-2 text-muted small fw-bold text-uppercase">Accessibilità Visiva</div>
                        <div class="px-3 mb-3 d-flex gap-2">
                            <button class="btn btn-outline-dark btn-sm flex-fill" id="btnZoomInMob" aria-label="Ingrandisci testo">A+</button>
                            <button class="btn btn-outline-dark btn-sm flex-fill" id="btnZoomOutMob" aria-label="Riduci testo">A-</button>
                            <button class="btn btn-dark btn-sm flex-fill" id="btnContrastMob" aria-label="Attiva Alto Contrasto"><i class="fa fa-adjust" aria-hidden="true"></i></button>
                        </div>

                        <div class="px-3 mb-2 text-muted small fw-bold text-uppercase">Account</div>
                        <?php if ($u_logged_header): ?>
                            <a class="nav-link" href="area_personale.php" style="color: #000000 !important; font-weight: 400 !important;"><i class="fa fa-user me-2 text-primary" aria-hidden="true"></i> Area Personale</a>
                            <?php if ($is_admin_header): ?>
                                <a class="nav-link" href="admin/index.php" style="color: #000000 !important; font-weight: 400 !important;"><i class="fa fa-cogs me-2 text-danger" aria-hidden="true"></i> Pannello Gestori</a>
                                <a class="nav-link" href="checkin.php" target="_blank" style="color: #000000 !important; font-weight: 400 !important;"><i class="fa fa-qrcode me-2 text-success" aria-hidden="true"></i> Scanner Check-in</a>
                            <?php endif; ?>
                            <a class="nav-link text-danger mt-2" href="esci.php" style="font-weight: bold !important;"><i class="fa fa-sign-out-alt me-2" aria-hidden="true"></i> Esci / Disconnetti</a>
                        <?php else: ?>
                            <a class="nav-link" href="saml_login.php" style="color: #000000 !important; font-weight: bold !important;"><i class="fa fa-sign-in-alt me-2 text-primary" aria-hidden="true"></i> Accedi</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</div>

<main id="main-content" class="container-fluid py-4" style="min-height: 60vh;">

<script>
document.addEventListener("DOMContentLoaded", function() {
    let zoomLevel = parseInt(localStorage.getItem('zoom_dibest')) || 100;
    let isHighContrast = localStorage.getItem('hc_dibest') === 'true';

    function updateA11y() {
        if(isHighContrast) document.body.classList.add('high-contrast');
        else document.body.classList.remove('high-contrast');
        
        document.documentElement.style.fontSize = zoomLevel + '%';
        
        localStorage.setItem('zoom_dibest', zoomLevel);
        localStorage.setItem('hc_dibest', isHighContrast);
    }

    const toggleContrast = () => { isHighContrast = !isHighContrast; updateA11y(); };
    document.getElementById('btnContrast')?.addEventListener('click', toggleContrast);
    document.getElementById('btnContrastMob')?.addEventListener('click', toggleContrast);

    const zoomIn = () => { if (zoomLevel < 150) { zoomLevel += 10; updateA11y(); } };
    document.getElementById('btnZoomIn')?.addEventListener('click', zoomIn);
    document.getElementById('btnZoomInMob')?.addEventListener('click', zoomIn);

    const zoomOut = () => { if (zoomLevel > 90) { zoomLevel -= 10; updateA11y(); } };
    document.getElementById('btnZoomOut')?.addEventListener('click', zoomOut);
    document.getElementById('btnZoomOutMob')?.addEventListener('click', zoomOut);
});
</script>
