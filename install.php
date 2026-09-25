<?php
// install.php - Sistema di Installazione EventiDiBEST CMS
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$msg = "";
$msg_type = "";

// 1. SICUREZZA: Controlla se il file config.php esiste già
if (file_exists('config.php') && filesize('config.php') > 0) {
    die("
    <div style='font-family: Arial, sans-serif; text-align: center; margin-top: 100px;'>
        <h1 style='color: #990000;'>🛑 Sistema già Installato!</h1>
        <p>Il file <strong>config.php</strong> è già presente. Se vuoi reinstallare il sistema da zero, cancella o rinomina il file <code>config.php</code> e svuota il database.</p>
        <a href='index.php' style='display: inline-block; padding: 10px 20px; background: #0056b3; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Vai alla Home del Portale</a>
    </div>
    ");
}

// 2. ELABORAZIONE DEL FORM DI INSTALLAZIONE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['esegui_installazione'])) {
    $db_host = trim($_POST['db_host']);
    $db_user = trim($_POST['db_user']);
    $db_pass = trim($_POST['db_pass']);
    $db_name = trim($_POST['db_name']);

    $admin_nome = trim($_POST['admin_nome']);
    $admin_cognome = trim($_POST['admin_cognome']);
    $admin_email = strtolower(trim($_POST['admin_email']));
    $admin_cf = strtoupper(trim($_POST['admin_cf']));

    // A. TEST CONNESSIONE
    $conn = @new mysqli($db_host, $db_user, $db_pass);
    if ($conn->connect_error) {
        $msg = "❌ <strong>Errore di connessione al Server MySQL:</strong> " . $conn->connect_error;
        $msg_type = "danger";
    } else {
        // B. CREAZIONE / SELEZIONE DATABASE
        $conn->query("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->select_db($db_name);

        // C. DEFINIZIONE SCHEMA SQL (11 Tabelle)
        $sql_schema = "
        CREATE TABLE IF NOT EXISTS `ruoli` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nome` varchar(100) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `utenti` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ruolo_id` int(11) DEFAULT 5,
            `ruoli_secondari` varchar(255) DEFAULT '',
            `nome` varchar(100) DEFAULT NULL,
            `cognome` varchar(100) DEFAULT NULL,
            `email` varchar(150) DEFAULT NULL,
            `email_personalizzata` tinyint(1) NOT NULL DEFAULT 0,
            `codice_fiscale` varchar(20) DEFAULT NULL,
            `matricola` varchar(50) DEFAULT NULL,
            `matricola_studente` varchar(50) DEFAULT NULL,
            `matricola_dipendente` varchar(50) DEFAULT NULL,
            `ultimo_accesso` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `configurazione_portale` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `nome_portale` varchar(255) DEFAULT 'UNIVERSITÀ DELLA CALABRIA',
            `sottotitolo_portale` varchar(255) DEFAULT 'Dipartimento di Biologia, Ecologia e Scienze della Terra',
            `descrizione_portale` text DEFAULT NULL,
            `logo_path` varchar(255) DEFAULT '',
            `favicon_path` varchar(255) DEFAULT '',
            `colore_menu_bg` varchar(20) DEFAULT '#1e293b',
            `colore_menu_testo` varchar(20) DEFAULT '#ffffff',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `impostazioni_sistema` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `smtp_host` varchar(255) DEFAULT 'smtpservizi.unical.it',
            `smtp_port` int(11) DEFAULT 587,
            `smtp_username` varchar(255) DEFAULT '',
            `smtp_password` varchar(255) DEFAULT '',
            `smtp_secure` varchar(10) DEFAULT 'tls',
            `smtp_from_email` varchar(255) DEFAULT 'noreply@unical.it',
            `smtp_from_name` varchar(255) DEFAULT 'Eventi DiBEST',
            `email_conferma_oggetto` varchar(255) DEFAULT 'Conferma Prenotazione',
            `email_conferma_corpo` text DEFAULT NULL,
            `email_canc_utente_oggetto` varchar(255) DEFAULT 'Cancellazione Prenotazione',
            `email_canc_utente_corpo` text DEFAULT NULL,
            `email_canc_admin_oggetto` varchar(255) DEFAULT 'Annullamento Evento',
            `email_canc_admin_corpo` text DEFAULT NULL,
            `email_reminder_oggetto` varchar(255) DEFAULT 'Promemoria Evento',
            `email_reminder_corpo` text DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `pagine_eventi` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `gestore_utente_id` int(11) DEFAULT 0,
            `gestori_utenti_ids` varchar(255) DEFAULT '',
            `titolo` varchar(255) NOT NULL,
            `sottotitolo` varchar(255) DEFAULT '',
            `slug` varchar(150) NOT NULL,
            `colore_primario` varchar(20) DEFAULT '#990000',
            `colore_secondario` varchar(20) DEFAULT '#0056b3',
            `larghezza_contenitore` varchar(20) DEFAULT '85%',
            `layout_template` varchar(50) DEFAULT 'advanced_list',
            `num_colonne` int(11) DEFAULT 2,
            `spazio_card` int(11) DEFAULT 30,
            `mostra_sidebar` tinyint(1) DEFAULT 1,
            `chiedi_matricola` tinyint(1) DEFAULT 1,
            `visibile` tinyint(1) DEFAULT 1,
            `sidebar_titolo` varchar(255) DEFAULT '',
            `sidebar_intervallo_date` varchar(255) DEFAULT '',
            `sidebar_testo` text DEFAULT NULL,
            `posizione_box_info` varchar(50) DEFAULT 'top',
            `hero_descrizione` text DEFAULT NULL,
            `box_info_html` text DEFAULT NULL,
            `hero_banner_path` varchar(255) DEFAULT '',
            `sidebar_immagine_path` varchar(255) DEFAULT '',
            `ordine` int(11) DEFAULT 0,
            `mostra_in_home` tinyint(1) NOT NULL DEFAULT 1,
            `limite_iscrizioni` varchar(20) NOT NULL DEFAULT 'nessuno',
            PRIMARY KEY (`id`),
            UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `sottocategorie` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pagina_id` int(11) NOT NULL,
            `nome` varchar(255) NOT NULL,
            `ordine` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `eventi` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pagina_id` int(11) NOT NULL,
            `sottocategoria_id` int(11) DEFAULT NULL,
            `titolo` varchar(255) NOT NULL,
            `luogo` varchar(255) DEFAULT '',
            `descrizione` text DEFAULT NULL,
            `locandina_path` varchar(255) DEFAULT '',
            `is_evidenza` tinyint(1) DEFAULT 0,
            `richiede_prenotazione` tinyint(1) DEFAULT 1,
            `ruolo_accesso_id` int(11) DEFAULT 0,
            `gestori_utenti_ids` varchar(255) DEFAULT '',
            `ordine` int(11) DEFAULT 0,
            `archiviato` tinyint(1) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `turni` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `evento_id` int(11) NOT NULL,
            `nome_turno` varchar(150) DEFAULT NULL,
            `data_turno` date DEFAULT NULL,
            `orario_inizio` time DEFAULT NULL,
            `orario_fine` time DEFAULT NULL,
            `max_posti` int(11) DEFAULT 30,
            `data_apertura` datetime DEFAULT NULL,
            `data_chiusura` datetime DEFAULT NULL,
            `abilita_lista_attesa` tinyint(1) DEFAULT 0,
            `abilita_multi_posto` tinyint(1) DEFAULT 0,
            `richiede_approvazione` tinyint(1) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `prenotazioni` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `turno_id` int(11) NOT NULL,
            `utente_id` int(11) DEFAULT NULL,
            `codice_prenotazione` varchar(50) NOT NULL,
            `stato` varchar(50) DEFAULT 'confermata',
            `presente` int(11) DEFAULT 0,
            `num_posti` int(11) DEFAULT 1,
            `nome` varchar(100) DEFAULT NULL,
            `cognome` varchar(100) DEFAULT NULL,
            `email` varchar(150) DEFAULT NULL,
            `matricola` varchar(50) DEFAULT NULL,
            `dati_custom_json` text DEFAULT NULL,
            `data_prenotazione` datetime DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `campi_form` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `pagina_id` int(11) NOT NULL,
            `evento_id` int(11) DEFAULT NULL,
            `nome_campo` varchar(100) NOT NULL,
            `etichetta` varchar(255) NOT NULL,
            `tipo_campo` varchar(50) DEFAULT 'text',
            `opzioni_select` text DEFAULT NULL,
            `obbligatorio` tinyint(1) DEFAULT 0,
            `ordine` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `menu_voci` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `genitore_id` int(11) DEFAULT 0,
            `etichetta` varchar(100) NOT NULL,
            `url` varchar(255) NOT NULL,
            `ordine` int(11) DEFAULT 0,
            `apri_nuova_scheda` tinyint(1) DEFAULT 0,
            `ruolo_visibilita_id` int(11) DEFAULT 0,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        // Esecuzione dello schema completo
        $conn->multi_query($sql_schema);
        while ($conn->next_result()) {;} // Svuota i risultati per poter fare altre query

        // D. INSERIMENTO DATI INIZIALI (Ruoli base)
        $conn->query("INSERT IGNORE INTO `ruoli` (`id`, `nome`) VALUES (1, 'Super Amministratore'), (2, 'Gestore Area/Evento'), (3, 'Studente'), (4, 'Docente/Dipendente'), (5, 'Ospite / Esterno')");

        // E. INSERIMENTO SUPER ADMIN
        // email_personalizzata = 1: impedisce che il login SSO sovrascriva la email inserita qui
        $stmt_admin = $conn->prepare("INSERT INTO utenti (ruolo_id, nome, cognome, email, codice_fiscale, email_personalizzata) VALUES (1, ?, ?, ?, ?, 1)");
        $stmt_admin->bind_param("ssss", $admin_nome, $admin_cognome, $admin_email, $admin_cf);
        $stmt_admin->execute();

        // F. INSERIMENTO CONFIGURAZIONI DI SISTEMA VUOTE (Riga 1)
        $conn->query("INSERT IGNORE INTO `configurazione_portale` (`id`) VALUES (1)");
        $conn->query("INSERT IGNORE INTO `impostazioni_sistema` (`id`) VALUES (1)");

        // G. CREAZIONE DEL FILE CONFIG.PHP FISICO
        $config_content = "<?php\n";
        $config_content .= "// File autogenerato da EventiDiBEST Installer\n";
        $config_content .= "if (session_status() === PHP_SESSION_NONE) { session_start(); }\n\n";
        $config_content .= "// Dati Connessione Database\n";
        $config_content .= "\$db_host = '" . addslashes($db_host) . "';\n";
        $config_content .= "\$db_user = '" . addslashes($db_user) . "';\n";
        $config_content .= "\$db_pass = '" . addslashes($db_pass) . "';\n";
        $config_content .= "\$db_name = '" . addslashes($db_name) . "';\n\n";
        $config_content .= "\$conn = new mysqli(\$db_host, \$db_user, \$db_pass, \$db_name);\n";
        $config_content .= "if (\$conn->connect_error) { die('Errore connessione database: ' . \$conn->connect_error); }\n";
        $config_content .= "\$conn->set_charset('utf8mb4');\n";
        $config_content .= "?>";

        if (file_put_contents('config.php', $config_content)) {
            $step = 2; // Passa alla schermata di successo
        } else {
            $msg = "❌ Database creato, ma impossibile scrivere il file <code>config.php</code>. Controlla i permessi della cartella (CHMOD 777).";
            $msg_type = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installazione - EventiDiBEST CMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; }
        .install-box { max-width: 700px; margin: 40px auto; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); overflow: hidden; border: 1px solid #e2e8f0; }
        .install-header { background: #990000; color: white; padding: 30px; text-align: center; border-bottom: 5px solid #700000; }
    </style>
</head>
<body>

<div class="install-box">
    <div class="install-header">
        <i class="fa fa-cogs fa-3x mb-3"></i>
        <h2 class="fw-bold m-0">EventiDiBEST - Setup Installer</h2>
        <p class="m-0 mt-2 opacity-75">Configurazione iniziale del CMS e del Database</p>
    </div>

    <div class="p-4 p-md-5">
        
        <?php if ($msg): ?>
            <div class="alert alert-<?php echo $msg_type; ?> shadow-sm fw-bold mb-4">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST" action="install.php">
                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa fa-database me-2 text-primary"></i> 1. Credenziali Database MySQL</h5>
                <p class="small text-muted mb-3">Inserisci i parametri per connettersi al database. Se il database non esiste, lo script tenterà di crearlo automaticamente.</p>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Host Database</label>
                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Nome Database (da creare)</label>
                        <input type="text" name="db_name" class="form-control" value="eventidibest_db" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Utente Database</label>
                        <input type="text" name="db_user" class="form-control" value="root" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Password Database</label>
                        <input type="password" name="db_pass" class="form-control" placeholder="(Lascia vuoto se in locale XAMPP)">
                    </div>
                </div>

                <h5 class="fw-bold text-dark border-bottom pb-2 mb-3 mt-4"><i class="fa fa-user-shield me-2 text-danger"></i> 2. Profilo Super Amministratore</h5>
                <div class="alert alert-info small mb-3 py-2">
                    <i class="fa fa-info-circle me-1"></i> L'accesso al portale avviene tramite <strong>SSO Unical</strong>. Il sistema ti riconoscerà come Admin confrontando il <strong>Codice Fiscale</strong> che inserisci qui con quello fornito dal Single Sign-On. <strong>Se lasci il CF vuoto non potrai mai accedere come Amministratore.</strong>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Nome Admin</label>
                        <input type="text" name="admin_nome" class="form-control" placeholder="Es. Mario" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Cognome Admin</label>
                        <input type="text" name="admin_cognome" class="form-control" placeholder="Es. Rossi" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Email Istituzionale</label>
                        <input type="email" name="admin_email" class="form-control" placeholder="mario.rossi@unical.it" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Codice Fiscale <span class="text-danger">*</span></label>
                        <input type="text" name="admin_cf" class="form-control text-uppercase" placeholder="RSSMRA80A01H501Z" maxlength="16" required
                               oninput="this.value=this.value.toUpperCase()">
                        <div class="form-text text-danger fw-bold">Obbligatorio per il riconoscimento via SSO</div>
                    </div>
                </div>

                <div class="alert alert-warning small border-warning mt-4">
                    <i class="fa fa-exclamation-triangle me-1"></i> Premendo su Installa, verranno create tutte le tabelle. L'operazione potrebbe impiegare qualche secondo.
                </div>

                <button type="submit" name="esegui_installazione" class="btn btn-danger btn-lg w-100 fw-bold shadow-sm mt-2" style="background-color: #990000; border:none;">
                    <i class="fa fa-magic me-2"></i> Avvia Installazione CMS
                </button>
            </form>

        <?php elseif ($step === 2): ?>
            
            <div class="text-center py-4">
                <i class="fa fa-check-circle text-success mb-3" style="font-size: 5rem;"></i>
                <h2 class="fw-bold text-dark mb-2">Installazione Completata!</h2>
                <p class="text-secondary fs-5 mb-4">Il database è stato strutturato e il file <code>config.php</code> è stato generato con successo.</p>

                <div class="alert alert-danger fw-bold border-danger text-start d-inline-block px-4 py-3 shadow-sm mb-4">
                    <i class="fa fa-shield-alt me-2 fs-5 align-middle"></i> 
                    Per motivi di sicurezza, ELIMINA o RINOMINA il file <code>install.php</code> dal server immediatamente.
                </div>

                <div class="d-flex justify-content-center gap-3">
                    <a href="index.php" class="btn btn-primary btn-lg fw-bold px-4 shadow-sm"><i class="fa fa-globe me-2"></i> Vai al Portale</a>
                    <a href="admin/index.php" class="btn btn-dark btn-lg fw-bold px-4 shadow-sm"><i class="fa fa-cogs me-2"></i> Pannello Admin</a>
                </div>
            </div>

        <?php endif; ?>

    </div>
</div>

</body>
</html>
