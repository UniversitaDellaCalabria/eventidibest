<?php
// admin/dashboard.php - Dashboard Principale Personalizzata per Ruolo
require_once 'admin_header.php';

// Helper: esegue query e ritorna assoc array o [] se fallisce
function db_row($conn, $sql) {
    $r = $conn->query($sql);
    return ($r !== false) ? ($r->fetch_assoc() ?: []) : [];
}
function db_rows($conn, $sql) {
    $r = $conn->query($sql);
    $out = [];
    if ($r !== false) { while ($row = $r->fetch_assoc()) $out[] = $row; }
    return $out;
}

// ============================================================================
// QUERY KPI
// ============================================================================
$kpi = db_row($conn,
    "SELECT COUNT(*) as tot,
            SUM(CASE WHEN stato IN ('confermata','confermato','confirmed') THEN 1 ELSE 0 END) as confermate,
            SUM(CASE WHEN stato IN ('in_attesa','pending') THEN 1 ELSE 0 END) as in_attesa,
            SUM(CASE WHEN presente = 1 THEN 1 ELSE 0 END) as checkin_tot
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
       AND p.stato NOT IN ('annullata','annullato','cancelled')"
);

// Check-in di oggi (query separata con guard su colonna presente)
$kpi_oggi = db_row($conn,
    "SELECT COUNT(*) as checkin_oggi
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
       AND p.presente = 1
       AND DATE(t.data_turno) = CURDATE()"
);

// Messaggi non letti
$msg_unread = (int)(db_row($conn,
    "SELECT COUNT(DISTINCT m.prenotazione_id) as n
     FROM messaggi_prenotazioni m
     JOIN prenotazioni p ON m.prenotazione_id = p.id
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE m.letto = 0 AND m.mittente_tipo = 'utente'
       AND e.pagina_id = $filtro_p $sql_filtro_eventi_rbac"
)['n'] ?? 0);

// Ultime 8 prenotazioni
$last_prenotazioni = db_rows($conn,
    "SELECT p.codice_prenotazione, p.nome, p.cognome, p.stato, p.presente,
            p.data_prenotazione, e.titolo as evento_titolo,
            t.data_turno, t.orario_inizio
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
     ORDER BY p.data_prenotazione DESC
     LIMIT 8"
);

// Ultimi 5 messaggi non letti (per conversazione)
$last_msgs = db_rows($conn,
    "SELECT m.messaggio, m.data_invio,
            p.codice_prenotazione, p.nome, p.cognome, p.id as pr_id,
            e.titolo as evento_titolo
     FROM messaggi_prenotazioni m
     JOIN prenotazioni p ON m.prenotazione_id = p.id
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE m.letto = 0 AND m.mittente_tipo = 'utente'
       AND e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
     GROUP BY m.prenotazione_id
     ORDER BY m.data_invio DESC
     LIMIT 5"
);

// Prossimi turni
$next_events = db_rows($conn,
    "SELECT e.titolo, t.data_turno, t.orario_inizio,
            t.posti_totali, t.posti_rimanenti,
            COUNT(p.id) as num_iscritti
     FROM turni t
     JOIN eventi e ON t.evento_id = e.id
     LEFT JOIN prenotazioni p ON p.turno_id = t.id
       AND p.stato NOT IN ('annullata','annullato','cancelled')
     WHERE t.data_turno >= CURDATE()
       AND e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
     GROUP BY t.id
     ORDER BY t.data_turno ASC, t.orario_inizio ASC
     LIMIT 6"
);

// Prenotazioni per stato (grafico)
$stati_data = db_rows($conn,
    "SELECT p.stato, COUNT(*) as cnt
     FROM prenotazioni p
     JOIN turni t ON p.turno_id = t.id
     JOIN eventi e ON t.evento_id = e.id
     WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
     GROUP BY p.stato"
);

$area_nome   = htmlspecialchars($page_cfg['titolo'] ?? 'Tutte le Aree');
$colore_area = $page_cfg['colore_primario'] ?? '#990000';

// Attività di oggi (solo full_admin o area_manager): prenotazioni + messaggi ricevuti oggi
$attivita_oggi = [];
if ($is_full_admin || $is_area_manager) {
    $rows_pr = db_rows($conn,
        "SELECT 'prenotazione' as tipo, p.nome, p.cognome, e.titolo as evento_titolo,
                p.data_prenotazione as quando, p.codice_prenotazione as ref, p.stato
         FROM prenotazioni p
         JOIN turni t ON p.turno_id = t.id
         JOIN eventi e ON t.evento_id = e.id
         WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
           AND DATE(p.data_prenotazione) = CURDATE()
         ORDER BY p.data_prenotazione DESC LIMIT 8"
    );
    $rows_msg = db_rows($conn,
        "SELECT 'messaggio' as tipo, p.nome, p.cognome, e.titolo as evento_titolo,
                m.data_invio as quando, p.codice_prenotazione as ref, '' as stato
         FROM messaggi_prenotazioni m
         JOIN prenotazioni p ON m.prenotazione_id = p.id
         JOIN turni t ON p.turno_id = t.id
         JOIN eventi e ON t.evento_id = e.id
         WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
           AND m.mittente_tipo = 'utente'
           AND DATE(m.data_invio) = CURDATE()
         ORDER BY m.data_invio DESC LIMIT 5"
    );
    $attivita_oggi = array_merge($rows_pr, $rows_msg);
    usort($attivita_oggi, function($a, $b) {
        return strtotime($b['quando']) - strtotime($a['quando']);
    });
}

// Dati grafico
$chart_labels = []; $chart_vals = []; $chart_colors = [];
$palette = [
    'confermata' => '#198754', 'confermato' => '#198754', 'confirmed' => '#198754',
    'in_attesa'  => '#ffc107', 'pending'    => '#ffc107',
    'annullata'  => '#dc3545', 'annullato'  => '#dc3545', 'cancelled'  => '#dc3545',
];
foreach ($stati_data as $sd) {
    $chart_labels[] = ucfirst(str_replace(['_','-'], ' ', $sd['stato']));
    $chart_vals[]   = (int)$sd['cnt'];
    $chart_colors[] = $palette[strtolower($sd['stato'])] ?? '#6c757d';
}
?>

<style>
.dash-kpi { border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; }
.dash-kpi-bar { height:4px; }
.dash-card { border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; }
.dash-card-header { padding:12px 16px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.dash-card-header .label { font-size:.78rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
.turno-row { display:flex; align-items:center; gap:12px; padding:10px 16px; border-bottom:1px solid #f1f5f9; font-size:.83rem; }
.turno-row:last-child { border-bottom:none; }
.pr-row { display:flex; align-items:center; gap:10px; padding:9px 16px; border-bottom:1px solid #f1f5f9; font-size:.82rem; }
.pr-row:last-child { border-bottom:none; }
.quick-link { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; padding:14px 8px; border-radius:10px; border:1px solid #e2e8f0; background:#f8fafc; text-decoration:none; color:#334155; font-size:.72rem; font-weight:600; transition:all .15s; }
.quick-link:hover { background:#f1f5f9; color:#0f172a; box-shadow:0 2px 8px rgba(0,0,0,.08); }
.quick-link i { font-size:1.25rem; }
</style>

<!-- ── HEADER ──────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0 text-dark">Dashboard</h4>
        <div class="text-muted mt-1" style="font-size:.83rem;">
            Benvenuto/a, <strong><?php echo htmlspecialchars($utente_admin['nome'] ?? ''); ?></strong>
            &mdash; <span style="color:<?php echo $colore_area; ?>;"><?php echo $area_nome; ?></span>
            &mdash; <?php echo date('l d F Y'); ?>
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <?php if ($can_manage_iscritti): ?>
        <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold position-relative" style="border-radius:8px;">
            <i class="fa fa-envelope me-1"></i>Messaggi
            <?php if ($msg_unread > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?php echo $msg_unread; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="scanner.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-sm fw-bold text-white" style="background:<?php echo $colore_area; ?>;border-radius:8px;border:none;">
            <i class="fa fa-qrcode me-1"></i>Apri Scanner
        </a>
    </div>
</div>

<!-- ── KPI ────────────────────────────────────────────────── -->
<?php
$kpi_defs = [
    ['val' => (int)($kpi['tot'] ?? 0),              'label' => 'Prenotazioni totali', 'icon' => 'fa-ticket-alt',                 'color' => $colore_area, 'text' => '#fff'],
    ['val' => (int)($kpi['confermate'] ?? 0),        'label' => 'Confermate',          'icon' => 'fa-circle-check',               'color' => '#16a34a',    'text' => '#fff'],
    ['val' => (int)($kpi['in_attesa'] ?? 0),         'label' => 'In attesa',           'icon' => 'fa-clock',                      'color' => '#d97706',    'text' => '#fff'],
    ['val' => (int)($kpi_oggi['checkin_oggi'] ?? 0), 'label' => 'Check-in oggi',       'icon' => 'fa-person-walking-arrow-right',  'color' => '#0891b2',    'text' => '#fff'],
];
?>
<div class="row g-3 mb-4">
<?php foreach ($kpi_defs as $k): ?>
    <div class="col-6 col-lg-3">
        <div class="dash-kpi">
            <div class="dash-kpi-bar" style="background:<?php echo $k['color']; ?>;"></div>
            <div class="p-3 d-flex align-items-center gap-3">
                <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center"
                     style="width:44px;height:44px;background:<?php echo $k['color']; ?>18;">
                    <i class="fa <?php echo $k['icon']; ?>" style="color:<?php echo $k['color']; ?>;font-size:1.1rem;"></i>
                </div>
                <div>
                    <div class="fw-bold lh-1 mb-1" style="font-size:1.6rem;color:#0f172a;"><?php echo $k['val']; ?></div>
                    <div style="font-size:.75rem;color:#64748b;"><?php echo $k['label']; ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- ── CORPO ──────────────────────────────────────────────── -->
<div class="row g-3">

    <!-- COLONNA SINISTRA -->
    <div class="col-lg-8 d-flex flex-column gap-3">

        <!-- Ultime prenotazioni -->
        <?php if ($can_manage_iscritti && !empty($last_prenotazioni)): ?>
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="label"><i class="fa fa-clock-rotate-left me-1" style="color:<?php echo $colore_area; ?>"></i>Ultime prenotazioni</span>
                <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:7px;font-size:.75rem;">Vedi tutte →</a>
            </div>
            <?php foreach ($last_prenotazioni as $pr):
                $s = strtolower($pr['stato'] ?? '');
                if (in_array($s, ['confermata','confermato','confirmed'])) { $bc='#dcfce7'; $tc='#166534'; $bl='Confermata'; }
                elseif (in_array($s, ['in_attesa','pending']))             { $bc='#fef3c7'; $tc='#92400e'; $bl='In attesa'; }
                else                                                        { $bc='#fee2e2'; $tc='#991b1b'; $bl='Annullata'; }
            ?>
            <div class="pr-row">
                <div class="flex-shrink-0">
                    <?php if (!empty($pr['presente']) && $pr['presente'] == 1): ?>
                        <span class="rounded-circle d-flex align-items-center justify-content-center" style="width:30px;height:30px;background:#dcfce7;"><i class="fa fa-check" style="color:#16a34a;font-size:.7rem;"></i></span>
                    <?php else: ?>
                        <span class="rounded-circle d-flex align-items-center justify-content-center" style="width:30px;height:30px;background:#f1f5f9;"><i class="fa fa-user" style="color:#94a3b8;font-size:.7rem;"></i></span>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="fw-semibold text-dark" style="font-size:.83rem;"><?php echo htmlspecialchars($pr['nome'] . ' ' . $pr['cognome']); ?></div>
                    <div class="text-muted text-truncate" style="font-size:.72rem;"><?php echo htmlspecialchars($pr['evento_titolo']); ?></div>
                </div>
                <div class="text-end flex-shrink-0">
                    <span class="badge fw-semibold" style="background:<?php echo $bc; ?>;color:<?php echo $tc; ?>;font-size:.68rem;"><?php echo $bl; ?></span>
                    <div class="text-muted mt-1" style="font-size:.7rem;"><?php echo $pr['data_turno'] ? date('d/m', strtotime($pr['data_turno'])) . ' ' . substr($pr['orario_inizio'] ?? '', 0, 5) : '—'; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Attività di oggi -->
        <?php if (!empty($attivita_oggi)): ?>
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="label"><i class="fa fa-bolt me-1" style="color:#d97706;"></i>Attività di oggi <span class="badge ms-1" style="background:#f1f5f9;color:#475569;font-size:.65rem;"><?php echo count($attivita_oggi); ?></span></span>
                <span style="font-size:.72rem;color:#94a3b8;"><?php echo date('d/m/Y'); ?></span>
            </div>
            <?php foreach ($attivita_oggi as $att):
                $is_msg = ($att['tipo'] === 'messaggio');
                $a_icon = $is_msg ? 'fa-envelope' : 'fa-ticket-alt';
                $a_bg   = $is_msg ? '#fee2e2' : '#dcfce7';
                $a_col  = $is_msg ? '#dc2626' : '#16a34a';
                $a_lbl  = $is_msg ? 'Messaggio' : 'Prenotazione';
            ?>
            <div class="pr-row">
                <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center" style="width:30px;height:30px;background:<?php echo $a_bg; ?>;"><i class="fa <?php echo $a_icon; ?>" style="color:<?php echo $a_col; ?>;font-size:.7rem;"></i></div>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="fw-semibold text-dark" style="font-size:.83rem;"><?php echo htmlspecialchars($att['nome'] . ' ' . $att['cognome']); ?></div>
                    <div class="text-muted text-truncate" style="font-size:.72rem;"><?php echo htmlspecialchars($att['evento_titolo']); ?></div>
                </div>
                <div class="text-end flex-shrink-0">
                    <div style="font-size:.7rem;color:#94a3b8;"><?php echo date('H:i', strtotime($att['quando'])); ?></div>
                    <span class="badge mt-1" style="background:<?php echo $a_bg; ?>;color:<?php echo $a_col; ?>;font-size:.65rem;"><?php echo $a_lbl; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Prossimi turni -->
        <?php if ($can_manage_eventi && !empty($next_events)): ?>
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="label"><i class="fa fa-calendar-days me-1" style="color:<?php echo $colore_area; ?>;"></i>Prossimi turni</span>
                <a href="eventi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:7px;font-size:.75rem;">Gestisci →</a>
            </div>
            <?php foreach ($next_events as $ev):
                $max_p = max(1, (int)($ev['posti_totali'] ?? 30));
                $pct   = min(100, round(($ev['num_iscritti'] / $max_p) * 100));
                $bar_c = $pct >= 90 ? '#dc2626' : ($pct >= 60 ? '#d97706' : '#16a34a');
            ?>
            <div class="turno-row">
                <div class="flex-shrink-0 text-center" style="width:46px;">
                    <div class="fw-bold" style="font-size:.95rem;color:<?php echo $colore_area; ?>;"><?php echo date('d', strtotime($ev['data_turno'])); ?></div>
                    <div style="font-size:.65rem;color:#94a3b8;text-transform:uppercase;"><?php echo date('M', strtotime($ev['data_turno'])); ?></div>
                </div>
                <div class="flex-grow-1" style="min-width:0;">
                    <div class="fw-semibold text-dark text-truncate" style="font-size:.83rem;"><?php echo htmlspecialchars($ev['titolo']); ?></div>
                    <div style="font-size:.72rem;color:#64748b;"><i class="fa fa-clock me-1"></i><?php echo substr($ev['orario_inizio'], 0, 5); ?></div>
                </div>
                <div class="flex-shrink-0 text-end" style="min-width:90px;">
                    <div style="font-size:.75rem;color:#475569;font-weight:600;"><?php echo $ev['num_iscritti']; ?> / <?php echo $max_p; ?></div>
                    <div class="rounded-pill mt-1" style="height:5px;background:#e2e8f0;overflow:hidden;">
                        <div style="height:5px;width:<?php echo $pct; ?>%;background:<?php echo $bar_c; ?>;transition:width .3s;"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- COLONNA DESTRA -->
    <div class="col-lg-4 d-flex flex-column gap-3">

        <!-- Accessi rapidi -->
        <div class="dash-card p-3">
            <div class="label mb-3"><i class="fa fa-bolt me-1" style="color:<?php echo $colore_area; ?>;"></i>Accessi rapidi</div>
            <div class="row g-2">
                <?php
                $links = [
                    ['href'=>'eventi.php?p_id='.$filtro_p,    'icon'=>'fa-calendar-alt',    'label'=>'Eventi',    'color'=>$colore_area],
                    ['href'=>'iscritti.php?p_id='.$filtro_p,  'icon'=>'fa-users',            'label'=>'Iscritti',  'color'=>'#0891b2'],
                    ['href'=>'messaggi.php?p_id='.$filtro_p,  'icon'=>'fa-envelope',         'label'=>'Messaggi',  'color'=>'#dc2626'],
                    ['href'=>'scanner.php?p_id=' . $filtro_p, 'icon'=>'fa-qrcode',           'label'=>'Scanner',   'color'=>'#16a34a'],
                ];
                foreach ($links as $lk): ?>
                <div class="col-6">
                    <a href="<?php echo $lk['href']; ?>" class="quick-link" <?php echo !empty($lk['target']) ? 'target="'.$lk['target'].'"' : ''; ?>>
                        <i class="fa <?php echo $lk['icon']; ?>" style="color:<?php echo $lk['color']; ?>;"></i>
                        <?php echo $lk['label']; ?>
                        <?php if ($lk['label']==='Messaggi' && $msg_unread > 0): ?>
                            <span class="badge bg-danger" style="font-size:.6rem;"><?php echo $msg_unread; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Messaggi non letti -->
        <?php if ($can_manage_iscritti): ?>
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="label"><i class="fa fa-envelope-open-text me-1" style="color:#dc2626;"></i>Messaggi non letti<?php if ($msg_unread > 0): ?> <span class="badge bg-danger ms-1" style="font-size:.65rem;"><?php echo $msg_unread; ?></span><?php endif; ?></span>
                <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm" style="border-radius:7px;font-size:.75rem;">Inbox →</a>
            </div>
            <?php if (!empty($last_msgs)): ?>
                <?php foreach ($last_msgs as $msg): ?>
                <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>&ticket=<?php echo $msg['pr_id']; ?>" class="pr-row text-decoration-none" style="display:flex;">
                    <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center" style="width:30px;height:30px;background:#fee2e2;"><i class="fa fa-envelope" style="color:#dc2626;font-size:.7rem;"></i></div>
                    <div class="flex-grow-1 ms-2" style="min-width:0;">
                        <div class="fw-semibold text-dark" style="font-size:.82rem;"><?php echo htmlspecialchars($msg['nome'] . ' ' . $msg['cognome']); ?></div>
                        <div class="text-muted text-truncate" style="font-size:.7rem;"><?php echo htmlspecialchars(strip_tags($msg['messaggio'])); ?></div>
                    </div>
                    <div class="flex-shrink-0 text-muted ms-2" style="font-size:.68rem;"><?php echo $msg['data_invio'] ? date('H:i', strtotime($msg['data_invio'])) : ''; ?></div>
                </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center text-muted py-4" style="font-size:.82rem;"><i class="fa fa-check-circle text-success me-1"></i>Nessun messaggio non letto</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Grafico stati -->
        <?php if ($can_manage_iscritti && !empty($stati_data)): ?>
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="label"><i class="fa fa-chart-pie me-1" style="color:<?php echo $colore_area; ?>;"></i>Distribuzione stati</span>
            </div>
            <div class="p-3 text-center">
                <canvas id="chartStati" width="160" height="160"></canvas>
                <div class="mt-3 d-flex flex-column gap-1">
                    <?php foreach ($stati_data as $i => $sd): ?>
                    <div class="d-flex align-items-center justify-content-between px-2" style="font-size:.78rem;">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle" style="width:10px;height:10px;background:<?php echo $chart_colors[$i]; ?>;display:inline-block;flex-shrink:0;"></span>
                            <span class="text-dark"><?php echo $chart_labels[$i]; ?></span>
                        </div>
                        <span class="fw-bold" style="color:<?php echo $chart_colors[$i]; ?>"><?php echo $sd['cnt']; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        (function(){
            var ctx = document.getElementById('chartStati');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{ data: <?php echo json_encode($chart_vals); ?>, backgroundColor: <?php echo json_encode($chart_colors); ?>, borderWidth: 0 }]
                },
                options: { cutout:'68%', plugins:{ legend:{ display:false } }, responsive:true, maintainAspectRatio:true }
            });
        })();
        </script>
        <?php endif; ?>

    </div>

</div>

<?php require_once 'admin_footer.php'; ?>
