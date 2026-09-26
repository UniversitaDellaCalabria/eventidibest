<?php
// eventi.php - Gestione Eventi e Turni (RBAC Pulito e Isolamento Eventi)
require_once 'admin_header.php';

if (!$can_manage_eventi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire gli eventi in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

// Filtro evento anche nei POST (i form lo inviano come campo nascosto), per tornare alla stessa vista
$filtro_ev = isset($_GET['f_ev']) ? (int)$_GET['f_ev'] : (int)($_POST['f_ev'] ?? 0);

// Mostra l'errore invece della pagina bianca
set_exception_handler(function (Throwable $e) {
    error_log('[admin/eventi.php] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-bug me-2'></i>Errore durante il salvataggio: "
       . htmlspecialchars($e->getMessage()) . " <small class='d-block fw-normal mt-1'>(riga " . (int)$e->getLine() . ")</small></div>";
    exit;
});

// Legge i campi turno dal POST: stringhe vuote -> NULL. Ritorna null se mancano sia nome che data.
function leggi_turno_post(): ?array {
    $v = fn($k) => (isset($_POST[$k]) && trim($_POST[$k]) !== '') ? trim($_POST[$k]) : null;
    $t = [
        'nome'  => $v('nome_turno'),
        'data'  => $v('data_turno'),
        'in'    => $v('orario_inizio'),
        'fi'    => $v('orario_fine'),
        'max'   => (int)($_POST['max_posti'] ?? 30),
        'ap'    => $v('data_apertura'),
        'ch'    => $v('data_chiusura'),
        'wa'    => isset($_POST['abilita_lista_attesa']) ? 1 : 0,
        'mp'    => isset($_POST['abilita_multi_posto']) ? 1 : 0,
        'app'   => isset($_POST['richiede_approvazione']) ? 1 : 0,
    ];
    return ($t['nome'] === null && $t['data'] === null) ? null : $t;
}

function inserisci_turno($conn, int $ev_id, array $t): void {
    $stmt = $conn->prepare("INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssissiii", $ev_id, $t['nome'], $t['data'], $t['in'], $t['fi'], $t['max'], $t['ap'], $t['ch'], $t['wa'], $t['mp'], $t['app']);
    $stmt->execute();
}


if (isset($_POST['duplica_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['duplica_turno'];
    if (!turno_autorizzato($conn, $t_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $r_ev = $conn->query("SELECT evento_id FROM turni WHERE id = $t_id");
    $ev_id = (int)($r_ev->fetch_assoc()['evento_id'] ?? 0);
    $nuovo = duplica_turno($conn, $t_id, $ev_id, true);
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Duplicazione Turno", ["Turno origine" => $t_id, "Nuovo turno" => $nuovo]);
    flash_set("Turno duplicato: modifica ora data e orari della copia.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev&apri=modTurno$nuovo");
}

if (isset($_POST['duplica_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['duplica_evento'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    try {
        $copia = duplica_evento($conn, $ev_id, true);
    } catch (Throwable $e) {
        error_log('[duplica_evento] ' . $e->getMessage());
        flash_set("Duplicazione non riuscita: " . htmlspecialchars($e->getMessage()), 'danger');
        admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
    }
    [$nuovo_ev, $n_turni, $n_sond] = [$copia['evento'], $copia['turni'], $copia['sondaggi']];
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Duplicazione Evento", ["Evento origine" => $ev_id, "Nuovo evento" => $nuovo_ev, "Turni" => $n_turni, "Sondaggi" => $n_sond]);
    flash_set("Evento duplicato con $n_turni turni" . ($n_sond ? " e $n_sond sondaggio" . ($n_sond > 1 ? "i" : "") . " (da attivare)" : "") . ", senza iscritti: controlla titolo e date della copia.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev&apri=modEv$nuovo_ev");
}


// Sezione "affiancata in alto" nel layout Griglia (attiva/disattiva)
if (isset($_POST['toggle_sezione_alto'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sub_id = (int)$_POST['toggle_sezione_alto'];
    $val = (int)($_POST['val'] ?? 0) === 1 ? 1 : 0;
    $conn->query("UPDATE sottocategorie SET affiancata_in_alto = $val WHERE id = $sub_id AND pagina_id = $filtro_p");
    flash_set($val ? "La sezione sarà mostrata in alto, affiancata alle altre (layout Griglia)." : "La sezione tornerà nell'elenco normale.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['add_sottocategoria'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_id = $filtro_p; // i permessi sono calcolati sull'area corrente: non fidarsi del campo POST
    $nome_sub = $_POST['nome_sottocategoria'] ?? '';
    $ord_sub = (int)($_POST['ordine_sottocategoria'] ?? 0);
    $affiancata = isset($_POST['affiancata_in_alto']) ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO sottocategorie (pagina_id, nome, ordine, affiancata_in_alto) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isii", $p_id, $nome_sub, $ord_sub, $affiancata);
    $stmt->execute();
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Creazione Sezione", ["Nome" => $_POST['nome_sottocategoria']]);
    flash_set("Sezione creata con successo!");
    admin_redirect("eventi.php?p_id=$p_id");
}

if (isset($_POST['add_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sub_id = !empty($_POST['sottocategoria_id']) ? (int)$_POST['sottocategoria_id'] : null;
    $titolo = $_POST['titolo'] ?? '';
    $luogo = $_POST['luogo'] ?? '';
    $desc = $_POST['descrizione'] ?? '';
    $ord = (int)($_POST['ordine_evento'] ?? 0);
    $evid = isset($_POST['is_evidenza']) ? 1 : 0;
    $req_pren = isset($_POST['richiede_prenotazione']) ? 1 : 0;
    $abilita_pres = isset($_POST['abilita_presenze']) ? 1 : 0;
    $ruolo_acc = (int)($_POST['ruolo_accesso_id'] ?? 0);

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $locandina_path = "";
    if (isset($_FILES['locandina_file'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $locandina_path = "uploads/$fn";
    }

    $allegato_pdf = null;
    if (isset($_FILES['allegato_pdf'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $allegato_pdf = "uploads/$fn";
    }

    $stmt_ev = $conn->prepare("INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, luogo, descrizione, locandina_path, allegato_pdf, is_evidenza, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine, gestori_utenti_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')");
    $stmt_ev->bind_param("iisssssiiiii", $filtro_p, $sub_id, $titolo, $luogo, $desc, $locandina_path, $allegato_pdf, $evid, $req_pren, $abilita_pres, $ruolo_acc, $ord);
    $stmt_ev->execute();
    $ev_id = $conn->insert_id;
    // Indirizzi aggiuntivi per le notifiche delle prenotazioni (validati, max 10)
    $notif_extra = normalizza_lista_email($_POST['email_notifiche_extra'] ?? '', 10, $notif_scartati);
    $notif_csv = $notif_extra ? implode(',', $notif_extra) : null;
    $stmt_nx = $conn->prepare("UPDATE eventi SET email_notifiche_extra = ? WHERE id = ?");
    $stmt_nx->bind_param("si", $notif_csv, $ev_id);
    $stmt_nx->execute();
    $avviso_notif = $notif_scartati ? " Indirizzi non validi ignorati: " . htmlspecialchars(implode(', ', $notif_scartati)) . "." : '';

    $primo_turno = leggi_turno_post();
    if ($primo_turno) inserisci_turno($conn, $ev_id, $primo_turno);
    
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Creazione Evento", ["Evento ID" => $ev_id]);
    flash_set("Evento e turni creati!" . $avviso_notif, $avviso_notif ? 'warning' : 'success');
    admin_redirect("eventi.php?p_id=$filtro_p");
}

if (isset($_POST['edit_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['evento_id'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $sub_id = !empty($_POST['sottocategoria_id']) ? (int)$_POST['sottocategoria_id'] : null;
    $titolo = $_POST['titolo'] ?? '';
    $luogo = $_POST['luogo'] ?? '';
    $desc = $_POST['descrizione'] ?? '';
    $ord = (int)($_POST['ordine_evento'] ?? 0);
    $evid = isset($_POST['is_evidenza']) ? 1 : 0;
    $req_pren = isset($_POST['richiede_prenotazione']) ? 1 : 0;
    $abilita_pres = isset($_POST['abilita_presenze']) ? 1 : 0;
    $ruolo_acc = (int)($_POST['ruolo_accesso_id'] ?? 0);

    if (isset($_POST['elimina_locandina']) && $_POST['elimina_locandina'] == '1') $conn->query("UPDATE eventi SET locandina_path = NULL WHERE id = $ev_id");
    if (isset($_POST['elimina_pdf']) && $_POST['elimina_pdf'] == '1') $conn->query("UPDATE eventi SET allegato_pdf = NULL WHERE id = $ev_id");

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $new_locandina = null;
    if (isset($_FILES['locandina_file'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $new_locandina = "uploads/$fn";
    }

    $new_pdf = null;
    if (isset($_FILES['allegato_pdf'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $new_pdf = "uploads/$fn";
    }

    $sql_upd = "UPDATE eventi SET sottocategoria_id=?, titolo=?, luogo=?, descrizione=?, ordine=?, is_evidenza=?, richiede_prenotazione=?, abilita_presenze=?, ruolo_accesso_id=?";
    $types = "isssiiiii";
    $params = [$sub_id, $titolo, $luogo, $desc, $ord, $evid, $req_pren, $abilita_pres, $ruolo_acc];
    if ($new_locandina !== null) { $sql_upd .= ", locandina_path=?"; $types .= "s"; $params[] = $new_locandina; }
    if ($new_pdf !== null) { $sql_upd .= ", allegato_pdf=?"; $types .= "s"; $params[] = $new_pdf; }
    $sql_upd .= " WHERE id=?";
    $types .= "i";
    $params[] = $ev_id;
    $stmt_upd = $conn->prepare($sql_upd);
    $stmt_upd->bind_param($types, ...$params);
    $stmt_upd->execute();
    // Indirizzi aggiuntivi per le notifiche delle prenotazioni (validati, max 10)
    $notif_extra = normalizza_lista_email($_POST['email_notifiche_extra'] ?? '', 10, $notif_scartati);
    $notif_csv = $notif_extra ? implode(',', $notif_extra) : null;
    $stmt_nx = $conn->prepare("UPDATE eventi SET email_notifiche_extra = ? WHERE id = ?");
    $stmt_nx->bind_param("si", $notif_csv, $ev_id);
    $stmt_nx->execute();
    $avviso_notif = $notif_scartati ? " Indirizzi non validi ignorati: " . htmlspecialchars(implode(', ', $notif_scartati)) . "." : '';
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Evento", ["Evento ID" => $ev_id]);
    flash_set("Evento modificato!" . $avviso_notif, $avviso_notif ? 'warning' : 'success');
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['add_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['evento_id'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $nt = leggi_turno_post();
    if (!$nt) {
        flash_set("Inserisci almeno il nome del turno oppure la data.", "danger");
        admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
    }
    inserisci_turno($conn, $ev_id, $nt);
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Aggiunta Turno", ["Evento ID" => $ev_id, "Turno" => etichetta_turno(['nome_turno' => $nt['nome'], 'data_turno' => $nt['data'], 'orario_inizio' => $nt['in'], 'orario_fine' => $nt['fi']]), "Max Posti" => $nt['max']]);
    flash_set("Turno aggiunto!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['edit_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['turno_id'];
    if (!turno_autorizzato($conn, $t_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $et = leggi_turno_post();
    if (!$et) {
        flash_set("Inserisci almeno il nome del turno oppure la data.", "danger");
        admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
    }
    $max_p = $et['max'];

    $stmt_et = $conn->prepare("UPDATE turni SET nome_turno=?, data_turno=?, orario_inizio=?, orario_fine=?, max_posti=?, data_apertura=?, data_chiusura=?, abilita_lista_attesa=?, abilita_multi_posto=?, richiede_approvazione=? WHERE id=?");
    $stmt_et->bind_param("ssssissiiii", $et['nome'], $et['data'], $et['in'], $et['fi'], $max_p, $et['ap'], $et['ch'], $et['wa'], $et['mp'], $et['app'], $t_id);
    $stmt_et->execute();
    
    // LOGICA AUTOMATICA PROMOZIONE
    $res_occ = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as tot FROM prenotazioni WHERE turno_id = $t_id AND stato = 'confermata'");
    $occupati = ($res_occ && $row_occ = $res_occ->fetch_assoc()) ? (int)$row_occ['tot'] : 0;
    $posti_liberi = $max_p - $occupati;
    $promossi = 0;
    
    if ($posti_liberi > 0) {
        $res_attesa = $conn->query("SELECT p.*, e.titolo as evento_titolo, e.luogo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE p.turno_id = $t_id AND p.stato = 'in_attesa' ORDER BY p.data_prenotazione ASC, p.id ASC");
        if ($res_attesa && $res_attesa->num_rows > 0) {
            if (file_exists(dirname(__DIR__) . '/functions.php')) require_once dirname(__DIR__) . '/functions.php';
            while ($pren = $res_attesa->fetch_assoc()) {
                $posti_richiesti = (int)$pren['num_posti'];
                if ($posti_liberi >= $posti_richiesti) {
                    $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = {$pren['id']}");
                    decadi_attese_vincolate($conn, (int)$pren['id']);
                    $posti_liberi -= $posti_richiesti; $promossi++;
                    if (function_exists('inviaNotificaEmail')) {
                        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                        $link = $proto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\') . "/stampa_ricevuta.php?code=" . urlencode($pren['codice_prenotazione']);
                        $btn = "<p><a href='$link' style='background:#B80000; color:#fff; padding:10px; border-radius:6px; text-decoration:none;'>Scarica Ricevuta</a></p>";
                        $body = "<p>Gentile " . htmlspecialchars($pren['nome']) . ", la tua prenotazione in lista d'attesa è stata CONFERMATA per l'evento " . htmlspecialchars($pren['evento_titolo']) . ".</p>" . $btn;
                        inviaNotificaEmail($pren['email'], "Posto Confermato: " . $pren['evento_titolo'], $body, $conn, colore_area_turno($conn, $t_id));
                    }
                } else break;
            }
        }
    }
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Turno", ["Turno ID" => $t_id, "Nome" => $et['nome'], "Data" => $et['data'], "Max Posti" => $max_p]);
    flash_set("Turno aggiornato!" . ($promossi > 0 ? " (Aggiunti $promossi utenti dalla Lista d'Attesa!)" : ""));
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['archivia_conclusi'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE eventi e SET archiviato = 1 WHERE pagina_id = $filtro_p AND NOT EXISTS (SELECT 1 FROM turni t WHERE t.evento_id = e.id AND (t.data_turno IS NULL OR t.data_turno >= CURDATE() OR (t.data_chiusura IS NOT NULL AND t.data_chiusura >= '$now')))");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Archiviazione Bulk Eventi", ["Pagina ID" => $filtro_p]);
    flash_set("Eventi passati archiviati.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['archivia_ev'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $arch_ev_id = (int)$_POST['archivia_ev'];
    if (!ev_autorizzato($conn, $arch_ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $conn->query("UPDATE eventi SET archiviato = 1, blocca_auto_archivio = 0 WHERE id = $arch_ev_id");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Archiviazione Evento", ["Evento ID" => $arch_ev_id]);
    flash_set("Evento archiviato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['del_ev'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['del_ev'];
    if (!ev_autorizzato($conn, $ev_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    $res_ev_info = $conn->query("SELECT titolo FROM eventi WHERE id = $ev_id");
    $ev_titolo_log = ($res_ev_info && $r_log = $res_ev_info->fetch_assoc()) ? $r_log['titolo'] : '';
    $ok_del = elimina_evento($conn, $ev_id); // turni, prenotazioni, messaggi, campi form, sondaggi con domande e risposte
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Evento", ["Evento ID" => $ev_id, "Titolo" => $ev_titolo_log]);
    flash_set($ok_del ? "Evento eliminato!" : "Eliminazione non riuscita: riprova.", $ok_del ? 'success' : 'danger');
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['del_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['del_turno'];
    if (!turno_autorizzato($conn, $t_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso();
    elimina_turno($conn, $t_id); // prenotazioni e messaggi collegati compresi
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Turno", ["Turno ID" => $t_id]);
    flash_set("Turno eliminato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

$sottocategorie = get_sottocategorie($conn, $filtro_p);
$ruoli          = get_ruoli($conn);

$eventi = []; $tutti_gli_eventi = [];
$filtro_ev = isset($_GET['f_ev']) ? (int)$_GET['f_ev'] : 0;

// ESTRAZIONE CON APPLICAZIONE VARIABILE MAGICA RBAC
// I progetti (tipo = 'progetto') hanno la loro scheda in progetti.php
$res_ev = $conn->query("SELECT e.*, sc.nome as nome_sottocategoria FROM eventi e LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id WHERE e.pagina_id = $filtro_p AND e.archiviato = 0 AND IFNULL(e.tipo, 'evento') <> 'progetto' $sql_filtro_eventi_rbac ORDER BY sc.ordine ASC, e.ordine ASC, e.id DESC");
$res_np = $conn->query("SELECT COUNT(*) AS n FROM eventi e WHERE e.pagina_id = $filtro_p AND e.archiviato = 0 AND e.tipo = 'progetto' $sql_filtro_eventi_rbac");
$n_progetti = $res_np ? (int)$res_np->fetch_assoc()['n'] : 0;

if ($res_ev) {
    while($row = $res_ev->fetch_assoc()) {
        $turni = [];
        $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$row['id']} ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC");
        if($res_t) { while($t = $res_t->fetch_assoc()) $turni[] = $t; }
        $row['turni'] = $turni;
        $tutti_gli_eventi[] = $row;
        if ($filtro_ev === 0 || $filtro_ev == $row['id']) { $eventi[] = $row; }
    }
}
?>

<?php
$col_area = htmlspecialchars($page_cfg['colore_primario'] ?? '#0056b3');
?>
<style>
.ev-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.06); transition:box-shadow .2s; overflow:hidden; }
.ev-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.10); }
.ev-card-accent { width:5px; flex-shrink:0; border-radius:0; }
.turno-chip { display:flex; align-items:center; justify-content:space-between; gap:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; font-size:.82rem; }
.turno-chip:hover { background:#f1f5f9; }
.add-turno-toggle { background:none; border:1px dashed #94a3b8; color:#64748b; border-radius:8px; padding:7px 16px; font-size:.82rem; font-weight:600; cursor:pointer; width:100%; transition:all .15s; }
.add-turno-toggle:hover { background:#f1f5f9; border-color:#475569; color:#1e293b; }
</style>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-calendar-alt me-2" style="color:<?php echo $col_area; ?>"></i>Gestione Eventi e Turni</h4>
    <div class="d-flex gap-2 align-items-center">
        <form method="GET" id="formFiltroEv" class="d-flex align-items-center gap-2 m-0">
            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
            <i class="fa fa-filter text-secondary"></i>
            <select name="f_ev" class="form-select form-select-sm fw-bold border-0 shadow-sm" style="min-width:200px;" onchange="document.getElementById('formFiltroEv').submit();">
                <option value="0">Tutti i tuoi Eventi</option>
                <?php foreach($tutti_gli_eventi as $e_opt): ?>
                    <option value="<?php echo $e_opt['id']; ?>" <?php echo $filtro_ev == $e_opt['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e_opt['titolo']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($can_manage_settings): ?>
            <form method="POST" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <button type="submit" name="archivia_conclusi" class="btn btn-outline-secondary btn-sm fw-bold" data-confirm="Archiviare tutti gli eventi passati?" title="Archivia conclusi"><i class="fa fa-archive me-1"></i>Archivia vecchi</button>
            </form>
        <?php endif; ?>
        <?php if ($can_manage_eventi && $is_full_admin): ?>
            <button type="button" class="btn btn-sm fw-bold px-3 shadow-sm text-white" data-bs-toggle="modal" data-bs-target="#modCreaEvento" style="background:<?php echo $col_area; ?>;border:none;border-radius:8px;"><i class="fa fa-plus-circle me-1"></i>Crea Evento</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-0">
    <?php if ($can_manage_settings): ?>
    <div class="col-md-3 pe-md-3 mb-4">
        <div class="ev-card p-3" style="border-top:3px solid <?php echo $col_area; ?>;">
            <div class="fw-bold mb-3 text-uppercase" style="font-size:.72rem;letter-spacing:.06em;color:<?php echo $col_area; ?>;"><i class="fa fa-tags me-1"></i>Sottocategorie / Sezioni</div>
            <form method="POST" class="mb-3">
                <?php csrf_field(); ?>
                <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
                <div class="d-flex gap-1 mb-2">
                    <input type="text" name="nome_sottocategoria" class="form-control form-control-sm" placeholder="Nome sezione..." required>
                    <input type="number" name="ordine_sottocategoria" class="form-control form-control-sm" value="0" style="width:60px;" required>
                </div>
                <div class="form-check mb-2" style="font-size:.78rem;">
                    <input class="form-check-input" type="checkbox" name="affiancata_in_alto" value="1" id="nuovaSezAlto">
                    <label class="form-check-label" for="nuovaSezAlto">Affiancata in alto (layout Griglia)</label>
                </div>
                <button type="submit" name="add_sottocategoria" class="btn btn-sm w-100 fw-bold text-white" style="background:<?php echo $col_area; ?>;border-radius:7px;">Aggiungi</button>
            </form>
            <div class="d-flex flex-column gap-1">
                <?php foreach($sottocategorie as $sub): ?>
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:.82rem;">
                        <span class="badge text-white fw-bold" style="background:<?php echo $col_area; ?>;min-width:24px;"><?php echo $sub['ordine']; ?></span>
                        <span class="fw-semibold text-dark"><?php echo htmlspecialchars($sub['nome'] ?? ''); ?></span>
                        <form method="POST" class="ms-auto m-0">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="toggle_sezione_alto" value="<?php echo (int)$sub['id']; ?>">
                            <input type="hidden" name="val" value="<?php echo !empty($sub['affiancata_in_alto']) ? 0 : 1; ?>">
                            <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                            <button type="submit" class="btn btn-sm py-0 px-2 <?php echo !empty($sub['affiancata_in_alto']) ? 'btn-dark' : 'btn-outline-secondary'; ?>" style="font-size:.68rem;border-radius:6px;" title="Nel layout Griglia: mostra questa sezione in alto, affiancata alle altre sezioni con la stessa opzione" aria-pressed="<?php echo !empty($sub['affiancata_in_alto']) ? 'true' : 'false'; ?>"><i class="fa fa-table-columns me-1" aria-hidden="true"></i>In alto</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if(empty($sottocategorie)): ?>
                    <div class="text-muted small text-center py-2">Nessuna sezione</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?php echo $can_manage_settings ? 'col-md-9' : 'col-12'; ?>">
        <?php if ($n_progetti > 0): ?>
            <div class="alert alert-light border small py-2 mb-3"><i class="fa fa-diagram-project me-1" aria-hidden="true"></i>In quest'area ci sono <?php echo $n_progetti; ?> <?php echo $n_progetti === 1 ? 'progetto' : 'progetti'; ?>: li gestisci da <a href="progetti.php?p_id=<?php echo $filtro_p; ?>" class="fw-bold">Progetti</a>.</div>
        <?php endif; ?>
        <?php if(empty($eventi)): ?>
            <div class="ev-card p-5 text-center text-muted">
                <i class="fa fa-folder-open fs-1 mb-3 d-block" style="opacity:.3;"></i>
                <div class="fw-semibold">Nessun evento trovato.</div>
            </div>
        <?php else: ?>
        <div class="d-flex flex-column gap-3">
        <?php foreach($eventi as $ev): ?>
            <div class="ev-card d-flex">
                <!-- Striscia colore sinistra -->
                <div class="ev-card-accent" style="background:<?php echo $col_area; ?>;"></div>

                <div class="flex-grow-1 p-3">
                    <!-- ── HEADER EVENTO ── -->
                    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                        <div>
                            <?php if($ev['is_evidenza']): ?>
                                <span class="badge bg-warning text-dark me-1" style="font-size:.65rem;">⭐ EVIDENZA</span>
                            <?php endif; ?>
                            <span class="fw-bold fs-6 text-dark"><?php echo htmlspecialchars($ev['titolo'] ?? ''); ?></span>
                            <div class="text-muted mt-1 d-flex flex-wrap gap-2" style="font-size:.8rem;">
                                <span><i class="fa fa-folder me-1 text-secondary"></i><?php echo $ev['nome_sottocategoria'] ? htmlspecialchars($ev['nome_sottocategoria']) : 'Nessuna Sezione'; ?></span>
                                <?php if(!empty($ev['luogo'])): ?>
                                    <span><i class="fa fa-map-marker-alt me-1 text-secondary"></i><?php echo htmlspecialchars($ev['luogo']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Bottoni azione -->
                        <div class="d-flex gap-1 flex-shrink-0">
                            <button type="button" class="btn btn-sm btn-outline-primary" style="border-radius:8px;width:34px;height:34px;padding:0;" data-bs-toggle="modal" data-bs-target="#modEv<?php echo $ev['id']; ?>" title="Modifica evento"><i class="fa fa-edit" style="font-size:.85rem;"></i></button>
                            <form method="POST" class="d-inline m-0">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="duplica_evento" value="<?php echo $ev['id']; ?>">
                                <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;width:34px;height:34px;padding:0;" data-confirm="Duplicare questo evento con turni, campi del form e sondaggio? Gli iscritti e le risposte non vengono copiati." title="Duplica evento" aria-label="Duplica evento"><i class="fa fa-copy" aria-hidden="true"></i></button>
                            </form>
                            <?php if ($can_manage_settings): ?>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="archivia_ev" value="<?php echo $ev['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning text-dark" style="border-radius:8px;width:34px;height:34px;padding:0;" data-confirm="Archiviare questo evento?" title="Archivia"><i class="fa fa-archive" style="font-size:.85rem;"></i></button>
                                </form>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="del_ev" value="<?php echo $ev['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" style="border-radius:8px;width:34px;height:34px;padding:0;" data-confirm="Eliminare questo evento e tutti i suoi turni e prenotazioni?" title="Elimina"><i class="fa fa-trash" style="font-size:.85rem;"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Badge features -->
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <?php if($ev['richiede_prenotazione'] == 0): ?>
                            <span class="badge" style="background:#dcfce7;color:#166534;font-size:.68rem;">Accesso Libero</span>
                        <?php else: ?>
                            <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.68rem;">Prenotabile</span>
                        <?php endif; ?>
                        <?php if(($ev['abilita_presenze'] ?? 1) == 1): ?>
                            <span class="badge" style="background:#ede9fe;color:#5b21b6;font-size:.68rem;"><i class="fa fa-qrcode me-1"></i>Check-in</span>
                        <?php endif; ?>
                        <?php if(!empty($ev['locandina_path'])): ?>
                            <span class="badge" style="background:#fef9c3;color:#854d0e;font-size:.68rem;"><i class="fa fa-image me-1"></i>Locandina</span>
                        <?php endif; ?>
                        <?php if(!empty($ev['allegato_pdf'])): ?>
                            <span class="badge" style="background:#f1f5f9;color:#475569;font-size:.68rem;"><i class="fa fa-file-pdf me-1"></i>PDF</span>
                        <?php endif; ?>
                        <?php $notif_extra_ev = normalizza_lista_email($ev['email_notifiche_extra'] ?? ''); if ($notif_extra_ev): ?>
                            <span class="badge" style="background:#e0e7ff;color:#3730a3;font-size:.68rem;" title="<?php echo htmlspecialchars(implode(', ', $notif_extra_ev)); ?>"><i class="fa fa-envelope me-1" aria-hidden="true"></i>Notifiche in copia: <?php echo count($notif_extra_ev); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- ── TURNI ESISTENTI ── -->
                    <?php if(!empty($ev['turni'])): ?>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <?php foreach($ev['turni'] as $t):
                            $t_passato = turno_concluso($t);
                        ?>
                        <div class="turno-chip <?php echo $t_passato ? 'opacity-50' : ''; ?>">
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <?php if (!empty($t['nome_turno'])): ?>
                                    <span class="fw-bold text-dark" style="font-size:.85rem;"><i class="fa fa-tag me-1 text-secondary"></i><?php echo htmlspecialchars($t['nome_turno']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($t['data_turno'])): ?>
                                    <span class="fw-bold" style="color:<?php echo $col_area; ?>;font-size:.85rem;">
                                        <i class="fa fa-calendar me-1"></i><?php echo date('d/m/Y', strtotime($t['data_turno'])); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (orario_turno($t) !== ''): ?>
                                    <span class="text-dark" style="font-size:.82rem;">
                                        <i class="fa fa-clock text-secondary me-1"></i><?php echo orario_turno($t); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="badge" style="background:#f1f5f9;color:#334155;font-size:.72rem;font-weight:600;">
                                    <i class="fa fa-users me-1"></i><?php echo $t['max_posti']; ?> posti
                                </span>
                                <?php if($t['abilita_lista_attesa']): ?><span class="badge" style="background:#fef3c7;color:#92400e;font-size:.68rem;">L. Attesa</span><?php endif; ?>
                                <?php if($t['abilita_multi_posto']): ?><span class="badge" style="background:#e0f2fe;color:#0c4a6e;font-size:.68rem;">Multi-Posto</span><?php endif; ?>
                                <?php if($t['richiede_approvazione']): ?><span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.68rem;">Approvazione</span><?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <a href="stampa_qr_aula.php?t_id=<?php echo $t['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-sm btn-outline-success py-0 px-2 fw-bold" style="border-radius:6px;font-size:.75rem;" title="QR Aula"><i class="fa fa-qrcode me-1"></i>QR Aula</a>
                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" style="border-radius:6px;" data-bs-toggle="modal" data-bs-target="#modTurno<?php echo $t['id']; ?>" title="Modifica turno"><i class="fa fa-edit"></i></button>
<form method="POST" class="d-inline m-0">                                    <?php csrf_field(); ?>                                    <input type="hidden" name="duplica_turno" value="<?php echo $t['id']; ?>">                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">                                    <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" style="border-radius:6px;" title="Duplica turno" aria-label="Duplica turno"><i class="fa fa-copy" aria-hidden="true"></i></button>                                </form>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="del_turno" value="<?php echo $t['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="border-radius:6px;" data-confirm="Eliminare questo turno?" title="Elimina turno"><i class="fa fa-times"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── AGGIUNGI TURNO (collassabile) ── -->
                    <div>
                        <button class="add-turno-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#addTurno<?php echo $ev['id']; ?>">
                            <i class="fa fa-plus me-1"></i> Aggiungi Turno
                        </button>
                        <div class="collapse mt-2" id="addTurno<?php echo $ev['id']; ?>">
                            <form method="POST" class="p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="evento_id" value="<?php echo $ev['id']; ?>">
                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                <div class="row g-2 align-items-end mb-2">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-bold mb-1">Nome turno</label>
                                        <input type="text" name="nome_turno" class="form-control form-control-sm" placeholder="Es. Gruppo 1" maxlength="150">
                                    </div>
                                    <div class="col-6 col-md-2">
                                        <label class="form-label small fw-bold mb-1">Data</label>
                                        <input type="date" name="data_turno" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-3 col-md-1">
                                        <label class="form-label small fw-bold mb-1">Inizio</label>
                                        <input type="time" name="orario_inizio" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-3 col-md-1">
                                        <label class="form-label small fw-bold mb-1">Fine</label>
                                        <input type="time" name="orario_fine" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-4 col-md-1">
                                        <label class="form-label small fw-bold mb-1">Posti</label>
                                        <input type="number" name="max_posti" class="form-control form-control-sm" value="30" required>
                                    </div>
                                    <div class="col-4 col-md-2">
                                        <label class="form-label small fw-bold mb-1">Ap. Pren.</label>
                                        <input type="datetime-local" name="data_apertura" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-4 col-md-2">
                                        <label class="form-label small fw-bold mb-1">Ch. Pren.</label>
                                        <input type="datetime-local" name="data_chiusura" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-12"><small class="text-muted">Compila almeno il nome oppure la data. Orari facoltativi.</small></div>
                                </div>
                                <div class="d-flex align-items-center flex-wrap gap-3 justify-content-between">
                                    <div class="d-flex gap-3 flex-wrap">
                                        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="abilita_lista_attesa" id="waAdd<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-warning" for="waAdd<?php echo $ev['id']; ?>">Lista Attesa</label></div>
                                        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="abilita_multi_posto" id="mpAdd<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-info" for="mpAdd<?php echo $ev['id']; ?>">Multi-Posto</label></div>
                                        <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="richiede_approvazione" id="apprAdd<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-danger" for="apprAdd<?php echo $ev['id']; ?>">Approvazione</label></div>
                                    </div>
                                    <button type="submit" name="add_turno" class="btn btn-success btn-sm fw-bold px-4" style="border-radius:7px;"><i class="fa fa-plus me-1"></i>Salva Turno</button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modale CREA EVENTO -->
<div class="modal fade" id="modCreaEvento" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-0">
            <form method="POST" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
                <div class="modal-header py-3 bg-danger text-white border-bottom-0">
                    <h6 class="modal-title fw-bold fs-5"><i class="fa fa-plus-circle me-2"></i> Crea Nuovo Evento in: <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light text-start">
                    <div class="row bg-white p-3 rounded mb-4 shadow-sm border border-secondary">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Informazioni Generali Evento</h6>
                        <div class="col-md-3 mb-2"><label class="form-label small fw-bold">Sottocategoria</label>
                            <select name="sottocategoria_id" class="form-select form-select-sm border-primary">
                                <option value="">-- Nessuna --</option>
                                <?php foreach ($sottocategorie as $sub): ?><option value="<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['nome'] ?? ''); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label class="form-label small fw-bold">Titolo Evento <span class="text-danger">*</span></label><input type="text" name="titolo" class="form-control form-control-sm" required></div>
                        <div class="col-md-3 mb-2"><label class="form-label small fw-bold">Luogo / Aula</label><input type="text" name="luogo" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ordine</label><input type="number" name="ordine_evento" class="form-control form-control-sm" value="0" required></div>
                        
                        <div class="col-md-4 mb-2">
                            <label class="form-label small fw-bold text-primary">Prenotabile da:</label>
                            <select name="ruolo_accesso_id" class="form-select form-select-sm">
                                <option value="0">🌐 Tutti (Pubblico / Accesso Libero)</option>
                                <option value="-1">🔑 Tutti gli Utenti Autenticati (SSO Unical)</option>
                                <?php foreach ($ruoli as $r): ?>
                                    <option value="<?php echo $r['id']; ?>">🔒 Solo: <?php echo htmlspecialchars($r['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-2"><label class="form-label small fw-bold text-danger">Immagine Locandina</label><input type="file" name="locandina_file" class="form-control form-control-sm" accept="image/png, image/jpeg, image/jpg"></div>
                        <div class="col-md-4 mb-2"><label class="form-label small fw-bold text-dark">Programma / Allegato (PDF)</label><input type="file" name="allegato_pdf" class="form-control form-control-sm" accept="application/pdf"></div>

                        <div class="col-md-4 mb-2 pt-2 border-top"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="richiede_prenotazione" id="reqPren" value="1" checked><label class="form-check-label small fw-bold text-primary" for="reqPren">Richiede Prenotazione</label></div></div>
                        <div class="col-md-4 mb-2 pt-2 border-top"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="checkEvid" value="1"><label class="form-check-label small fw-bold text-danger" for="checkEvid">⭐ In EVIDENZA</label></div></div>
                        <div class="col-md-4 mb-2 pt-2 border-top"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_presenze" id="checkPres" value="1" checked><label class="form-check-label small fw-bold text-success" for="checkPres">Check-in / Scanner QR</label></div></div>
                        
                        <div class="col-12 mt-2 mb-2"><label class="form-label small fw-bold">Descrizione Evento</label><textarea name="descrizione" class="form-control form-control-sm editor-html" rows="3"></textarea></div>
                        <div class="col-12 mb-2">
                            <label for="notifExtraNuovo" class="form-label small fw-bold"><i class="fa fa-envelope me-1" aria-hidden="true"></i> Invia copia delle prenotazioni a</label>
                            <input type="text" name="email_notifiche_extra" id="notifExtraNuovo" class="form-control form-control-sm" placeholder="es. segreteria@unical.it, docente@unical.it">
                            <small class="text-muted">Facoltativo. Oltre ai gestori, questi indirizzi ricevono il riepilogo completo di ogni prenotazione e disdetta (campi aggiuntivi compresi). Separali con una virgola, massimo 10.</small>
                        </div>
                    </div>

                    <div class="row bg-white p-3 rounded border border-warning shadow-sm">
                        <h6 class="fw-bold text-warning border-bottom pb-2 mb-3" style="color:#b37700!important;">Configurazione Primo Turno (Opzionale)</h6>
                        <div class="col-md-3 mb-2"><label class="form-label small fw-bold">Nome Turno</label><input type="text" name="nome_turno" class="form-control form-control-sm" placeholder="Es. Gruppo 1" maxlength="150"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Data Turno</label><input type="date" name="data_turno" class="form-control form-control-sm"></div>
                        <div class="col-md-1 mb-2"><label class="form-label small fw-bold">Inizio</label><input type="time" name="orario_inizio" class="form-control form-control-sm"></div>
                        <div class="col-md-1 mb-2"><label class="form-label small fw-bold">Fine</label><input type="time" name="orario_fine" class="form-control form-control-sm"></div>
                        <div class="col-md-1 mb-2"><label class="form-label small fw-bold">Capienza</label><input type="number" name="max_posti" class="form-control form-control-sm" value="30"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ap. Prenotazioni</label><input type="datetime-local" name="data_apertura" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ch. Prenotazioni</label><input type="datetime-local" name="data_chiusura" class="form-control form-control-sm"></div>
                        <div class="col-12"><small class="text-muted">Il turno viene creato se compili il nome oppure la data.</small></div>
                    </div>
                </div>
                <div class="modal-footer py-2 bg-white">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="add_evento" onclick="tinymce.triggerSave();" class="btn btn-danger px-4 fw-bold shadow-sm" style="background-color: #990000;">Salva e Crea Evento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modali EDIT EVENTO e EDIT TURNO -->
<?php foreach($eventi as $ev): ?>
    <div class="modal fade" id="modEv<?php echo $ev['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="evento_id" value="<?php echo $ev['id']; ?>">
                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">

                    <div class="modal-header py-2 bg-primary text-white">
                        <h6 class="modal-title fw-bold"><i class="fa fa-edit me-1"></i> Modifica Evento: <?php echo htmlspecialchars($ev['titolo']); ?></h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body text-start bg-light">
                        <div class="row g-2 mb-3 bg-white p-2 rounded shadow-sm border border-secondary">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Sottocategoria / Sezione</label>
                                <select name="sottocategoria_id" class="form-select form-select-sm" <?php echo !$can_manage_settings ? 'disabled' : ''; ?>>
                                    <option value="">-- Nessuna --</option>
                                    <?php foreach ($sottocategorie as $sub): ?><option value="<?php echo $sub['id']; ?>" <?php echo ($ev['sottocategoria_id'] == $sub['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub['nome']); ?></option><?php endforeach; ?>
                                </select>
                                <?php if (!$can_manage_settings && !empty($ev['sottocategoria_id'])): ?><input type="hidden" name="sottocategoria_id" value="<?php echo $ev['sottocategoria_id']; ?>"><?php endif; ?>
                            </div>
                            <div class="col-md-5"><label class="form-label small fw-bold">Titolo Evento</label><input type="text" name="titolo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($ev['titolo'] ?? ''); ?>" required></div>
                            <div class="col-md-3"><label class="form-label small fw-bold">Luogo / Aula</label><input type="text" name="luogo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($ev['luogo'] ?? ''); ?>"></div>
                        </div>

                        <div class="row g-2 mb-3 align-items-center bg-white p-2 rounded border shadow-sm">
                            <div class="col-md-2"><label class="form-label small fw-bold">Ordine</label><input type="number" name="ordine_evento" class="form-control form-control-sm" value="<?php echo (int)($ev['ordine'] ?? 0); ?>" required <?php echo !$can_manage_settings ? 'readonly' : ''; ?>></div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-primary">Prenotabile da:</label>
                                <select name="ruolo_accesso_id" class="form-select form-select-sm">
                                    <option value="0">🌐 Tutti</option>
                                    <option value="-1" <?php echo ($ev['ruolo_accesso_id'] == -1) ? 'selected' : ''; ?>>🔑 Solo Autenticati</option>
                                    <?php foreach ($ruoli as $r): ?><option value="<?php echo $r['id']; ?>" <?php echo ($ev['ruolo_accesso_id'] == $r['id']) ? 'selected' : ''; ?>>🔒 Solo: <?php echo htmlspecialchars($r['nome']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 pt-4 d-flex justify-content-end gap-3">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="richiede_prenotazione" id="editReqPren<?php echo $ev['id']; ?>" value="1" <?php echo ($ev['richiede_prenotazione'] ?? 1) == 1 ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-primary" for="editReqPren<?php echo $ev['id']; ?>">Prenotazione</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_presenze" id="editPres<?php echo $ev['id']; ?>" value="1" <?php echo ($ev['abilita_presenze'] ?? 1) == 1 ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-success" for="editPres<?php echo $ev['id']; ?>">Scanner</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="editEvid<?php echo $ev['id']; ?>" value="1" <?php echo ($ev['is_evidenza'] ?? 0) == 1 ? 'checked' : ''; ?> <?php echo !$can_manage_settings ? 'disabled' : ''; ?>><label class="form-check-label small fw-bold text-danger" for="editEvid<?php echo $ev['id']; ?>">⭐ Evidenza</label></div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3 bg-white p-2 rounded shadow-sm border border-secondary">
                            <div class="col-md-6 border-end">
                                <label class="form-label small fw-bold text-danger">Modifica Locandina (JPG/PNG)</label>
                                <input type="file" name="locandina_file" class="form-control form-control-sm" accept="image/png, image/jpeg, image/jpg">
                                <?php if(!empty($ev['locandina_path'])): ?><div class="mt-2 text-danger"><input type="checkbox" class="form-check-input" name="elimina_locandina" id="delLoc<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold" for="delLoc<?php echo $ev['id']; ?>"><i class="fa fa-trash"></i> Elimina locandina</label></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark">Programma / Allegato (PDF)</label>
                                <input type="file" name="allegato_pdf" class="form-control form-control-sm" accept="application/pdf">
                                <?php if(!empty($ev['allegato_pdf'])): ?><div class="mt-2 d-flex align-items-center gap-2"><a href="../<?php echo htmlspecialchars($ev['allegato_pdf']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-eye"></i> Vedi</a><div class="form-check m-0"><input type="checkbox" class="form-check-input" name="elimina_pdf" id="delPdf<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-danger" for="delPdf<?php echo $ev['id']; ?>">Elimina PDF</label></div></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-2 bg-white p-2 rounded border"><label class="form-label small fw-bold">Descrizione Evento</label><textarea name="descrizione" class="form-control form-control-sm editor-html" rows="4"><?php echo htmlspecialchars($ev['descrizione'] ?? ''); ?></textarea></div>
                        <div class="mb-2 bg-white p-2 rounded border">
                            <label for="notifExtra<?php echo $ev['id']; ?>" class="form-label small fw-bold"><i class="fa fa-envelope me-1" aria-hidden="true"></i> Invia copia delle prenotazioni a</label>
                            <input type="text" name="email_notifiche_extra" id="notifExtra<?php echo $ev['id']; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars(implode(', ', normalizza_lista_email($ev['email_notifiche_extra'] ?? ''))); ?>" placeholder="es. segreteria@unical.it, docente@unical.it">
                            <small class="text-muted">Oltre ai gestori, questi indirizzi ricevono il riepilogo completo di ogni prenotazione e disdetta. Separali con una virgola, massimo 10. Lascia vuoto per nessuno.</small>
                        </div>
                    </div>
                    <div class="modal-footer py-2 bg-white border-top">
                        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" name="edit_evento" onclick="tinymce.triggerSave();" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-save"></i> Salva Modifiche Evento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach($ev['turni'] as $t): ?>
    <div class="modal fade" id="modTurno<?php echo $t['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="modal-header py-2 bg-light">
                        <h6 class="modal-title fw-bold text-primary"><i class="fa fa-clock me-1"></i> Modifica Turno</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-start row g-2">
                        <input type="hidden" name="turno_id" value="<?php echo $t['id']; ?>">
                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                        <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                        
                        <div class="col-12"><label class="small fw-bold">Nome Turno</label><input type="text" name="nome_turno" class="form-control form-control-sm" value="<?php echo htmlspecialchars($t['nome_turno'] ?? ''); ?>" placeholder="Es. Gruppo 1" maxlength="150"></div>
                        <div class="col-12"><label class="small fw-bold">Data Turno</label><input type="date" name="data_turno" class="form-control form-control-sm" value="<?php echo htmlspecialchars($t['data_turno'] ?? ''); ?>"></div>
                        <div class="col-6"><label class="small fw-bold">Ora Inizio</label><input type="time" name="orario_inizio" class="form-control form-control-sm" value="<?php echo htmlspecialchars($t['orario_inizio'] ?? ''); ?>"></div>
                        <div class="col-6"><label class="small fw-bold">Ora Fine</label><input type="time" name="orario_fine" class="form-control form-control-sm" value="<?php echo htmlspecialchars($t['orario_fine'] ?? ''); ?>"></div>
                        <div class="col-12"><small class="text-muted">Almeno il nome oppure la data.</small></div>
                        <div class="col-12"><label class="small fw-bold">Capienza Posti</label><input type="number" name="max_posti" class="form-control form-control-sm" value="<?php echo $t['max_posti']; ?>" required></div>
                        
                        <div class="col-12 mt-3"><label class="small fw-bold text-primary">Ap. Prenotazioni</label><input type="datetime-local" name="data_apertura" class="form-control form-control-sm" value="<?php echo !empty($t['data_apertura']) ? date('Y-m-d\TH:i', strtotime($t['data_apertura'])) : ''; ?>"></div>
                        <div class="col-12"><label class="small fw-bold text-danger">Ch. Prenotazioni</label><input type="datetime-local" name="data_chiusura" class="form-control form-control-sm" value="<?php echo !empty($t['data_chiusura']) ? date('Y-m-d\TH:i', strtotime($t['data_chiusura'])) : ''; ?>"></div>
                        
                        <div class="col-12 mt-2">
                            <div class="border p-2 bg-light rounded d-flex gap-3 flex-wrap">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_lista_attesa" id="waEdit<?php echo $t['id']; ?>" value="1" <?php echo (isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa']==1) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-warning" for="waEdit<?php echo $t['id']; ?>">L. Attesa</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_multi_posto" id="mpEdit<?php echo $t['id']; ?>" value="1" <?php echo (isset($t['abilita_multi_posto']) && $t['abilita_multi_posto']==1) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-info" for="mpEdit<?php echo $t['id']; ?>">Multi-Posto</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="richiede_approvazione" id="apprEdit<?php echo $t['id']; ?>" value="1" <?php echo (isset($t['richiede_approvazione']) && $t['richiede_approvazione']==1) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-danger" for="apprEdit<?php echo $t['id']; ?>">Approvazione</label></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2"><button type="submit" name="edit_turno" class="btn btn-primary btn-sm w-100 fw-bold">Salva Turno</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<script>
// Dopo una duplicazione apre subito la finestra di modifica della copia (?apri=modTurnoN / modEvN)
document.addEventListener("DOMContentLoaded", function () {
    var id = new URLSearchParams(location.search).get("apri");
    if (!id || !/^mod(Turno|Ev)[0-9]+$/.test(id)) return;
    var el = document.getElementById(id);
    if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
});
</script>
<?php require_once 'admin_footer.php'; ?>
