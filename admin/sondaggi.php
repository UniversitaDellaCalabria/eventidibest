<?php
// sondaggi.php - Gestione Questionari, Feedback e Statistiche
require_once 'admin_header.php';

$is_archivio = (isset($_GET['archivio']) && $_GET['archivio'] == 1) || (isset($_POST['archivio']) && $_POST['archivio'] == 1) ? 1 : 0;

if (!$can_manage_sondaggi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

// RBAC: evento / sondaggio / domanda toccabili solo se l'evento è dell'area corrente
// e visibile all'utente (per i gestori di singolo evento vale $sql_filtro_eventi_rbac).
function sond_autorizzato($conn, string $tipo, int $id, int $p_id, string $rbac): bool {
    $from = [
        'evento'    => "FROM eventi e WHERE e.id = $id",
        'sondaggio' => "FROM sondaggi s JOIN eventi e ON s.evento_id = e.id WHERE s.id = $id",
        'domanda'   => "FROM sondaggi_domande d JOIN sondaggi s ON d.sondaggio_id = s.id JOIN eventi e ON s.evento_id = e.id WHERE d.id = $id",
    ][$tipo];
    $res = $conn->query("SELECT 1 $from AND e.pagina_id = $p_id $rbac LIMIT 1");
    return $res && $res->num_rows > 0;
}
function sond_richiedi($conn, string $tipo, int $id, int $p_id, string $rbac): void {
    if (!sond_autorizzato($conn, $tipo, $id, $p_id, $rbac)) { http_response_code(403); die("Accesso negato."); }
}

// AJAX: salva ordine domande
if (isset($_POST['ajax_salva_ordine_dom'])) {
    header('Content-Type: application/json');
    csrf_verify($_POST['csrf_token'] ?? '');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    foreach ($ids as $pos => $id) {
        if (!sond_autorizzato($conn, 'domanda', $id, $filtro_p, $sql_filtro_eventi_rbac)) continue;
        $conn->query("UPDATE sondaggi_domande SET ordine=" . (int)$pos . " WHERE id=$id");
    }
    echo json_encode(['ok' => true]); exit;
}

$f_sond_ev = isset($_GET['f_sond_ev']) ? (int)$_GET['f_sond_ev'] : 0;
$url_suffix = $is_archivio ? "&archivio=1" : "";

$tipo_info = [
    'rating'     => ['label' => 'Stelle (1-5)',         'icon' => 'fa-star',              'color' => 'warning'],
    'nps'        => ['label' => 'NPS (0-10)',            'icon' => 'fa-chart-bar',         'color' => 'info'],
    'matrice'    => ['label' => 'Matrice Valutaz.',      'icon' => 'fa-table',             'color' => 'primary'],
    'radio'      => ['label' => 'Scelta Singola',        'icon' => 'fa-dot-circle',        'color' => 'success'],
    'select'     => ['label' => 'Menu a Tendina',        'icon' => 'fa-caret-square-down', 'color' => 'secondary'],
    'checkboxes' => ['label' => 'Scelta Multipla',       'icon' => 'fa-check-square',      'color' => 'success'],
    'text'       => ['label' => 'Testo Breve',           'icon' => 'fa-font',              'color' => 'dark'],
    'textarea'   => ['label' => 'Commento Esteso',       'icon' => 'fa-align-left',        'color' => 'dark'],
    'number'     => ['label' => 'Valore Numerico',       'icon' => 'fa-hashtag',           'color' => 'dark'],
    'date'       => ['label' => 'Data',                  'icon' => 'fa-calendar',          'color' => 'dark'],
    'email'      => ['label' => 'Email',                 'icon' => 'fa-envelope',          'color' => 'dark'],
    'tel'        => ['label' => 'Telefono',              'icon' => 'fa-phone',             'color' => 'dark'],
    'url'        => ['label' => 'Link/URL',              'icon' => 'fa-link',              'color' => 'dark'],
    'time'       => ['label' => 'Orario',                'icon' => 'fa-clock',             'color' => 'dark'],
    'separator'  => ['label' => 'Separatore Sezione',    'icon' => 'fa-minus',             'color' => 'secondary'],
];
$tipi_con_opzioni = ['radio', 'select', 'checkboxes', 'matrice'];

// ==============================================================================
// BACKEND
// ==============================================================================

if (isset($_POST['export_sondaggio_xls'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sond_id_exp = (int)$_POST['sondaggio_id_export'];
    sond_richiedi($conn, 'sondaggio', $sond_id_exp, $filtro_p, $sql_filtro_eventi_rbac);
    $domande_exp = [];
    $res_d_exp = $conn->query("SELECT id, testo_domanda, tipo FROM sondaggi_domande WHERE sondaggio_id = $sond_id_exp ORDER BY ordine ASC, id ASC");
    if ($res_d_exp) while($d = $res_d_exp->fetch_assoc()) $domande_exp[$d['id']] = $d;
    $risposte_raggruppate = [];
    $res_r_exp = $conn->query("SELECT * FROM sondaggi_risposte WHERE sondaggio_id = $sond_id_exp ORDER BY data_risposta DESC");
    if ($res_r_exp) {
        while ($r = $res_r_exp->fetch_assoc()) {
            $dr = $r['data_risposta'];
            if (!isset($risposte_raggruppate[$dr])) $risposte_raggruppate[$dr] = [];
            $risposte_raggruppate[$dr][$r['domanda_id']] = $r['risposta'];
        }
    }
    ob_end_clean();
    $filename = "Risultati_Sondaggio_" . date('Ymd_Hi');
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename={$filename}.xls");
    header("Pragma: no-cache"); header("Expires: 0");
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1">';
    echo '<tr><th style="background-color:#198754; color:white;">Data Compilazione</th>';
    foreach ($domande_exp as $id => $dinfo) echo '<th style="background-color:#198754; color:white;">' . htmlspecialchars($dinfo['testo_domanda']) . '</th>';
    echo '</tr>';
    foreach ($risposte_raggruppate as $data_comp => $risp_date) {
        echo '<tr><td>' . htmlspecialchars($data_comp) . '</td>';
        foreach ($domande_exp as $id => $dinfo) {
            $val = $risp_date[$id] ?? 'N/D';
            if ($dinfo['tipo'] === 'matrice' && $val !== 'N/D') {
                $json = json_decode($val, true);
                if (is_array($json)) { $arr = []; foreach($json as $k => $v) $arr[] = "$k: $v/5"; $val = implode(" | ", $arr); }
            }
            echo '<td>' . htmlspecialchars($val) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></body></html>';
    exit;
}

if (!$is_archivio) {

    if (isset($_POST['add_sondaggio'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $ev_id = (int)$_POST['evento_id'];
        sond_richiedi($conn, 'evento', $ev_id, $filtro_p, $sql_filtro_eventi_rbac);
        $titolo = $conn->real_escape_string($_POST['titolo_sondaggio']);
        $conn->query("INSERT INTO sondaggi (evento_id, titolo, attivo) VALUES ($ev_id, '$titolo', 0)");
        flash_set("Sondaggio creato! Ora aggiungi le domande.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_POST['add_domanda_sondaggio'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $s_id   = (int)$_POST['sondaggio_id'];
        $ev_id  = (int)$_POST['evento_id'];
        sond_richiedi($conn, 'sondaggio', $s_id, $filtro_p, $sql_filtro_eventi_rbac);
        $testo  = $conn->real_escape_string($_POST['testo_domanda'] ?? '');
        $tipo   = $conn->real_escape_string($_POST['tipo_domanda'] ?? 'text');
        $opzioni = $conn->real_escape_string(trim($_POST['opzioni'] ?? ''));
        $obbl   = isset($_POST['obbligatorio']) ? 1 : 0;
        $cond_d_id = (int)($_POST['cond_dom_id'] ?? 0);
        $cond_val  = trim($_POST['cond_valore'] ?? ''); // escape solo sul JSON finale
        $condizione_json = '';
        if ($cond_d_id > 0 && $cond_val !== '') {
            $condizione_json = $conn->real_escape_string(json_encode(['se_id' => $cond_d_id, 'se_val' => $cond_val]));
        }
        $res_mo = $conn->query("SELECT COALESCE(MAX(ordine), -10) + 10 as new_ord FROM sondaggi_domande WHERE sondaggio_id=$s_id");
        $new_ord = $res_mo ? (int)$res_mo->fetch_assoc()['new_ord'] : 0;
        $conn->query("INSERT INTO sondaggi_domande (sondaggio_id, testo_domanda, tipo, obbligatorio, opzioni, condizione_json, ordine) VALUES ($s_id, '$testo', '$tipo', $obbl, '$opzioni', '$condizione_json', $new_ord)");
        flash_set("Domanda aggiunta!");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_POST['edit_domanda_sondaggio'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $d_id   = (int)$_POST['dom_id'];
        $ev_id  = (int)$_POST['evento_id'];
        sond_richiedi($conn, 'domanda', $d_id, $filtro_p, $sql_filtro_eventi_rbac);
        $testo  = $conn->real_escape_string($_POST['testo_domanda'] ?? '');
        $tipo   = $conn->real_escape_string($_POST['tipo_domanda'] ?? 'text');
        $opzioni = $conn->real_escape_string(trim($_POST['opzioni'] ?? ''));
        $obbl   = isset($_POST['obbligatorio']) ? 1 : 0;
        $cond_d_id = (int)($_POST['cond_dom_id'] ?? 0);
        $cond_val  = trim($_POST['cond_valore'] ?? ''); // escape solo sul JSON finale
        $condizione_json = '';
        if ($cond_d_id > 0 && $cond_val !== '') {
            $condizione_json = $conn->real_escape_string(json_encode(['se_id' => $cond_d_id, 'se_val' => $cond_val]));
        }
        $conn->query("UPDATE sondaggi_domande SET testo_domanda='$testo', tipo='$tipo', opzioni='$opzioni', obbligatorio=$obbl, condizione_json='$condizione_json' WHERE id=$d_id");
        flash_set("Domanda aggiornata!");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_POST['move_domanda'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $d_id  = (int)$_POST['dom_id'];
        $ev_id = (int)$_POST['evento_id'];
        sond_richiedi($conn, 'domanda', $d_id, $filtro_p, $sql_filtro_eventi_rbac);
        $dir   =($_POST['dir'] ?? '') === 'up' ? -15 : 15;
        $conn->query("UPDATE sondaggi_domande SET ordine = ordine + $dir WHERE id=$d_id");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_GET['toggle_sondaggio'])) {
        csrf_verify($_GET['csrf'] ?? '');
        $s_id = (int)$_GET['toggle_sondaggio'];
        $val  = (int)$_GET['val'] === 1 ? 1 : 0;
        $ev_id = (int)$_GET['ev_id'];
        sond_richiedi($conn, 'sondaggio', $s_id, $filtro_p, $sql_filtro_eventi_rbac);
        $conn->query("UPDATE sondaggi SET attivo = $val WHERE id = $s_id");
        flash_set($val == 1 ? "Sondaggio Attivato!" : "Sondaggio Disattivato!");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_GET['del_domanda_sondaggio'])) {
        csrf_verify($_GET['csrf'] ?? '');
        $d_id  = (int)$_GET['del_domanda_sondaggio'];
        $ev_id = (int)$_GET['ev_id'];
        sond_richiedi($conn, 'domanda', $d_id, $filtro_p, $sql_filtro_eventi_rbac);
        $conn->query("DELETE FROM sondaggi_domande WHERE id = $d_id");
        flash_set("Domanda eliminata.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_GET['del_sondaggio'])) {
        csrf_verify($_GET['csrf'] ?? '');
        $s_id  = (int)$_GET['del_sondaggio'];
        $ev_id = (int)$_GET['ev_id'];
        sond_richiedi($conn, 'sondaggio', $s_id, $filtro_p, $sql_filtro_eventi_rbac);
        $conn->query("DELETE FROM sondaggi_risposte WHERE sondaggio_id = $s_id");
        $conn->query("DELETE FROM sondaggi_domande WHERE sondaggio_id = $s_id");
        $conn->query("DELETE FROM sondaggi WHERE id = $s_id");
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Sondaggio", ["Sondaggio ID" => $s_id, "Evento ID" => $ev_id]);
        flash_set("Sondaggio e risultati eliminati.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

    if (isset($_POST['invia_mail_sondaggi'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $ev_id = (int)$_POST['evento_id'];
        sond_richiedi($conn, 'evento', $ev_id, $filtro_p, $sql_filtro_eventi_rbac);
        $sys = $conn->query("SELECT email_sondaggio_oggetto, email_sondaggio_corpo FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();
        $oggetto_base = $sys['email_sondaggio_oggetto'] ?: 'La tua opinione è importante! Sondaggio Evento: {TITOLO_EVENTO}';
        $corpo_base = $sys['email_sondaggio_corpo'] ?: '<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ringraziamo per aver partecipato all\'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>La tua opinione per noi è fondamentale. Ti invitiamo a compilare il questionario di gradimento in forma <strong>totalmente anonima</strong>.</p><div style="text-align:center;margin:35px 0;">{LINK_SONDAGGIO}</div>';
        $sql_pr = "SELECT pr.*, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo as evento_luogo, e.abilita_presenze FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE e.id = $ev_id AND IFNULL(pr.stato, 'confermata') = 'confermata'";
        $res_pr = $conn->query($sql_pr);
        $count = 0;
        if ($res_pr && $res_pr->num_rows > 0) {
            $domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
            while ($p = $res_pr->fetch_assoc()) {
                if (empty($p['email'])) continue;
                if ((int)($p['sondaggio_completato'] ?? 0) === 1) continue;
                if ((int)($p['abilita_presenze'] ?? 1) === 1 && (int)$p['presente'] === 0) continue;
                $token_sond = $p['token_sondaggio'] ?? '';
                $pr_id = (int)$p['id'];
                if (empty($token_sond)) {
                    $token_sond = bin2hex(random_bytes(16));
                    $conn->query("UPDATE prenotazioni SET token_sondaggio = '$token_sond' WHERE id = $pr_id");
                }
                $url_sondaggio = $domain . "/sondaggio.php?token=" . $token_sond;
                $btn_sondaggio = "<a href='$url_sondaggio' style='background-color:#17a2b8;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:bold;font-size:16px;'>📝 Compila il Questionario</a>";
                $r_find = ['{NOME}','{COGNOME}','{MATRICOLA}','{TITOLO_EVENTO}','{DATA_TURNO}','{ORARIO_TURNO}','{LUOGO}','{LINK_SONDAGGIO}'];
                $ora_f  = orario_turno($p);
                $data_f = !empty($p['data_turno']) ? date('d/m/Y', strtotime($p['data_turno'])) : '';
                $r_repl = [$p['nome'],$p['cognome'],$p['matricola'],$p['evento_titolo'],$data_f,$ora_f,$p['evento_luogo'],$btn_sondaggio];
                inviaNotificaEmail($p['email'], str_replace($r_find, $r_repl, $oggetto_base), str_replace($r_find, $r_repl, $corpo_base), $conn, colore_area_turno($conn, $p['turno_id']));
                $count++;
            }
        }
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Invio Massivo Mail Sondaggio", ["Evento ID" => $ev_id, "Email Inviate" => $count]);
        flash_set("Completato! Inviate $count email.");
        admin_redirect("sondaggi.php?p_id=$filtro_p&f_sond_ev=$ev_id$url_suffix");
    }

} // fine !$is_archivio

// ==============================================================================
// PREPARAZIONE DATI
// ==============================================================================

$tutti_gli_eventi = [];
$res_ev = $conn->query("SELECT id, titolo, archiviato FROM eventi e WHERE e.pagina_id = $filtro_p AND e.archiviato = $is_archivio $sql_filtro_eventi_rbac ORDER BY e.ordine ASC, e.id DESC");
if ($res_ev) while($row = $res_ev->fetch_assoc()) $tutti_gli_eventi[] = $row;

$curr_sondaggio = null;
$curr_domande   = [];
$statistiche_sondaggio = [];

if ($f_sond_ev > 0) {
    $res_s = $conn->query("SELECT * FROM sondaggi WHERE evento_id = $f_sond_ev LIMIT 1");
    if ($res_s && $res_s->num_rows > 0) {
        $curr_sondaggio = $res_s->fetch_assoc();
        $res_d = $conn->query("SELECT * FROM sondaggi_domande WHERE sondaggio_id = {$curr_sondaggio['id']} ORDER BY ordine ASC, id ASC");
        while ($d = $res_d->fetch_assoc()) $curr_domande[] = $d;

        $res_risp = $conn->query("SELECT r.*, d.tipo, d.testo_domanda FROM sondaggi_risposte r JOIN sondaggi_domande d ON r.domanda_id = d.id WHERE r.sondaggio_id = {$curr_sondaggio['id']}");
        if ($res_risp) {
            while ($r = $res_risp->fetch_assoc()) {
                $d_id = $r['domanda_id'];
                if (!isset($statistiche_sondaggio[$d_id])) {
                    $statistiche_sondaggio[$d_id] = [
                        'testo' => $r['testo_domanda'], 'tipo' => $r['tipo'],
                        'risposte' => [], 'totale_voti' => 0, 'somma_voti' => 0,
                        'conteggi_opzioni' => [], 'conteggi_matrice' => [],
                        'distribuzione_nps' => array_fill(0, 11, 0),
                    ];
                }
                if ($r['tipo'] === 'rating') {
                    $statistiche_sondaggio[$d_id]['totale_voti']++;
                    $statistiche_sondaggio[$d_id]['somma_voti'] += (int)$r['risposta'];
                } elseif ($r['tipo'] === 'nps') {
                    $val = min(10, max(0, (int)$r['risposta']));
                    $statistiche_sondaggio[$d_id]['totale_voti']++;
                    $statistiche_sondaggio[$d_id]['somma_voti'] += $val;
                    $statistiche_sondaggio[$d_id]['distribuzione_nps'][$val]++;
                } elseif ($r['tipo'] === 'matrice') {
                    $val_json = json_decode($r['risposta'], true);
                    if (is_array($val_json)) {
                        foreach ($val_json as $sub_item => $voto) {
                            if (!isset($statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item]))
                                $statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item] = ['somma' => 0, 'tot' => 0];
                            $statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item]['somma'] += (int)$voto;
                            $statistiche_sondaggio[$d_id]['conteggi_matrice'][$sub_item]['tot']++;
                        }
                        $statistiche_sondaggio[$d_id]['totale_voti']++;
                    }
                } elseif (in_array($r['tipo'], ['radio', 'select', 'checkbox', 'checkboxes'])) {
                    $val = trim($r['risposta']);
                    if (!isset($statistiche_sondaggio[$d_id]['conteggi_opzioni'][$val])) $statistiche_sondaggio[$d_id]['conteggi_opzioni'][$val] = 0;
                    $statistiche_sondaggio[$d_id]['conteggi_opzioni'][$val]++;
                    $statistiche_sondaggio[$d_id]['totale_voti']++;
                } else {
                    $statistiche_sondaggio[$d_id]['risposte'][] = $r['risposta'];
                }
            }
        }
    }
}

// Build map for conditional lookup
$dom_id_map = [];
foreach ($curr_domande as $d) $dom_id_map[$d['id']] = $d;
?>

<style>
.sd-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:10px 14px; margin-bottom:8px; display:flex; align-items:center; gap:10px; transition: box-shadow .15s; }
.sd-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.1); }
.sd-drag-handle { cursor:grab; color:#adb5bd; font-size:1.1rem; flex-shrink:0; }
.sd-drag-handle:active { cursor:grabbing; }
.sortable-ghost { opacity:.4; background:#e9ecef !important; }
.nps-bar-wrap { display:flex; align-items:center; gap:6px; margin-bottom:3px; }
.nps-bar { flex:1; height:10px; border-radius:4px; overflow:hidden; background:#e9ecef; }
.nps-bar-inner { height:100%; border-radius:4px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0">
        <?php if ($is_archivio): ?>
            <i class="fa fa-archive text-secondary me-2"></i> Archivio Sondaggi
        <?php else: ?>
            <i class="fa fa-star text-success me-2"></i> Sondaggi & Feedback
        <?php endif; ?>
    </h4>
    <?php if ($is_archivio): ?>
        <a href="archivio.php?p_id=<?= $filtro_p ?>" class="btn btn-secondary btn-sm fw-bold shadow-sm"><i class="fa fa-arrow-left me-1"></i> Torna all'Archivio</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0 p-4 mb-4">
    <h5 class="fw-bold text-success border-bottom pb-2 mb-3">Customer Satisfaction <?= $is_archivio ? '(Archivio)' : 'e Questionari Anonimi' ?></h5>

    <!-- Selezione evento -->
    <div class="row align-items-center mb-4">
        <div class="col-md-8">
            <label class="form-label small fw-bold">Seleziona l'Evento:</label>
            <form method="GET" id="formSondaggioEv" class="d-flex align-items-center gap-2 m-0">
                <input type="hidden" name="p_id" value="<?= $filtro_p ?>">
                <?php if ($is_archivio): ?><input type="hidden" name="archivio" value="1"><?php endif; ?>
                <select name="f_sond_ev" class="form-select border-success fw-bold" onchange="document.getElementById('formSondaggioEv').submit();">
                    <option value="0">-- Seleziona un Evento --</option>
                    <?php foreach($tutti_gli_eventi as $e_opt): ?>
                        <option value="<?= $e_opt['id'] ?>" <?= $f_sond_ev == $e_opt['id'] ? 'selected' : '' ?>>
                            <?= $e_opt['archiviato'] == 1 ? '🗄️ [ARCHIVIATO] ' : '🎯 ' ?><?= htmlspecialchars($e_opt['titolo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($f_sond_ev > 0): ?>
        <?php if (!$curr_sondaggio): ?>
            <div class="alert alert-light border shadow-sm text-center p-4">
                <i class="fa fa-info-circle text-muted fs-1 mb-2 d-block"></i>
                <h5 class="fw-bold text-dark">Nessun sondaggio per questo evento.</h5>
                <?php if (!$is_archivio): ?>
                    <p class="text-secondary mb-3">Crea un questionario di gradimento anonimo.</p>
                    <form method="POST" class="d-flex flex-column align-items-center">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="evento_id" value="<?= $f_sond_ev ?>">
                        <input type="text" name="titolo_sondaggio" class="form-control mb-2" style="max-width:400px;" placeholder="Es. Valutazione Evento" required>
                        <button type="submit" name="add_sondaggio" class="btn btn-success fw-bold"><i class="fa fa-plus me-1"></i> Crea Sondaggio</button>
                    </form>
                <?php else: ?>
                    <p class="text-secondary m-0">Nessuno storico di questionari per questa attività.</p>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- SONDAGGIO ESISTENTE -->
            <div class="border p-4 bg-light rounded shadow-sm mb-4 border-success">
                <!-- Intestazione sondaggio -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom pb-3 mb-3">
                    <div>
                        <h5 class="fw-bold text-dark m-0 mb-1">📋 <?= htmlspecialchars($curr_sondaggio['titolo']) ?></h5>
                        <?php if($curr_sondaggio['attivo']): ?>
                            <span class="badge bg-success shadow-sm">ATTIVO</span>
                        <?php else: ?>
                            <span class="badge bg-secondary shadow-sm">DISATTIVATO</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-info btn-sm fw-bold text-white shadow-sm" data-bs-toggle="modal" data-bs-target="#modAnteprimaSondaggio">
                            <i class="fa fa-eye"></i> Anteprima
                        </button>
                        <?php if (!$is_archivio): ?>
                            <?php if($curr_sondaggio['attivo']): ?>
                                <a href="?toggle_sondaggio=<?= $curr_sondaggio['id'] ?>&val=0&p_id=<?= $filtro_p ?>&csrf=<?= urlencode(csrf_token()) ?>&ev_id=<?= $f_sond_ev ?>" class="btn btn-outline-secondary btn-sm fw-bold">Sospendi</a>
                            <?php else: ?>
                                <a href="?toggle_sondaggio=<?= $curr_sondaggio['id'] ?>&val=1&p_id=<?= $filtro_p ?>&csrf=<?= urlencode(csrf_token()) ?>&ev_id=<?= $f_sond_ev ?>" class="btn btn-success btn-sm fw-bold shadow-sm">Attiva</a>
                            <?php endif; ?>
                            <a href="?del_sondaggio=<?= $curr_sondaggio['id'] ?>&p_id=<?= $filtro_p ?>&csrf=<?= urlencode(csrf_token()) ?>&ev_id=<?= $f_sond_ev ?>" class="btn btn-danger btn-sm fw-bold shadow-sm" data-confirm="ATTENZIONE! Verranno eliminate tutte le domande e le risposte. Confermi?"><i class="fa fa-trash"></i> Elimina</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$is_archivio): ?>
                <!-- FORM AGGIUNGI DOMANDA -->
                <form method="POST" class="bg-white p-3 border rounded shadow-sm mb-4" id="formAddDomanda">
                    <?php csrf_field(); ?>
                    <h6 class="fw-bold text-success mb-3"><i class="fa fa-plus-circle me-1"></i> Aggiungi Domanda</h6>
                    <input type="hidden" name="sondaggio_id" value="<?= $curr_sondaggio['id'] ?>">
                    <input type="hidden" name="evento_id" value="<?= $f_sond_ev ?>">

                    <div class="row g-2 mb-2">
                        <div class="col-md-7">
                            <input type="text" name="testo_domanda" id="addTesto" class="form-control" placeholder="Testo della domanda (non necessario per Separatore)">
                        </div>
                        <div class="col-md-3">
                            <select name="tipo_domanda" id="addTipo" class="form-select fw-bold text-primary border-primary" onchange="sdOnTipoChange('add', this.value)">
                                <?php foreach ($tipo_info as $tk => $tv): ?>
                                    <option value="<?= $tk ?>"><?= $tv['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="add_domanda_sondaggio" class="btn btn-primary fw-bold w-100">Aggiungi</button>
                        </div>
                    </div>

                    <!-- Opzioni (radio/select/checkboxes/matrice) -->
                    <div class="d-none mb-2" id="addBoxOpzioni">
                        <label class="form-label small fw-bold text-primary mb-1" id="addLabelOpzioni">Opzioni (separate da virgola):</label>
                        <input type="text" name="opzioni" id="addInputOpzioni" class="form-control form-control-sm border-primary" placeholder="Es. Ottimo, Buono, Sufficiente, Scarso">
                    </div>

                    <!-- Condizione (solo se ci sono domande precedenti con opzioni) -->
                    <?php
                    $dom_trigger_cond = array_filter($curr_domande, function($d) use ($tipi_con_opzioni) {
                        return in_array($d['tipo'], $tipi_con_opzioni);
                    });
                    ?>
                    <?php if (!empty($dom_trigger_cond)): ?>
                    <div class="d-none mb-2" id="addBoxCondizione">
                        <div class="row g-2 align-items-center">
                            <div class="col-auto"><span class="badge bg-warning text-dark"><i class="fa fa-code-branch me-1"></i>Mostra solo se</span></div>
                            <div class="col-md-4">
                                <select name="cond_dom_id" id="addCondDom" class="form-select form-select-sm border-warning" onchange="sdLoadOpzioniCond('add', this.value)">
                                    <option value="0">-- scegli domanda --</option>
                                    <?php foreach ($dom_trigger_cond as $dt): ?>
                                        <option value="<?= $dt['id'] ?>" data-opzioni="<?= htmlspecialchars($dt['opzioni']) ?>">
                                            <?= htmlspecialchars(mb_substr($dt['testo_domanda'], 0, 40)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto"><span class="small text-muted">vale</span></div>
                            <div class="col-md-3">
                                <select name="cond_valore" id="addCondVal" class="form-select form-select-sm border-warning">
                                    <option value="">-- scegli valore --</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input" type="checkbox" name="obbligatorio" id="addObbl" value="1">
                            <label class="form-check-label small fw-bold" for="addObbl">Obbligatoria</label>
                        </div>
                        <?php if (!empty($dom_trigger_cond)): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="sdToggleCond('add')">
                            <i class="fa fa-code-branch me-1"></i> Aggiungi Condizione
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Aggiungi prima una domanda con opzioni (Scelta Singola, Menu a Tendina, Scelta Multipla o Matrice) per poter definire condizioni">
                            <i class="fa fa-code-branch me-1"></i> Aggiungi Condizione
                        </button>
                        <span class="small text-muted"><i class="fa fa-info-circle me-1"></i>Le condizioni si abilitano dopo aver aggiunto almeno una domanda con opzioni (radio, select, ecc.)</span>
                        <?php endif; ?>
                    </div>
                </form>

                <script>
                function sdOnTipoChange(prefix, tipo) {
                    var tipiOpz = ['radio','select','checkboxes','matrice'];
                    var boxOpz  = document.getElementById(prefix + 'BoxOpzioni');
                    var inpOpz  = document.getElementById(prefix + 'InputOpzioni');
                    var lblOpz  = document.getElementById(prefix + 'LabelOpzioni');
                    if (boxOpz) {
                        if (tipiOpz.indexOf(tipo) >= 0) {
                            boxOpz.classList.remove('d-none');
                            if (inpOpz) inpOpz.setAttribute('required', '');
                            if (lblOpz) lblOpz.innerHTML = tipo === 'matrice' ? '<i class="fa fa-list me-1"></i> Righe da valutare (separate da virgola):' : '<i class="fa fa-list me-1"></i> Opzioni (separate da virgola):';
                        } else {
                            boxOpz.classList.add('d-none');
                            if (inpOpz) { inpOpz.removeAttribute('required'); }
                        }
                    }
                }
                function sdToggleCond(prefix) {
                    var box = document.getElementById(prefix + 'BoxCondizione');
                    if (box) box.classList.toggle('d-none');
                }
                function sdLoadOpzioniCond(prefix, domId) {
                    var selDom = document.getElementById(prefix + 'CondDom');
                    var selVal = document.getElementById(prefix + 'CondVal');
                    if (!selDom || !selVal) return;
                    var opt = selDom.querySelector('option[value="' + domId + '"]');
                    var opzStr = opt ? (opt.dataset.opzioni || '') : '';
                    selVal.innerHTML = '<option value="">-- scegli valore --</option>';
                    if (opzStr) {
                        opzStr.split(',').forEach(function(o) {
                            o = o.trim();
                            if (o) {
                                var el = document.createElement('option');
                                el.value = o; el.textContent = o;
                                selVal.appendChild(el);
                            }
                        });
                    }
                }
                // populate condVal for edit modals with pre-selected cond_dom_id
                function sdInitEditCond(prefix, domId, selectedVal) {
                    sdLoadOpzioniCond(prefix, domId);
                    setTimeout(function() {
                        var selVal = document.getElementById(prefix + 'CondVal');
                        if (selVal && selectedVal) {
                            for (var i = 0; i < selVal.options.length; i++) {
                                if (selVal.options[i].value === selectedVal) { selVal.selectedIndex = i; break; }
                            }
                        }
                    }, 50);
                }
                </script>
                <?php endif; ?>

                <!-- LISTA DOMANDE -->
                <h6 class="fw-bold text-dark mt-2 mb-3"><i class="fa fa-list me-1"></i> Struttura Questionario
                    <?php if (!$is_archivio && count($curr_domande) > 1): ?>
                        <span class="small text-muted fw-normal ms-2"><i class="fa fa-arrows-up-down"></i> Trascina per riordinare</span>
                    <?php endif; ?>
                </h6>

                <?php if (empty($curr_domande)): ?>
                    <div class="text-muted small text-center p-3 border rounded bg-white">Nessuna domanda. Aggiungine una qui sopra.</div>
                <?php else: ?>
                    <div id="domSortable">
                    <?php foreach ($curr_domande as $idx => $d):
                        $tipo_k = $d['tipo'];
                        $tinfo  = $tipo_info[$tipo_k] ?? ['label' => $tipo_k, 'icon' => 'fa-question', 'color' => 'dark'];
                        $cond_data = !empty($d['condizione_json']) ? json_decode($d['condizione_json'], true) : null;
                        $cond_trigger_label = '';
                        if ($cond_data && isset($cond_data['se_id']) && isset($dom_id_map[$cond_data['se_id']])) {
                            $cond_trigger_label = mb_substr($dom_id_map[$cond_data['se_id']]['testo_domanda'], 0, 30);
                        }
                    ?>
                        <div class="sd-card" data-id="<?= $d['id'] ?>">
                            <?php if (!$is_archivio): ?>
                            <span class="sd-drag-handle"><i class="fa fa-grip-vertical"></i></span>
                            <?php endif; ?>
                            <span class="badge bg-<?= $tinfo['color'] ?> text-<?= $tinfo['color'] === 'warning' ? 'dark' : 'white' ?> flex-shrink-0" style="font-size:.7rem;">
                                <i class="fa <?= $tinfo['icon'] ?> me-1"></i><?= $tinfo['label'] ?>
                            </span>
                            <div class="flex-fill min-w-0">
                                <?php if ($tipo_k === 'separator'): ?>
                                    <em class="text-muted small">— Separatore sezione<?= !empty($d['testo_domanda']) ? ': ' . htmlspecialchars($d['testo_domanda']) : '' ?> —</em>
                                <?php else: ?>
                                    <strong class="small"><?= ($idx + 1) ?>. <?= htmlspecialchars(mb_substr($d['testo_domanda'], 0, 70)) ?><?= mb_strlen($d['testo_domanda']) > 70 ? '…' : '' ?></strong>
                                    <?php if ($d['obbligatorio']): ?><span class="text-danger ms-1" title="Obbligatoria">*</span><?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($d['opzioni'])): ?>
                                    <div class="text-muted" style="font-size:.72rem;"><i class="fa fa-list-ul me-1"></i><?= htmlspecialchars(mb_substr($d['opzioni'], 0, 60)) ?></div>
                                <?php endif; ?>
                                <?php if ($cond_data): ?>
                                    <span class="badge bg-warning text-dark mt-1" style="font-size:.65rem;"><i class="fa fa-code-branch me-1"></i>se "<?= htmlspecialchars($cond_trigger_label) ?>…" = "<?= htmlspecialchars($cond_data['se_val'] ?? '') ?>"</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!$is_archivio): ?>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="dom_id" value="<?= $d['id'] ?>">
                                    <input type="hidden" name="evento_id" value="<?= $f_sond_ev ?>">
                                    <input type="hidden" name="dir" value="up">
                                    <button type="submit" name="move_domanda" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Su"><i class="fa fa-arrow-up"></i></button>
                                </form>
                                <form method="POST" class="d-inline m-0">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="dom_id" value="<?= $d['id'] ?>">
                                    <input type="hidden" name="evento_id" value="<?= $f_sond_ev ?>">
                                    <input type="hidden" name="dir" value="down">
                                    <button type="submit" name="move_domanda" class="btn btn-outline-secondary btn-sm py-0 px-1" title="Giù"><i class="fa fa-arrow-down"></i></button>
                                </form>
                                <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditDom<?= $d['id'] ?>" title="Modifica"><i class="fa fa-edit"></i></button>
                                <a href="?del_domanda_sondaggio=<?= $d['id'] ?>&p_id=<?= $filtro_p ?>&csrf=<?= urlencode(csrf_token()) ?>&ev_id=<?= $f_sond_ev ?>" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminare questa domanda?"><i class="fa fa-trash"></i></a>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- STATISTICHE -->
                <hr class="my-5">
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                    <h5 class="fw-bold text-primary m-0"><i class="fa fa-chart-line me-1"></i> Risultati e Statistiche</h5>
                    <form method="POST" class="m-0">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="sondaggio_id_export" value="<?= $curr_sondaggio['id'] ?>">
                        <button type="submit" name="export_sondaggio_xls" class="btn btn-success fw-bold shadow-sm">
                            <i class="fa fa-file-excel me-1"></i> Esporta Excel
                        </button>
                    </form>
                </div>

                <?php if (!empty($statistiche_sondaggio)): ?>
                <div class="row g-3">
                    <?php foreach($statistiche_sondaggio as $d_id => $stat): ?>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100 bg-white">
                            <div class="card-header bg-white fw-bold text-dark border-bottom-0 pt-3 pb-1" style="font-size:.9rem;">
                                <?= htmlspecialchars($stat['testo']) ?>
                            </div>
                            <div class="card-body pt-2">
                                <?php if ($stat['tipo'] === 'rating'): ?>
                                    <?php $media = $stat['totale_voti'] > 0 ? round($stat['somma_voti'] / $stat['totale_voti'], 1) : 0; ?>
                                    <div class="text-center py-3">
                                        <h2 class="fw-black text-warning m-0" style="font-size:3.5rem;"><?= number_format($media,1,',','.') ?> <small class="text-muted fs-6">/ 5</small></h2>
                                        <div class="mt-1 mb-2">
                                            <?php for($i=1;$i<=5;$i++): ?><i class="fa fa-star <?= $i <= round($media) ? 'text-warning' : 'text-light' ?> fs-3"></i><?php endfor; ?>
                                        </div>
                                        <p class="small text-muted m-0 fw-bold">Basato su <?= $stat['totale_voti'] ?> voti</p>
                                    </div>

                                <?php elseif ($stat['tipo'] === 'nps'): ?>
                                    <?php
                                    $tot = $stat['totale_voti'];
                                    $promoters  = 0; $passives = 0; $detractors = 0;
                                    for ($i=0; $i<=10; $i++) {
                                        $cnt = $stat['distribuzione_nps'][$i];
                                        if ($i >= 9) $promoters += $cnt;
                                        elseif ($i >= 7) $passives += $cnt;
                                        else $detractors += $cnt;
                                    }
                                    $pct_p = $tot > 0 ? round($promoters/$tot*100) : 0;
                                    $pct_pa = $tot > 0 ? round($passives/$tot*100) : 0;
                                    $pct_d = $tot > 0 ? round($detractors/$tot*100) : 0;
                                    $nps = $pct_p - $pct_d;
                                    $nps_color = $nps >= 50 ? 'success' : ($nps >= 0 ? 'warning' : 'danger');
                                    ?>
                                    <div class="text-center mb-3">
                                        <span class="fw-black text-<?= $nps_color ?>" style="font-size:3rem;"><?= ($nps > 0 ? '+' : '') . $nps ?></span>
                                        <div class="small text-muted fw-bold">NPS Score — <?= $tot ?> risposte</div>
                                    </div>
                                    <div class="d-flex justify-content-around text-center mb-3 small">
                                        <div><div class="fw-bold text-danger fs-5"><?= $pct_d ?>%</div><div class="text-muted">Detrattori<br>(0-6)</div></div>
                                        <div><div class="fw-bold text-warning fs-5"><?= $pct_pa ?>%</div><div class="text-muted">Passivi<br>(7-8)</div></div>
                                        <div><div class="fw-bold text-success fs-5"><?= $pct_p ?>%</div><div class="text-muted">Promotori<br>(9-10)</div></div>
                                    </div>
                                    <?php for ($i=10; $i>=0; $i--): $cnt=$stat['distribuzione_nps'][$i]; $pct=$tot>0?round($cnt/$tot*100):0; $bc=$i>=9?'#198754':($i>=7?'#ffc107':'#dc3545'); ?>
                                    <div class="nps-bar-wrap">
                                        <span style="width:18px;text-align:right;font-size:.75rem;color:#555;"><?= $i ?></span>
                                        <div class="nps-bar"><div class="nps-bar-inner" style="width:<?= $pct ?>%;background:<?= $bc ?>;"></div></div>
                                        <span style="font-size:.75rem;color:#555;width:28px;"><?= $cnt ?></span>
                                    </div>
                                    <?php endfor; ?>

                                <?php elseif ($stat['tipo'] === 'matrice'): ?>
                                    <?php if (!empty($stat['conteggi_matrice'])): ?>
                                        <?php foreach($stat['conteggi_matrice'] as $sub_item => $dati): ?>
                                            <?php $media = $dati['tot'] > 0 ? round($dati['somma']/$dati['tot'],1) : 0; ?>
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between align-items-center mb-1 small fw-bold text-secondary">
                                                    <span><?= htmlspecialchars($sub_item) ?></span>
                                                    <span class="text-warning"><i class="fa fa-star"></i> <?= number_format($media,1,',','.') ?> / 5</span>
                                                </div>
                                                <div class="progress" style="height:6px;">
                                                    <div class="progress-bar bg-warning" style="width:<?= ($media/5)*100 ?>%;"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <p class="small text-muted m-0 mt-2 fw-bold text-end">Basato su <?= $stat['totale_voti'] ?> schede</p>
                                    <?php else: ?>
                                        <p class="small text-muted">Dati non disponibili.</p>
                                    <?php endif; ?>

                                <?php elseif (in_array($stat['tipo'], ['radio','select','checkbox','checkboxes'])): ?>
                                    <?php foreach($stat['conteggi_opzioni'] as $opz => $count): ?>
                                        <?php $perc = $stat['totale_voti'] > 0 ? round(($count/$stat['totale_voti'])*100) : 0; ?>
                                        <div class="d-flex justify-content-between align-items-center mb-1 small fw-bold text-secondary">
                                            <span><?= htmlspecialchars($opz) ?></span>
                                            <span><?= $perc ?>% (<?= $count ?>)</span>
                                        </div>
                                        <div class="progress mb-3" style="height:8px;">
                                            <div class="progress-bar bg-primary" style="width:<?= $perc ?>%;"></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <p class="small text-muted m-0 mt-2 fw-bold text-end">Voti totali: <?= $stat['totale_voti'] ?></p>

                                <?php else: ?>
                                    <div style="max-height:200px;overflow-y:auto;" class="border rounded p-3 bg-light">
                                        <?php foreach(array_reverse($stat['risposte']) as $risp): ?>
                                            <div class="small border-bottom border-white py-2 text-dark"><i class="fa fa-comment-dots text-secondary me-2"></i><?= nl2br(htmlspecialchars($risp)) ?></div>
                                        <?php endforeach; ?>
                                        <?php if(empty($stat['risposte'])): ?><span class="small text-muted">Nessuna risposta.</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="alert alert-secondary text-center p-4 border shadow-sm mt-3">
                        <i class="fa fa-inbox mb-2 fs-2 d-block text-muted"></i> Nessun utente ha ancora compilato questo questionario.
                    </div>
                <?php endif; ?>

                <!-- Invio mail -->
                <?php if (!$is_archivio && $curr_sondaggio['attivo']): ?>
                <form method="POST" class="mt-4 border-top pt-4 text-end" onsubmit="return confirm('Inviare mail di invito al sondaggio a tutti gli iscritti confermati?');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="evento_id" value="<?= $f_sond_ev ?>">
                    <button type="submit" name="invia_mail_sondaggi" class="btn btn-warning btn-lg fw-bold text-dark shadow-sm">
                        <i class="fa fa-paper-plane me-1"></i> Invia Invito al Sondaggio a Tutti
                    </button>
                    <p class="small text-muted mt-2 mb-0"><i class="fa fa-info-circle me-1"></i> Usa il template impostato in "Sistema Email".</p>
                </form>
                <?php endif; ?>

            </div><!-- fine sondaggio esistente -->
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-secondary text-center p-4">Seleziona un evento per iniziare.</div>
    <?php endif; ?>
</div>

<!-- MODALI EDIT DOMANDE -->
<?php foreach ($curr_domande as $d):
    if ($is_archivio) break;
    $tipo_k  = $d['tipo'];
    $cond_ed = !empty($d['condizione_json']) ? json_decode($d['condizione_json'], true) : null;
    $cond_ed_dom_id = $cond_ed['se_id'] ?? 0;
    $cond_ed_val    = $cond_ed['se_val'] ?? '';
    $prefix = 'ed' . $d['id'];
?>
<div class="modal fade" id="modEditDom<?= $d['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-info">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="dom_id" value="<?= $d['id'] ?>">
                <input type="hidden" name="evento_id" value="<?= $f_sond_ev ?>">
                <div class="modal-header bg-info text-white py-2">
                    <h6 class="modal-title fw-bold"><i class="fa fa-edit me-1"></i> Modifica Domanda</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Testo domanda</label>
                            <input type="text" name="testo_domanda" class="form-control" value="<?= htmlspecialchars($d['testo_domanda']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Tipo</label>
                            <select name="tipo_domanda" id="<?= $prefix ?>Tipo" class="form-select fw-bold text-primary border-primary" onchange="sdOnTipoChange('<?= $prefix ?>', this.value)">
                                <?php foreach ($tipo_info as $tk => $tv): ?>
                                    <option value="<?= $tk ?>" <?= $tipo_k === $tk ? 'selected' : '' ?>><?= $tv['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 <?= !in_array($tipo_k, $tipi_con_opzioni) ? 'd-none' : '' ?>" id="<?= $prefix ?>BoxOpzioni">
                            <label class="form-label small fw-bold text-primary mb-1" id="<?= $prefix ?>LabelOpzioni">
                                <?= $tipo_k === 'matrice' ? 'Righe da valutare (separate da virgola):' : 'Opzioni (separate da virgola):' ?>
                            </label>
                            <input type="text" name="opzioni" id="<?= $prefix ?>InputOpzioni" class="form-control form-control-sm border-primary" value="<?= htmlspecialchars($d['opzioni']) ?>" <?= in_array($tipo_k, $tipi_con_opzioni) ? 'required' : '' ?>>
                        </div>
                        <!-- Condizione -->
                        <?php if (!empty($dom_trigger_cond)): ?>
                        <div class="col-12 <?= !$cond_ed ? 'd-none' : '' ?>" id="<?= $prefix ?>BoxCondizione">
                            <div class="row g-2 align-items-center">
                                <div class="col-auto"><span class="badge bg-warning text-dark"><i class="fa fa-code-branch me-1"></i>Mostra solo se</span></div>
                                <div class="col-md-4">
                                    <select name="cond_dom_id" id="<?= $prefix ?>CondDom" class="form-select form-select-sm border-warning" onchange="sdLoadOpzioniCond('<?= $prefix ?>', this.value)">
                                        <option value="0">-- scegli domanda --</option>
                                        <?php foreach ($dom_trigger_cond as $dt): ?>
                                            <option value="<?= $dt['id'] ?>" data-opzioni="<?= htmlspecialchars($dt['opzioni']) ?>" <?= $cond_ed_dom_id == $dt['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(mb_substr($dt['testo_domanda'], 0, 40)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-auto"><span class="small text-muted">vale</span></div>
                                <div class="col-md-3">
                                    <select name="cond_valore" id="<?= $prefix ?>CondVal" class="form-select form-select-sm border-warning">
                                        <option value="">-- scegli valore --</option>
                                        <?php if ($cond_ed_dom_id && isset($dom_id_map[$cond_ed_dom_id])): ?>
                                            <?php foreach (explode(',', $dom_id_map[$cond_ed_dom_id]['opzioni']) as $opz): $opz=trim($opz); if(empty($opz)) continue; ?>
                                                <option value="<?= htmlspecialchars($opz) ?>" <?= $cond_ed_val === $opz ? 'selected' : '' ?>><?= htmlspecialchars($opz) ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                            <div class="form-check form-switch m-0">
                                <input class="form-check-input" type="checkbox" name="obbligatorio" id="<?= $prefix ?>Obbl" value="1" <?= $d['obbligatorio'] ? 'checked' : '' ?>>
                                <label class="form-check-label small fw-bold" for="<?= $prefix ?>Obbl">Obbligatoria</label>
                            </div>
                            <?php if (!empty($dom_trigger_cond)): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2" onclick="sdToggleCond('<?= $prefix ?>')">
                                <i class="fa fa-code-branch me-1"></i> <?= $cond_ed ? 'Modifica Condizione' : 'Aggiungi Condizione' ?>
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" disabled title="Nessuna domanda con opzioni disponibile come trigger">
                                <i class="fa fa-code-branch me-1"></i> Aggiungi Condizione
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="edit_domanda_sondaggio" class="btn btn-info text-white fw-bold shadow-sm px-4"><i class="fa fa-save me-1"></i> Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- MODALE ANTEPRIMA INTERATTIVA -->
<?php if ($curr_sondaggio): ?>
<div class="modal fade" id="modAnteprimaSondaggio" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0" style="border-radius:12px; border-top:5px solid #17a2b8 !important;">
            <div class="modal-header py-3 bg-light border-bottom">
                <h5 class="modal-title fw-bold text-dark"><i class="fa fa-eye text-info me-2"></i> Anteprima: <?= htmlspecialchars($curr_sondaggio['titolo']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-info text-center fw-bold shadow-sm mb-4 py-2"><i class="fa fa-flask me-1"></i> Anteprima interattiva — le condizioni funzionano, i dati non vengono salvati.</div>
                <?php if (empty($curr_domande)): ?>
                    <div class="text-center text-muted py-4">Nessuna domanda inserita.</div>
                <?php else: ?>
                    <form id="formAnteprima" onsubmit="return false;">
                    <?php
                    $prev_q_num = 0;
                    foreach ($curr_domande as $idx => $d):
                        $tipo_k    = $d['tipo'];
                        $cond_pr   = !empty($d['condizione_json']) ? json_decode($d['condizione_json'], true) : null;
                        $is_cond_pr = ($cond_pr && isset($cond_pr['se_id']) && isset($cond_pr['se_val']));
                        $n_pr      = 'prev[' . $d['id'] . ']';

                        if ($tipo_k === 'separator'): ?>
                            <div class="my-4 border-top border-2"
                                <?= $is_cond_pr ? 'id="prevWrap_'.$d['id'].'" data-cond-id="'.$cond_pr['se_id'].'" data-cond-val="'.htmlspecialchars($cond_pr['se_val']).'" style="display:none;"' : '' ?>>
                                <span class="bg-light px-2 small text-muted fw-bold text-uppercase" style="position:relative;top:-11px;"><?= !empty($d['testo_domanda']) ? htmlspecialchars($d['testo_domanda']) : 'Sezione' ?></span>
                            </div>
                        <?php continue; endif;
                        $prev_q_num++;
                        $ast = $d['obbligatorio'] ? '<span class="text-danger">*</span>' : '';
                    ?>
                        <div class="bg-white p-4 rounded shadow-sm border mb-4"
                            id="prevWrap_<?= $d['id'] ?>"
                            <?= $is_cond_pr ? 'data-cond-id="'.$cond_pr['se_id'].'" data-cond-val="'.htmlspecialchars($cond_pr['se_val']).'" style="display:none;"' : '' ?>>
                            <h6 class="fw-bold mb-3 text-dark fs-6">
                                <span class="badge bg-danger me-2"><?= $prev_q_num ?></span>
                                <?= htmlspecialchars($d['testo_domanda']) ?><?= $ast ?>
                                <?php if ($is_cond_pr): ?><span class="badge bg-warning text-dark ms-2" style="font-size:.65rem;"><i class="fa fa-code-branch"></i> condizionale</span><?php endif; ?>
                            </h6>
                            <div class="mt-3">
                                <?php if ($tipo_k === 'rating'): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php for($i=1;$i<=5;$i++): ?>
                                            <div class="form-check form-check-inline m-0 p-0 text-center">
                                                <input class="btn-check" type="radio" name="<?= $n_pr ?>" id="prev_r_<?= $d['id'].'_'.$i ?>" value="<?= $i ?>">
                                                <label class="btn btn-outline-warning text-dark fw-bold px-3 py-1" for="prev_r_<?= $d['id'].'_'.$i ?>" style="border-color:#dee2e6;">
                                                    <i class="fa fa-star text-warning d-block mb-1 fs-5"></i><?= $i ?>
                                                </label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                <?php elseif ($tipo_k === 'nps'): ?>
                                    <div class="d-flex flex-wrap gap-1 mb-1">
                                        <?php for($i=0;$i<=10;$i++):
                                            $bc=$i>=9?'btn-outline-success':($i>=7?'btn-outline-warning':'btn-outline-danger'); ?>
                                            <div class="form-check form-check-inline m-0 p-0">
                                                <input class="btn-check" type="radio" name="<?= $n_pr ?>" id="prev_nps_<?= $d['id'].'_'.$i ?>" value="<?= $i ?>">
                                                <label class="btn <?= $bc ?> btn-sm fw-bold" for="prev_nps_<?= $d['id'].'_'.$i ?>" style="min-width:38px;"><?= $i ?></label>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="d-flex justify-content-between small text-muted"><span>Per nulla d'accordo</span><span>Totalmente d'accordo</span></div>
                                <?php elseif ($tipo_k === 'matrice' && !empty($d['opzioni'])): ?>
                                    <div class="table-responsive">
                                        <table class="table table-bordered align-middle mb-0">
                                            <thead class="table-light"><tr><th style="width:40%;">Aspetto</th><th class="text-center">1</th><th class="text-center">2</th><th class="text-center">3</th><th class="text-center">4</th><th class="text-center">5</th></tr></thead>
                                            <tbody>
                                                <?php foreach(explode(',',$d['opzioni']) as $riga): $riga=trim($riga); if(empty($riga)) continue; ?>
                                                    <tr><td class="fw-bold text-secondary"><?= htmlspecialchars($riga) ?></td>
                                                    <?php for($v=1;$v<=5;$v++): ?><td class="text-center"><input class="form-check-input" type="radio" name="<?= $n_pr.'['.htmlspecialchars($riga).']' ?>" value="<?= $v ?>" style="width:18px;height:18px;"></td><?php endfor; ?></tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php elseif ($tipo_k === 'textarea'): ?>
                                    <textarea class="form-control" rows="3" placeholder="Scrivi qui il tuo feedback..."></textarea>
                                <?php elseif ($tipo_k === 'select' && !empty($d['opzioni'])): ?>
                                    <select class="form-select border-primary" name="<?= $n_pr ?>">
                                        <option value="">-- Seleziona --</option>
                                        <?php foreach(explode(',',$d['opzioni']) as $opt): ?><option value="<?= htmlspecialchars(trim($opt)) ?>"><?= htmlspecialchars(trim($opt)) ?></option><?php endforeach; ?>
                                    </select>
                                <?php elseif ($tipo_k === 'radio' && !empty($d['opzioni'])): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach(explode(',',$d['opzioni']) as $oi => $opt): $opt=trim($opt); ?>
                                            <div class="form-check p-2 border rounded bg-light m-0">
                                                <input class="form-check-input ms-1" type="radio" name="<?= $n_pr ?>" id="prev_ro_<?= $d['id'].'_'.$oi ?>" value="<?= htmlspecialchars($opt) ?>">
                                                <label class="form-check-label ms-2 fw-bold text-dark" for="prev_ro_<?= $d['id'].'_'.$oi ?>"><?= htmlspecialchars($opt) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($tipo_k === 'checkboxes' && !empty($d['opzioni'])): ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach(explode(',',$d['opzioni']) as $oi => $opt): $opt=trim($opt); ?>
                                            <div class="form-check p-2 border rounded bg-light m-0">
                                                <input class="form-check-input ms-1" type="checkbox" name="<?= $n_pr ?>[]" id="prev_co_<?= $d['id'].'_'.$oi ?>" value="<?= htmlspecialchars($opt) ?>">
                                                <label class="form-check-label ms-2 fw-bold text-dark" for="prev_co_<?= $d['id'].'_'.$oi ?>"><?= htmlspecialchars($opt) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($tipo_k === 'number'): ?>
                                    <input type="number" class="form-control" disabled placeholder="0">
                                <?php elseif ($tipo_k === 'date'): ?>
                                    <input type="date" class="form-control" disabled>
                                <?php elseif ($tipo_k === 'email'): ?>
                                    <input type="email" class="form-control" disabled placeholder="esempio@email.com">
                                <?php elseif ($tipo_k === 'tel'): ?>
                                    <input type="tel" class="form-control" disabled placeholder="+39 000 0000000">
                                <?php elseif ($tipo_k === 'url'): ?>
                                    <input type="url" class="form-control" disabled placeholder="https://...">
                                <?php elseif ($tipo_k === 'time'): ?>
                                    <input type="time" class="form-control" disabled>
                                <?php elseif ($tipo_k === 'number'): ?>
                                    <input type="number" class="form-control" placeholder="0">
                                <?php elseif ($tipo_k === 'date'): ?>
                                    <input type="date" class="form-control">
                                <?php elseif ($tipo_k === 'email'): ?>
                                    <input type="email" class="form-control" placeholder="esempio@email.com">
                                <?php elseif ($tipo_k === 'tel'): ?>
                                    <input type="tel" class="form-control" placeholder="+39 000 0000000">
                                <?php elseif ($tipo_k === 'url'): ?>
                                    <input type="url" class="form-control" placeholder="https://...">
                                <?php elseif ($tipo_k === 'time'): ?>
                                    <input type="time" class="form-control">
                                <?php else: ?>
                                    <input type="text" class="form-control" placeholder="Tua risposta">
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </form>
                    <div class="text-center mt-4">
                        <button class="btn btn-danger btn-lg fw-bold px-5" style="background:#990000;opacity:.6;" disabled><i class="fa fa-paper-plane me-2"></i> Invia Valutazione</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var modal = document.getElementById('modAnteprimaSondaggio');
    if (!modal) return;

    function getPrevVal(domId) {
        var radios = modal.querySelectorAll('input[name="prev[' + domId + ']"]:checked');
        if (radios.length > 0) return radios[0].value.toLowerCase();
        var sel = modal.querySelector('select[name="prev[' + domId + ']"]');
        if (sel) return sel.value.toLowerCase();
        return '';
    }

    function evalPrevConds() {
        modal.querySelectorAll('[data-cond-id]').forEach(function(wrap) {
            var show = (getPrevVal(wrap.dataset.condId) === wrap.dataset.condVal.toLowerCase());
            wrap.style.display = show ? '' : 'none';
            if (!show) {
                wrap.querySelectorAll('input,select,textarea').forEach(function(inp) {
                    if (inp.type === 'checkbox' || inp.type === 'radio') inp.checked = false;
                    else inp.value = '';
                });
            }
        });
    }

    modal.addEventListener('change', evalPrevConds);
    modal.addEventListener('input', evalPrevConds);
    modal.addEventListener('shown.bs.modal', evalPrevConds);
})();
</script>
<?php endif; ?>

<!-- SortableJS -->
<?php if (!$is_archivio && count($curr_domande) > 1): ?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
(function() {
    var el = document.getElementById('domSortable');
    if (!el) return;
    Sortable.create(el, {
        handle: '.sd-drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            var ids = [];
            el.querySelectorAll('[data-id]').forEach(function(c) { ids.push(c.dataset.id); });
            var fd = new FormData();
            fd.append('ajax_salva_ordine_dom', '1');
            fd.append('csrf_token', '<?= htmlspecialchars(csrf_token()) ?>');
            ids.forEach(function(id) { fd.append('ids[]', id); });
            fetch('sondaggi.php?p_id=<?= $filtro_p ?>&f_sond_ev=<?= $f_sond_ev ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .catch(function() {});
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
