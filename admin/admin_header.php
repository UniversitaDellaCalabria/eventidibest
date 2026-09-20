<?php
// admin_header.php - Guscio Superiore, Sicurezza, RBAC e Menu Laterale
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../config.php';
require_once '../functions.php';

// 1. SINCRONIZZAZIONE SSO E CONTROLLO ACCESSO
sync_sso_user($conn);
$utente_admin = null;
$u_id_curr = $_SESSION['utente_id'] ?? null;

if ($u_id_curr) {
    $stmt_ua = $conn->prepare("SELECT * FROM utenti WHERE id = ? LIMIT 1");
    $stmt_ua->bind_param("i", $u_id_curr);
    $stmt_ua->execute();
    $res_chk = $stmt_ua->get_result();
    if ($res_chk && $res_chk->num_rows > 0) { $utente_admin = $res_chk->fetch_assoc(); }
    $stmt_ua->close();
}

if (!$utente_admin) {
    ?>
    <!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Accesso Riservato</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>
    <body class="bg-light d-flex align-items-center justify-content-center" style="height: 100vh;">
        <div class="card shadow-sm p-4 text-center" style="max-width: 500px; border-top: 4px solid #990000;">
            <h4 class="fw-bold mb-3 text-danger"><i class="fa fa-lock"></i> Autenticazione Richiesta</h4>
            <div class="mt-3"><a href="../saml_login.php" class="btn btn-danger px-4 fw-bold" style="background-color: #990000;">Accedi con SSO Unical</a></div>
        </div>
    </body></html>
    <?php exit;
}

$u_ruolo_curr = (int)$utente_admin['ruolo_id'];
$u_sec_roles = array_filter(explode(',', $utente_admin['ruoli_secondari'] ?? ''));
$is_full_admin = ($u_ruolo_curr === 1) || in_array('1', $u_sec_roles);

// ==============================================================================
// GESTIONE AZIONI GLOBALI AREA DI LAVORO
// ==============================================================================
if (isset($_POST['toggle_visibilita_pagina']) && $is_full_admin) {
    $st_vis = (int)$_POST['stato_visibile'];
    $p_id_toggle = (int)$_POST['pagina_id'];
    $stmt_vis = $conn->prepare("UPDATE pagine_eventi SET visibile = ? WHERE id = ?");
    $stmt_vis->bind_param("ii", $st_vis, $p_id_toggle);
    $stmt_vis->execute();
    $stmt_vis->close();
    flash_set("Stato visibilità dell'area aggiornato!");
    echo "<script>window.location.replace('".$_SERVER['PHP_SELF']."?p_id=$p_id_toggle');</script>";
    exit;
}

if (isset($_POST['add_nuova_pagina']) && $is_full_admin) {
    $titolo_p = trim($_POST['titolo_pagina'] ?? '');
    $slug_raw = trim($_POST['slug_pagina'] ?? '');
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $slug_raw)));
    $col_p = $_POST['colore_primario'] ?? '#0056b3';
    $add_menu = isset($_POST['add_to_menu']) ? 1 : 0;

    if (!empty($titolo_p) && !empty($slug)) {
        $stmt_chk_slug = $conn->prepare("SELECT id FROM pagine_eventi WHERE slug = ?");
        $stmt_chk_slug->bind_param("s", $slug);
        $stmt_chk_slug->execute();
        $check_e = $stmt_chk_slug->get_result();
        if ($check_e && $check_e->num_rows > 0) {
            flash_set("Errore: Un'area con slug '$slug' esiste già!", 'danger');
        } else {
            $desc_def = "<strong style=\"color: $col_p;\">Benvenuto/a a $titolo_p:</strong> Scopri il programma ed iscriviti.";
            $stmt_ins_p = $conn->prepare("INSERT INTO pagine_eventi (titolo, slug, colore_primario, colore_secondario, larghezza_contenitore, layout_template, num_colonne, spazio_card, mostra_sidebar, chiedi_matricola, visibile, sidebar_titolo, hero_descrizione) VALUES (?, ?, ?, '#0056b3', '85%', 'grid', 2, 30, 1, 1, 1, ?, ?)");
            $stmt_ins_p->bind_param("sssss", $titolo_p, $slug, $col_p, $titolo_p, $desc_def);
            $stmt_ins_p->execute();
            $new_id = $conn->insert_id;
            if ($add_menu) {
                $menu_url = $slug . '.php';
                $stmt_menu = $conn->prepare("INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id) VALUES (0, ?, ?, 10, 0, 0)");
                $stmt_menu->bind_param("ss", $titolo_p, $menu_url);
                $stmt_menu->execute();
            }
            
            $template_code = "<?php\n\$page_slug = '{$slug}';\nrequire_once 'master_template.php';\n?>";
            file_put_contents(dirname(__DIR__) . '/' . $slug . '.php', $template_code);
            $archive_code = "<?php\n\$page_slug = '{$slug}';\nrequire_once 'master_archivio.php';\n?>";
            file_put_contents(dirname(__DIR__) . '/' . $slug . '_archivio.php', $archive_code);
            
            flash_set("Area $titolo_p e pagina archivio create!");
            echo "<script>window.location.replace('impostazioni_area.php?p_id=$new_id');</script>";
            exit;
        }
    }
}

if (isset($_POST['del_pagina_completa']) && $is_full_admin) {
    $p_id_del = (int)$_POST['pagina_id_del'];
    $stmt_p_del = $conn->prepare("SELECT slug FROM pagine_eventi WHERE id = ? LIMIT 1");
    $stmt_p_del->bind_param("i", $p_id_del);
    $stmt_p_del->execute();
    $res_p_del = $stmt_p_del->get_result();
    $stmt_p_del->close();
    if ($res_p_del && $p_info_del = $res_p_del->fetch_assoc()) {
        $slug_del = $p_info_del['slug'];
        $res_evs = $conn->query("SELECT id FROM eventi WHERE pagina_id = " . (int)$p_id_del);
        while($ev_row = $res_evs->fetch_assoc()){
            $ev_del_id = (int)$ev_row['id'];
            $conn->query("DELETE FROM prenotazioni WHERE turno_id IN (SELECT id FROM turni WHERE evento_id = $ev_del_id)");
            $conn->query("DELETE FROM turni WHERE evento_id = $ev_del_id");
        }
        $stmt_cf = $conn->prepare("DELETE FROM campi_form WHERE pagina_id = ?");
        $stmt_cf->bind_param("i", $p_id_del); $stmt_cf->execute(); $stmt_cf->close();
        $stmt_sc = $conn->prepare("DELETE FROM sottocategorie WHERE pagina_id = ?");
        $stmt_sc->bind_param("i", $p_id_del); $stmt_sc->execute(); $stmt_sc->close();
        $stmt_ev = $conn->prepare("DELETE FROM eventi WHERE pagina_id = ?");
        $stmt_ev->bind_param("i", $p_id_del); $stmt_ev->execute(); $stmt_ev->close();
        $slug_del_safe = preg_replace('/[^a-z0-9_]/', '', $slug_del);
        $menu_url_del = $slug_del_safe . '.php';
        $stmt_mv = $conn->prepare("DELETE FROM menu_voci WHERE url = ?");
        $stmt_mv->bind_param("s", $menu_url_del); $stmt_mv->execute(); $stmt_mv->close();
        $conn->query("DELETE FROM pagine_eventi WHERE id = $p_id_del");
        @unlink(dirname(__DIR__) . '/' . $slug_del . '.php');
        @unlink(dirname(__DIR__) . '/' . $slug_del . '_archivio.php');
        
        flash_set("Area di lavoro eliminata definitivamente!", 'warning');
        echo "<script>window.location.replace('index.php');</script>";
        exit;
    }
}

// ==============================================================================
// 2. RECUPERO AREE DI LAVORO (MOTORE REGEX ANTIPROIETTILE)
// ==============================================================================
$pagine_disponibili = [];
$res_all_p = $conn->query("SELECT * FROM pagine_eventi ORDER BY ordine ASC, id ASC");

if ($res_all_p) {
    while ($p_row = $res_all_p->fetch_assoc()) {
        if ($is_full_admin) {
            $pagine_disponibili[] = $p_row;
        } else {
            $p_id = $p_row['id'];
            $has_access = false;
            $u_id_str = (string)$u_id_curr;

            // A. Gestore Principale dell'Area
            if ($p_row['gestore_utente_id'] == $u_id_curr) $has_access = true;

            // B. Ricerca Flessibile nei permessi dell'Area (ignora sporcizia del DB)
            if (!$has_access && preg_match('/\b' . $u_id_str . '\b/', $p_row['gestori_utenti_ids'] ?? '')) $has_access = true;
            if (!$has_access && preg_match('/\b' . $u_id_str . '\b/', $p_row['permessi_gestori_json'] ?? '')) $has_access = true;

            // C. Ricerca Flessibile nei Singoli Eventi
            if (!$has_access) {
                $res_ev = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = $p_id");
                if ($res_ev) {
                    while ($e_row = $res_ev->fetch_assoc()) {
                        if (preg_match('/\b' . $u_id_str . '\b/', $e_row['gestori_utenti_ids'] ?? '')) { $has_access = true; break; }
                        if (preg_match('/\b' . $u_id_str . '\b/', $e_row['permessi_gestori_json'] ?? '')) { $has_access = true; break; }
                    }
                }
            }

            if ($has_access) {
                $pagine_disponibili[] = $p_row;
            }
        }
    }
}

$filtro_p = isset($_GET['p_id']) ? (int)$_GET['p_id'] : ($pagine_disponibili[0]['id'] ?? 0);
$page_cfg = null;
if ($filtro_p > 0) {
    $stmt_cfg = $conn->prepare("SELECT * FROM pagine_eventi WHERE id = ?");
    $stmt_cfg->bind_param("i", $filtro_p);
    $stmt_cfg->execute();
    $res_cfg = $stmt_cfg->get_result();
    if ($res_cfg && $res_cfg->num_rows > 0) $page_cfg = $res_cfg->fetch_assoc();
    $stmt_cfg->close();
}

// ==============================================================================
// 3. MOTORE RBAC & ISOLAMENTO EVENTI (CON REGEX)
// ==============================================================================
$can_manage_eventi = $is_full_admin;
$can_manage_iscritti = $is_full_admin;
$can_manage_sondaggi = $is_full_admin;
$can_manage_form = $is_full_admin;
$can_manage_settings = $is_full_admin;

$is_area_manager = $is_full_admin;
$allowed_events_ids = [];

if (!$is_full_admin && $page_cfg) {
    $u_id_str = (string)$u_id_curr;
    
    // Controlla se è Manager di Tutta l'Area
    if ($page_cfg['gestore_utente_id'] == $u_id_curr || 
        preg_match('/\b' . $u_id_str . '\b/', $page_cfg['gestori_utenti_ids'] ?? '') || 
        preg_match('/\b' . $u_id_str . '\b/', $page_cfg['permessi_gestori_json'] ?? '')) {
        
        $is_area_manager = true;
        
        $permessi_json = json_decode($page_cfg['permessi_gestori_json'] ?? '{}', true) ?: [];
        if (isset($permessi_json[$u_id_curr])) {
            $p_user = $permessi_json[$u_id_curr];
            if (in_array('full', $p_user)) { 
                $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = $can_manage_settings = true; 
            } else {
                if (in_array('eventi', $p_user)) $can_manage_eventi = true;
                if (in_array('iscritti', $p_user)) $can_manage_iscritti = true;
                if (in_array('sondaggi', $p_user)) $can_manage_sondaggi = true;
                if (in_array('form', $p_user)) $can_manage_form = true;
            }
        } else {
            $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = true;
        }
    }

    // Controlla i Singoli Eventi e ISOLA
    $res_ev_perms = $conn->query("SELECT id, permessi_gestori_json, gestori_utenti_ids FROM eventi WHERE pagina_id = $filtro_p");
    if ($res_ev_perms) {
        while ($ev_row = $res_ev_perms->fetch_assoc()) {
            $ev_id = $ev_row['id'];
            $has_event_access = false;
            
            if (preg_match('/\b' . $u_id_str . '\b/', $ev_row['gestori_utenti_ids'] ?? '') || 
                preg_match('/\b' . $u_id_str . '\b/', $ev_row['permessi_gestori_json'] ?? '')) {
                $has_event_access = true;
            }
            
            if ($has_event_access) {
                $allowed_events_ids[] = $ev_id; // Colleziona gli ID consentiti
                
                if (!$is_area_manager) {
                    $ev_json = json_decode($ev_row['permessi_gestori_json'] ?? '{}', true) ?: [];
                    if (isset($ev_json[$u_id_curr])) {
                        $p_ev = $ev_json[$u_id_curr];
                        if (in_array('full', $p_ev)) { 
                            $can_manage_eventi = $can_manage_iscritti = $can_manage_sondaggi = $can_manage_form = true; 
                        } else {
                            if (in_array('eventi', $p_ev)) $can_manage_eventi = true;
                            if (in_array('iscritti', $p_ev)) $can_manage_iscritti = true;
                            if (in_array('sondaggi', $p_ev)) $can_manage_sondaggi = true;
                            if (in_array('form', $p_ev)) $can_manage_form = true;
                        }
                    } else {
                        // Se è nel vecchio formato, sblocca almeno la gestione di base
                        $can_manage_eventi = true;
                        $can_manage_iscritti = true;
                    }
                }
            }
        }
    }
}

// VARIABILE MAGICA GLOBALE PER I FILTRI
$sql_filtro_eventi_rbac = "";
if (!$is_full_admin && !$is_area_manager) {
    if (!empty($allowed_events_ids)) {
        // Applica i paraocchi: vedi solo i tuoi eventi
        $sql_filtro_eventi_rbac = " AND e.id IN (" . implode(',', $allowed_events_ids) . ") ";
    } else {
        // Taglia fuori tutto
        $sql_filtro_eventi_rbac = " AND e.id = -1 "; 
    }
}

$sys = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
// Fase 3: lettura da cache locale (stessa di header.php/footer.php). Sempre coerente con
// il DB perché testata.php invalida la cache subito dopo ogni salvataggio delle impostazioni.
$cfg_p = function_exists('get_configurazione_portale')
    ? get_configurazione_portale($conn)
    : ($conn->query("SELECT * FROM configurazione_portale WHERE id = 1")->fetch_assoc() ?: []);
$current_page = basename($_SERVER['PHP_SELF']);

$unread_sql = "SELECT COUNT(DISTINCT m.prenotazione_id) as total_unread FROM messaggi_prenotazioni m JOIN prenotazioni p ON m.prenotazione_id = p.id JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE m.letto = 0 AND m.mittente_tipo = 'utente' AND e.pagina_id = $filtro_p $sql_filtro_eventi_rbac";
$unread_count = $conn->query($unread_sql)->fetch_assoc()['total_unread'] ?? 0;
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Amministrazione - EventiDiBEST</title>
    <link rel="icon" type="image/x-icon" href="../<?php echo !empty($cfg_p['favicon_path']) ? $cfg_p['favicon_path'] : 'favicon.ico'; ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
      // Applica il tema prima del render per evitare il flash
      (function() {
        var t = localStorage.getItem('adminTheme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', t);
      })();
    </script>
    <style>
        body { background-color: #f8f9fa; overflow-x: hidden; }
        [data-bs-theme="dark"] body { background-color: #1a1d21 !important; }
        [data-bs-theme="dark"] #sidebar { background: #101418 !important; }
        [data-bs-theme="dark"] .bg-white { background-color: #2b2f33 !important; }
        [data-bs-theme="dark"] .navbar { background-color: #1e2227 !important; border-color: #3a3f46 !important; }
        [data-bs-theme="dark"] .card { background-color: #2b2f33 !important; border-color: #3a3f46 !important; }
        [data-bs-theme="dark"] .form-select, [data-bs-theme="dark"] .form-control { background-color: #1e2227; color: #e0e6f0; border-color: #3a3f46; }
        [data-bs-theme="dark"] .text-muted { color: #8a95a3 !important; }
        #wrapper { display: flex; width: 100%; min-height: 100vh; }
        #sidebar { width: 260px; min-height: 100vh; transition: all 0.3s ease; z-index: 1000; background: #1e293b; }
        #page-content-wrapper { flex-grow: 1; width: 100%; transition: all 0.3s ease; }
        .nav-pills .nav-link { color: #cbd5e1; border-radius: 8px; margin-bottom: 5px; text-align: left; font-weight: 600; padding: 10px 15px; }
        .nav-pills .nav-link.active { background-color: #990000; color: #fff; }
        .nav-pills .nav-link:hover:not(.active) { background-color: rgba(255,255,255,0.1); color: #fff; }
        @media (max-width: 991.98px) {
            #sidebar { margin-left: -260px; position: fixed; height: 100%; overflow-y: auto; }
            #sidebar.active { margin-left: 0; box-shadow: 5px 0 15px rgba(0,0,0,0.5); }
            #sidebarOverlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999; }
            #sidebarOverlay.active { display: block; }
        }
    </style>
</head>
<body>

<div id="wrapper" class="no-print">

    <!-- SIDEBAR DINAMICA (RBAC) -->
    <div id="sidebar" class="text-white shadow">
        <div class="p-4 border-bottom border-secondary d-flex justify-content-between align-items-center">
            <h5 class="fw-bold text-danger m-0" style="color:#ff4d4d !important;">EventiDiBEST<br><small class="text-white fs-6">CMS Admin</small></h5>
            <button class="btn btn-sm btn-outline-light d-lg-none" id="closeSidebar"><i class="fa fa-times"></i></button>
        </div>
        
        <div class="p-3">
            <ul class="nav nav-pills flex-column">
                <li class="nav-item mb-1">
                    <a class="nav-link w-100 <?php echo ($current_page == 'dashboard.php' || $current_page == 'index.php') ? 'active' : ''; ?>" href="dashboard.php?p_id=<?php echo $filtro_p; ?>">
                        <i class="fa fa-gauge-high me-2 text-center" style="width:20px;"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item mb-2">
                    <div class="text-secondary small fw-bold px-3 mb-1 text-uppercase">Gestione Contenuti</div>
                </li>

                <?php if ($can_manage_eventi): ?>
                <li class="nav-item">
                    <a class="nav-link w-100 <?php echo ($current_page == 'eventi.php' || $current_page == 'index.php') ? 'active' : ''; ?>" href="eventi.php?p_id=<?php echo $filtro_p; ?>">
                        <i class="fa fa-calendar-alt me-2 text-center" style="width:20px;"></i> Eventi e Turni
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link w-100 <?php echo ($current_page == 'archivio.php') ? 'active' : ''; ?>" href="archivio.php?p_id=<?php echo $filtro_p; ?>">
                        <i class="fa fa-archive me-2 text-center" style="width:20px;"></i> Archivio Storico
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($can_manage_iscritti): ?>
                <li class="nav-item mt-2">
                    <a class="nav-link w-100 <?php echo ($current_page == 'iscritti.php') ? 'active' : ''; ?>" href="iscritti.php?p_id=<?php echo $filtro_p; ?>">
                        <i class="fa fa-users me-2 text-center" style="width:20px;"></i> Iscritti & Check-in
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link w-100 <?php echo ($current_page == 'stampa_badge.php') ? 'active' : ''; ?>" href="stampa_badge.php?p_id=<?php echo $filtro_p; ?>">
                        <i class="fa fa-id-badge me-2 text-center" style="width:20px;"></i> Stampa Badge
                    </a>
                </li>
                <?php endif; ?>

                <?php if ($can_manage_sondaggi): ?>
                    <li class="nav-item mt-2">
                        <a class="nav-link w-100 <?php echo ($current_page == 'sondaggi.php') ? 'active' : ''; ?>" href="sondaggi.php?p_id=<?php echo $filtro_p; ?>" <?php echo ($current_page == 'sondaggi.php') ? '' : 'style="background-color: #198754; color: white;"'; ?>>
                            <i class="fa fa-star me-2 text-center" style="width:20px;"></i> Sondaggi & Feedback
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php if ($can_manage_iscritti): ?>
                    <li class="nav-item">
                        <a class="nav-link w-100 <?php echo ($current_page == 'statistiche.php') ? 'active' : ''; ?>" href="statistiche.php?p_id=<?php echo $filtro_p; ?>">
                            <i class="fa fa-chart-pie me-2 text-center" style="width:20px;"></i> Statistiche & Report
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php if ($can_manage_settings || $can_manage_form || $is_full_admin): ?>
                    <li class="nav-item mt-3 mb-2">
                        <div class="text-secondary small fw-bold px-3 mb-1 text-uppercase">Configurazione Pagina</div>
                    </li>
                    <?php if ($can_manage_settings): ?>
                        <li class="nav-item">
                            <a class="nav-link w-100 <?php echo ($current_page == 'impostazioni_area.php') ? 'active' : ''; ?>" href="impostazioni_area.php?p_id=<?php echo $filtro_p; ?>">
                                <i class="fa fa-paint-brush me-2 text-center" style="width:20px;"></i> Impostazioni Area
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($can_manage_form): ?>
                        <li class="nav-item">
                            <a class="nav-link w-100 <?php echo ($current_page == 'form_builder.php') ? 'active' : ''; ?>" href="form_builder.php?p_id=<?php echo $filtro_p; ?>">
                                <i class="fa fa-list-check me-2 text-center" style="width:20px;"></i> Form Builder
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($is_full_admin): ?>
                        <li class="nav-item">
                            <a class="nav-link w-100 <?php echo ($current_page == 'testata.php') ? 'active' : ''; ?>" href="testata.php?p_id=<?php echo $filtro_p; ?>">
                                <i class="fa fa-image me-2 text-center" style="width:20px;"></i> Testata & Logo
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link w-100 <?php echo ($current_page == 'menu.php') ? 'active' : ''; ?>" href="menu.php?p_id=<?php echo $filtro_p; ?>">
                                <i class="fa fa-link me-2 text-center" style="width:20px;"></i> Menu Navigazione
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if ($is_full_admin): ?>
                    <li class="nav-item mt-3 mb-2">
                        <div class="text-secondary small fw-bold px-3 mb-1 text-uppercase">Sicurezza & Server</div>
                    </li>
                    <li class="nav-item">
                        <a href="utenti.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'utenti.php' ? 'active bg-danger' : ''; ?>">
                            <i class="fa fa-users-cog me-2"></i> Utenti & Gruppi
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="abilitazioni.php" class="nav-link text-white <?php echo basename($_SERVER['PHP_SELF']) == 'abilitazioni.php' ? 'active bg-danger' : ''; ?>">
                            <i class="fa fa-key me-2"></i> Abilitazioni Gestori
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link w-100 <?php echo ($current_page == 'audit_log.php') ? 'active' : ''; ?>" href="audit_log.php?p_id=<?php echo $filtro_p; ?>">
                            <i class="fa fa-user-secret me-2 text-center" style="width:20px;"></i> Registro Audit
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link w-100 <?php echo ($current_page == 'sistema.php') ? 'active' : ''; ?>" href="sistema.php?p_id=<?php echo $filtro_p; ?>">
                            <i class="fa fa-envelope me-2 text-center" style="width:20px;"></i> Sistema Email
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    
    <div id="sidebarOverlay"></div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-white border-bottom shadow-sm px-3 py-2 d-flex justify-content-between sticky-top" style="z-index: 998;">
            <button class="btn btn-dark d-lg-none" id="sidebarToggle"><i class="fa fa-bars"></i> Menu</button>
            <div class="ms-auto d-flex align-items-center gap-2">
                <span class="text-muted small d-none d-md-inline-block"><i class="fa fa-user-shield text-danger me-1"></i> <strong><?php echo htmlspecialchars($utente_admin['nome'] ?? ''); ?></strong></span>
                
                <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-dark btn-sm fw-bold me-2 shadow-sm" style="background-color: #ffffff;">
                    <i class="fa fa-envelope me-1"></i> Messaggi
                    <span id="badgeUnreadWrap" <?php echo (isset($unread_count) && $unread_count > 0) ? '' : 'style="display:none;"'; ?>>
                        <span id="badgeUnread" class="badge bg-danger ms-1 text-white shadow-sm" style="background-color: #B80000 !important;"><?php echo 'Nuovi (' . ($unread_count ?? 0) . ')'; ?></span>
                    </span>
                </a>

                <button id="themeToggle" class="btn btn-outline-secondary btn-sm" title="Cambia tema" onclick="toggleTheme()">
                    <i class="fa fa-moon" id="themeIcon"></i>
                </button>
                <a href="../checkin.php" target="_blank" class="btn btn-warning btn-sm fw-bold text-dark shadow-sm" title="Apri Scanner Check-in"><i class="fa fa-qrcode"></i> <span class="d-none d-sm-inline">Scanner</span></a>
                <a href="../index.php" target="_blank" class="btn btn-outline-secondary btn-sm" title="Vai al sito"><i class="fa fa-external-link-alt"></i> <span class="d-none d-sm-inline">Visita Sito</span></a>
                <a href="../esci.php" class="btn btn-danger btn-sm" title="Esci"><i class="fa fa-sign-out-alt"></i></a>
            </div>
        </nav>

        <div class="container-fluid p-4" style="max-width: 1400px;">
            <?php echo flash_html(); ?>

            <div class="card mb-4 shadow-sm border-0 bg-white">
                <div class="card-body d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3 w-100 flex-wrap">
                        
                        <form method="GET" id="formAreaLavoro" class="d-flex align-items-center m-0 bg-white p-2 rounded shadow-sm border">
                            <label class="fw-bold small text-primary me-2 mb-0"><i class="fa fa-layer-group"></i> AREA DI LAVORO:</label>
                            <select name="p_id" class="form-select form-select-sm fw-bold border-primary text-primary" onchange="window.location.href='<?php echo $current_page; ?>?p_id='+this.value" style="min-width: 200px;">
                                <?php if (empty($pagine_disponibili)): ?>
                                    <option value="0">Nessuna Area Assegnata</option>
                                <?php else: ?>
                                    <?php foreach($pagine_disponibili as $p_opt): ?>
                                        <option value="<?php echo $p_opt['id']; ?>" <?php echo $filtro_p == $p_opt['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p_opt['titolo']); ?>
                                            <?php if (($p_opt['visibile'] ?? 1) == 0): ?> 🙈 [Nascosta]<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </form>

                        <div class="ms-auto d-flex gap-2">
                            <?php if ($is_full_admin && $filtro_p > 0): ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
                                    <?php 
                                        $stmt_rvis = $conn->prepare("SELECT visibile FROM pagine_eventi WHERE id = ?");
                                        $stmt_rvis->bind_param("i", $filtro_p); $stmt_rvis->execute();
                                        $res_vis = $stmt_rvis->get_result(); $stmt_rvis->close();
                                        $vis_val = ($res_vis && $row_vis = $res_vis->fetch_assoc()) ? $row_vis['visibile'] : 1;
                                    ?>
                                    <?php if ($vis_val == 1): ?>
                                        <input type="hidden" name="stato_visibile" value="0">
                                        <button type="submit" name="toggle_visibilita_pagina" class="btn btn-outline-success btn-sm fw-bold bg-white shadow-sm"><i class="fa fa-eye me-1"></i> Area Visibile (Clicca per nascondere)</button>
                                    <?php else: ?>
                                        <input type="hidden" name="stato_visibile" value="1">
                                        <button type="submit" name="toggle_visibilita_pagina" class="btn btn-warning text-dark btn-sm fw-bold shadow-sm"><i class="fa fa-eye-slash me-1"></i> Area Nascosta (Clicca per mostrare)</button>
                                    <?php endif; ?>
                                </form>
                                <button type="button" class="btn btn-primary btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modNuovaAreaTop"><i class="fa fa-plus-circle me-1"></i> Nuova Area</button>
                                <?php if ($filtro_p > 0): ?>
                                    <button type="button" class="btn btn-outline-danger bg-white btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modEliminaAreaTop"><i class="fa fa-trash-alt me-1"></i> Elimina Area</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>

            <!-- MODALI GLOBALI DELLA TESTATA -->
            <?php if ($is_full_admin): ?>
                <div class="modal fade" id="modNuovaAreaTop" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header bg-primary text-white py-2">
                                    <h6 class="modal-title fw-bold"><i class="fa fa-plus-circle me-1"></i> Crea Nuova Area di Lavoro</h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-start">
                                    <div class="mb-3"><label class="form-label small fw-bold">Nome / Titolo dell'Area</label><input type="text" name="titolo_pagina" class="form-control form-control-sm" required></div>
                                    <div class="mb-3"><label class="form-label small fw-bold">Identificativo URL (Slug)</label><input type="text" name="slug_pagina" class="form-control form-control-sm" required></div>
                                    <div class="mb-3"><label class="form-label small fw-bold">Colore Primario</label><input type="color" name="colore_primario" class="form-control form-control-color w-100" value="#0056b3"></div>
                                    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="add_to_menu" value="1" id="chkAddMenu" checked><label class="form-check-label small fw-bold" for="chkAddMenu">Aggiungi al Menu Principale</label></div>
                                </div>
                                <div class="modal-footer py-2"><button type="submit" name="add_nuova_pagina" class="btn btn-primary btn-sm fw-bold w-100">Crea Area</button></div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if (isset($filtro_p) && $filtro_p > 0 && isset($page_cfg)): ?>
                <div class="modal fade" id="modEliminaAreaTop" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content text-start border-danger">
                            <form method="POST">
                                <input type="hidden" name="pagina_id_del" value="<?php echo $filtro_p; ?>">
                                <div class="modal-header bg-danger text-white py-2">
                                    <h6 class="modal-title fw-bold"><i class="fa fa-exclamation-triangle me-1"></i> Conferma Eliminazione</h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body"><p class="text-danger fw-bold mb-2">Attenzione: operazione irreversibile!</p><p class="small text-secondary mb-0">Stai per eliminare definitivamente questa area.</p></div>
                                <div class="modal-footer py-2"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button><button type="submit" name="del_pagina_completa" class="btn btn-danger btn-sm fw-bold">Sì, Elimina Definitivamente</button></div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>

<!-- Modal avviso scadenza sessione -->
<div class="modal fade" id="modSessionTimeout" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-warning">
            <div class="modal-header bg-warning text-dark py-2">
                <h6 class="modal-title fw-bold"><i class="fa fa-clock me-1"></i> Sessione in scadenza</h6>
            </div>
            <div class="modal-body text-center">
                <p class="mb-1">La tua sessione scadrà tra</p>
                <p class="fw-bold fs-4 text-danger mb-1" id="sessionCountdown">2:00</p>
                <p class="text-muted small">Vuoi restare connesso?</p>
            </div>
            <div class="modal-footer py-2 justify-content-center gap-2">
                <button class="btn btn-success btn-sm fw-bold px-4" onclick="renewSession()"><i class="fa fa-rotate-right me-1"></i> Sì, rinnova</button>
                <a href="../esci.php" class="btn btn-danger btn-sm fw-bold"><i class="fa fa-sign-out-alt me-1"></i> Esci</a>
            </div>
        </div>
    </div>
</div>

<script>
// ── Dark / Light mode toggle ──────────────────────────────────────────────────
function toggleTheme() {
    var html = document.documentElement;
    var current = html.getAttribute('data-bs-theme') || 'light';
    var next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-bs-theme', next);
    localStorage.setItem('adminTheme', next);
    updateThemeIcon(next);
}
function updateThemeIcon(theme) {
    var icon = document.getElementById('themeIcon');
    if (!icon) return;
    icon.className = theme === 'dark' ? 'fa fa-sun' : 'fa fa-moon';
}
// Sincronizza icona al caricamento
document.addEventListener('DOMContentLoaded', function() {
    updateThemeIcon(localStorage.getItem('adminTheme') || 'light');
});

// ── Session timeout (avviso 2 min prima dei 30 min di inattività) ─────────────
(function() {
    var SESSION_MINUTES = 30;
    var WARN_BEFORE_SEC = 120;
    var inactivityTimer, countdownTimer;
    var modal = null;
    var secondsLeft = WARN_BEFORE_SEC;

    function getModal() {
        if (!modal && typeof bootstrap !== 'undefined') {
            modal = new bootstrap.Modal(document.getElementById('modSessionTimeout'));
        }
        return modal;
    }

    function startCountdown() {
        secondsLeft = WARN_BEFORE_SEC;
        var el = document.getElementById('sessionCountdown');
        clearInterval(countdownTimer);
        countdownTimer = setInterval(function() {
            secondsLeft--;
            if (el) {
                var m = Math.floor(secondsLeft / 60);
                var s = secondsLeft % 60;
                el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }
            if (secondsLeft <= 0) {
                clearInterval(countdownTimer);
                window.location.href = '../esci.php';
            }
        }, 1000);
        var m = getModal();
        if (m) m.show();
    }

    function resetTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(startCountdown, (SESSION_MINUTES * 60 - WARN_BEFORE_SEC) * 1000);
    }

    window.renewSession = function() {
        clearInterval(countdownTimer);
        var m = getModal();
        if (m) m.hide();
        fetch(window.location.href, { method: 'HEAD', credentials: 'same-origin' });
        resetTimer();
    };

    ['click', 'keydown', 'mousemove', 'touchstart'].forEach(function(e) {
        document.addEventListener(e, resetTimer, { passive: true });
    });
    resetTimer();
})();

// ── Badge messaggi in tempo reale (polling ogni 30s) ─────────────────────────
(function() {
    var pId = <?php echo (int)$filtro_p; ?>;
    if (!pId) return;

    function aggiornaBadge(n) {
        var el = document.getElementById('badgeUnread');
        var wrap = document.getElementById('badgeUnreadWrap');
        if (!el || !wrap) return;
        if (n > 0) {
            el.textContent = 'Nuovi (' + n + ')';
            wrap.style.display = '';
        } else {
            wrap.style.display = 'none';
        }
    }

    function fetchUnread() {
        fetch('api_unread.php?p_id=' + pId, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.auth !== false) aggiornaBadge(d.unread); })
            .catch(function() {});
    }

    setInterval(fetchUnread, 30000);
})();

</script>
