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

<!-- TITOLO -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa fa-gauge-high me-2 text-danger"></i> Dashboard</h4>
        <small class="text-muted">
            Benvenuto/a, <strong><?php echo htmlspecialchars($utente_admin['nome'] ?? ''); ?></strong>
            &mdash; <?php echo $area_nome; ?>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="../checkin.php" target="_blank" class="btn btn-warning fw-bold shadow-sm">
            <i class="fa fa-qrcode me-1"></i> Scanner Check-in
        </a>
        <?php if ($can_manage_iscritti): ?>
        <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-danger fw-bold shadow-sm position-relative">
            <i class="fa fa-envelope me-1"></i> Messaggi
            <?php if ($msg_unread > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?php echo $msg_unread; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI CARDS -->
<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['val' => (int)($kpi['tot'] ?? 0),             'label' => 'Prenotazioni totali',  'icon' => 'fa-ticket-alt',                'color' => $colore_area,  'text' => 'white'],
        ['val' => (int)($kpi['confermate'] ?? 0),       'label' => 'Confermate',           'icon' => 'fa-check-circle',              'color' => '#198754',     'text' => 'white'],
        ['val' => (int)($kpi['in_attesa'] ?? 0),        'label' => 'In attesa',            'icon' => 'fa-clock',                     'color' => '#ffc107',     'text' => 'dark'],
        ['val' => (int)($kpi_oggi['checkin_oggi'] ?? 0),'label' => 'Check-in oggi',        'icon' => 'fa-person-walking-arrow-right','color' => '#0dcaf0',     'text' => 'dark'],
    ];
    foreach ($cards as $c): ?>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid <?php echo $c['color']; ?> !important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-<?php echo $c['text']; ?> flex-shrink-0"
                     style="width:48px;height:48px;background:<?php echo $c['color']; ?>;">
                    <i class="fa <?php echo $c['icon']; ?>"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold lh-1"><?php echo $c['val']; ?></div>
                    <div class="text-muted small"><?php echo $c['label']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">

    <!-- COLONNA SINISTRA -->
    <div class="col-lg-8">

        <!-- Ultime prenotazioni -->
        <?php if ($can_manage_iscritti): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold small"><i class="fa fa-clock-rotate-left me-1 text-danger"></i> Ultime prenotazioni</span>
                <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm">Vedi tutte &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket</th>
                                <th>Iscritto</th>
                                <th>Evento</th>
                                <th>Turno</th>
                                <th>Stato</th>
                                <th>Presenza</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($last_prenotazioni)): ?>
                            <?php foreach ($last_prenotazioni as $pr): ?>
                            <tr>
                                <td><code class="small"><?php echo htmlspecialchars($pr['codice_prenotazione']); ?></code></td>
                                <td><?php echo htmlspecialchars($pr['nome'] . ' ' . $pr['cognome']); ?></td>
                                <td class="text-truncate" style="max-width:130px;" title="<?php echo htmlspecialchars($pr['evento_titolo']); ?>">
                                    <?php echo htmlspecialchars($pr['evento_titolo']); ?>
                                </td>
                                <td><?php echo $pr['data_turno'] ? date('d/m', strtotime($pr['data_turno'])) . ' ' . substr($pr['orario_inizio'] ?? '', 0, 5) : '—'; ?></td>
                                <td>
                                    <?php
                                    $s = strtolower($pr['stato'] ?? '');
                                    if (in_array($s, ['confermata','confermato','confirmed']))
                                        echo '<span class="badge bg-success">Confermata</span>';
                                    elseif (in_array($s, ['in_attesa','pending']))
                                        echo '<span class="badge bg-warning text-dark">In attesa</span>';
                                    elseif (in_array($s, ['annullata','annullato','cancelled']))
                                        echo '<span class="badge bg-danger">Annullata</span>';
                                    else
                                        echo '<span class="badge bg-secondary">' . htmlspecialchars($pr['stato'] ?? '') . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($pr['presente']) && $pr['presente'] == 1): ?>
                                        <span class="badge bg-success"><i class="fa fa-check"></i></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Nessuna prenotazione trovata</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attività di oggi -->
        <?php if (!empty($attivita_oggi)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold small"><i class="fa fa-bolt me-1 text-warning"></i> Attività di oggi
                    <span class="badge bg-secondary ms-1"><?php echo count($attivita_oggi); ?></span>
                </span>
                <span class="text-muted small"><?php echo date('d/m/Y'); ?></span>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($attivita_oggi as $att):
                    $is_msg = ($att['tipo'] === 'messaggio');
                    $icon   = $is_msg ? 'fa-envelope text-danger' : 'fa-ticket-alt text-success';
                    $label  = $is_msg ? 'Messaggio' : 'Prenotazione';
                    $bg     = $is_msg ? 'rgba(220,53,69,.1)' : 'rgba(25,135,84,.1)';
                    $badge  = $is_msg ? 'bg-danger' : 'bg-success';
                ?>
                <div class="list-group-item border-0 border-bottom py-2 px-3 d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:32px;height:32px;background:<?php echo $bg; ?>;">
                        <i class="fa <?php echo $icon; ?>" style="font-size:.8rem;"></i>
                    </div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="small fw-bold text-truncate"><?php echo htmlspecialchars($att['nome'] . ' ' . $att['cognome']); ?></div>
                        <div class="text-muted text-truncate" style="font-size:.72rem;"><?php echo htmlspecialchars($att['evento_titolo']); ?></div>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <div class="text-muted" style="font-size:.7rem;"><?php echo date('H:i', strtotime($att['quando'])); ?></div>
                        <span class="badge <?php echo $badge; ?> bg-opacity-75" style="font-size:.65rem;"><?php echo $label; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Prossimi turni -->
        <?php if ($can_manage_eventi && !empty($next_events)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold small"><i class="fa fa-calendar-days me-1 text-primary"></i> Prossimi turni</span>
                <a href="eventi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm">Gestisci &rarr;</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 small">
                        <thead class="table-light">
                            <tr><th>Evento</th><th>Data</th><th>Ora</th><th>Iscritti / Posti</th><th style="min-width:80px;">Fill</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($next_events as $ev):
                            $tot = max(1, (int)($ev['posti_totali'] ?? 1));
                            $pct = min(100, round(($ev['num_iscritti'] / $tot) * 100));
                            $bar = $pct >= 90 ? 'bg-danger' : ($pct >= 60 ? 'bg-warning' : 'bg-success');
                        ?>
                            <tr>
                                <td class="text-truncate" style="max-width:150px;" title="<?php echo htmlspecialchars($ev['titolo']); ?>"><?php echo htmlspecialchars($ev['titolo']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($ev['data_turno'])); ?></td>
                                <td><?php echo substr($ev['orario_inizio'], 0, 5); ?></td>
                                <td><?php echo $ev['num_iscritti'] . ' / ' . $ev['posti_totali']; ?></td>
                                <td>
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar <?php echo $bar; ?>" style="width:<?php echo $pct; ?>%"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /col-lg-8 -->

    <!-- COLONNA DESTRA -->
    <div class="col-lg-4">

        <!-- Scanner QR -->
        <div class="card border-0 shadow-sm mb-3" style="background:linear-gradient(135deg,#1e293b 0%,#334155 100%);">
            <div class="card-body text-center py-4">
                <div class="mb-2 text-warning"><i class="fa fa-qrcode fa-3x"></i></div>
                <h6 class="text-white fw-bold mb-1">Scanner Presenze</h6>
                <p class="small mb-3" style="color:#94a3b8;">Apri il lettore QR per registrare i check-in in tempo reale</p>
                <a href="../checkin.php" target="_blank" class="btn btn-warning fw-bold px-4">
                    <i class="fa fa-camera me-1"></i> Apri Scanner
                </a>
            </div>
        </div>

        <!-- Messaggi non letti -->
        <?php if ($can_manage_iscritti): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-2">
                <span class="fw-bold small">
                    <i class="fa fa-envelope-open-text me-1 text-danger"></i> Messaggi non letti
                    <?php if ($msg_unread > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo $msg_unread; ?></span>
                    <?php endif; ?>
                </span>
                <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm">Inbox &rarr;</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (!empty($last_msgs)): ?>
                    <?php foreach ($last_msgs as $msg): ?>
                    <a href="messaggi.php?p_id=<?php echo $filtro_p; ?>&ticket=<?php echo $msg['pr_id']; ?>"
                       class="list-group-item list-group-item-action py-2 px-3 border-0 border-bottom">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold small"><?php echo htmlspecialchars($msg['nome'] . ' ' . $msg['cognome']); ?></span>
                            <span class="text-muted" style="font-size:.7rem;"><?php echo $msg['data_invio'] ? date('d/m H:i', strtotime($msg['data_invio'])) : ''; ?></span>
                        </div>
                        <div class="text-muted small text-truncate"><?php echo htmlspecialchars(strip_tags($msg['messaggio'])); ?></div>
                        <div class="text-secondary" style="font-size:.7rem;"><?php echo htmlspecialchars($msg['evento_titolo']); ?></div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="list-group-item border-0 text-center text-muted small py-3">
                        <i class="fa fa-check-circle text-success me-1"></i> Nessun messaggio non letto
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Grafico stati -->
        <?php if ($can_manage_iscritti && !empty($stati_data)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-2">
                <span class="fw-bold small"><i class="fa fa-chart-pie me-1 text-primary"></i> Distribuzione stati</span>
            </div>
            <div class="card-body text-center py-3">
                <canvas id="chartStati" width="180" height="180"></canvas>
                <div class="mt-2 d-flex flex-wrap justify-content-center gap-2">
                    <?php foreach ($stati_data as $i => $sd): ?>
                        <span class="badge" style="background:<?php echo $chart_colors[$i]; ?>;font-size:.7rem;">
                            <?php echo $chart_labels[$i]; ?>: <?php echo $sd['cnt']; ?>
                        </span>
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
                    datasets: [{
                        data: <?php echo json_encode($chart_vals); ?>,
                        backgroundColor: <?php echo json_encode($chart_colors); ?>,
                        borderWidth: 2
                    }]
                },
                options: {
                    cutout: '65%',
                    plugins: { legend: { display: false } },
                    responsive: true,
                    maintainAspectRatio: true
                }
            });
        })();
        </script>
        <?php endif; ?>

    </div><!-- /col-lg-4 -->

</div><!-- /row -->

<?php require_once 'admin_footer.php'; ?>
