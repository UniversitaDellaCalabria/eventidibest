<?php
// admin/statistiche.php
ob_start();
require_once 'admin_header.php';

if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-ban me-2'></i> Accesso negato alle statistiche.</div>";
    require_once 'admin_footer.php';
    exit;
}

// ============================================================================
// ESPORTAZIONE EXCEL / CSV
// ============================================================================
if (isset($_GET['export_csv']) || isset($_GET['export_excel'])) {

    ob_end_clean();

    $is_excel = isset($_GET['export_excel']);
    $filename = "Export_Iscritti_" . date('Ymd_His') . ($is_excel ? ".xls" : ".csv");

    if ($is_excel) {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename\"");
        echo "<html xmlns:x=\"urn:schemas-microsoft-com:office:excel\">
              <head><meta charset=\"utf-8\"></head><body>";
        echo "<table border='1' style='font-family: Arial;'>";
        echo "<tr style='background-color:#1e293b; color:white;'>
                <th>ID Ticket</th><th>Stato</th><th>Evento</th><th>Turno (Data e Ora)</th>
                <th>Nome</th><th>Cognome</th><th>Email</th><th>Matricola</th>
                <th>Posti Prenotati</th><th>Presente</th><th>Data Prenotazione</th>
              </tr>";
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo chr(0xEF).chr(0xBB).chr(0xBF);
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID Ticket', 'Stato', 'Evento', 'Turno (Data e Ora)', 'Nome', 'Cognome', 'Email', 'Matricola', 'Posti Prenotati', 'Presente', 'Data Prenotazione'], ';');
    }

    $sql_export = "SELECT p.*, e.titolo as evento_titolo, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine
                   FROM prenotazioni p
                   JOIN turni t ON p.turno_id = t.id
                   JOIN eventi e ON t.evento_id = e.id
                   JOIN pagine_eventi pe ON e.pagina_id = pe.id
                   WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
                   ORDER BY t.data_turno ASC, t.orario_inizio ASC, p.data_prenotazione ASC";

    $res_export = $conn->query($sql_export);
    if ($res_export) {
        while ($r = $res_export->fetch_assoc()) {
            $turno_str         = etichetta_turno($r);
            $data_prenotazione = date('d/m/Y H:i', strtotime($r['data_prenotazione']));
            $stato_str         = strtoupper($r['stato']);
            $presente_str      = $r['presente'] ? 'Sì' : 'No';

            if ($is_excel) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($r['codice_prenotazione']) . "</td>";
                echo "<td>" . htmlspecialchars($stato_str) . "</td>";
                echo "<td>" . htmlspecialchars($r['evento_titolo']) . "</td>";
                echo "<td>" . htmlspecialchars($turno_str) . "</td>";
                echo "<td>" . htmlspecialchars($r['nome']) . "</td>";
                echo "<td>" . htmlspecialchars($r['cognome']) . "</td>";
                echo "<td>" . htmlspecialchars($r['email']) . "</td>";
                echo "<td>" . htmlspecialchars($r['matricola']) . "</td>";
                echo "<td>" . htmlspecialchars($r['num_posti']) . "</td>";
                echo "<td>" . $presente_str . "</td>";
                echo "<td>" . $data_prenotazione . "</td>";
                echo "</tr>";
            } else {
                fputcsv($output, [
                    $r['codice_prenotazione'], $stato_str, $r['evento_titolo'], $turno_str,
                    $r['nome'], $r['cognome'], $r['email'], $r['matricola'],
                    $r['num_posti'], $presente_str, $data_prenotazione
                ], ';');
            }
        }
    }

    if ($is_excel) { echo "</table></body></html>"; } else { fclose($output); }
    exit;
}

// ============================================================================
// CALCOLO KPI
// ============================================================================
$kpi = get_kpi_statistiche_v2($conn, $filtro_p, $sql_filtro_eventi_rbac);

$tasso_presenza = 0;
if ($kpi['confermate'] > 0) {
    $tasso_presenza = round(($kpi['presenti'] / $kpi['confermate']) * 100);
}

$tasso_riempimento = 0;
if ($kpi['capienza'] > 0) {
    $tasso_riempimento = min(100, round(($kpi['confermate'] / $kpi['capienza']) * 100));
}

// ============================================================================
// DATI GRAFICI E TABELLA
// ============================================================================
$grafico     = get_dati_grafico_eventi($conn, $filtro_p, $sql_filtro_eventi_rbac);
$trend_data  = get_trend_iscrizioni($conn, $filtro_p, $sql_filtro_eventi_rbac, 30);
$stats_turni = get_stats_turni_ext($conn, $filtro_p, $sql_filtro_eventi_rbac);

$oggi = date('Y-m-d');

// Separate turni passati e futuri (i turni senza data restano sempre "Live")
$turni_futuri  = [];
$turni_passati = [];
foreach ($stats_turni as $st) {
    if (empty($st['data_turno']) || $st['data_turno'] >= $oggi) {
        $turni_futuri[] = $st;
    } else {
        $turni_passati[] = $st;
    }
}

// Dataset grafico presenti/assenti per evento (dagli stats_turni raggruppati per evento)
$ev_labels_pres = [];
$ev_presenti    = [];
$ev_assenti     = [];
$ev_map         = [];
foreach ($stats_turni as $st) {
    $tit = mb_strlen($st['evento_titolo']) > 22 ? mb_substr($st['evento_titolo'], 0, 19) . '…' : $st['evento_titolo'];
    if (!isset($ev_map[$tit])) {
        $ev_map[$tit] = ['presenti' => 0, 'assenti' => 0];
    }
    $ev_map[$tit]['presenti'] += (int)$st['presenti'];
    $ev_map[$tit]['assenti']  += max(0, (int)$st['confermati'] - (int)$st['presenti']);
}
foreach ($ev_map as $label => $vals) {
    $ev_labels_pres[] = '"' . addslashes($label) . '"';
    $ev_presenti[]    = $vals['presenti'];
    $ev_assenti[]     = $vals['assenti'];
}

// Dataset trend iscrizioni
$trend_labels = [];
$trend_counts = [];
foreach ($trend_data as $t) {
    $trend_labels[] = '"' . date('d/m', strtotime($t['giorno'])) . '"';
    $trend_counts[] = (int)$t['cnt'];
}
?>

<style>
.kpi-card { border-left-width: 4px !important; transition: transform .15s; }
.kpi-card:hover { transform: translateY(-2px); }
.kpi-icon { width: 46px; height: 46px; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.progress-sm { height: 6px; border-radius: 3px; }
.section-separator { border-top: 2px dashed #dee2e6; margin: 2rem 0 1.5rem; }
@media print {
    #wrapper { display: block !important; }
    #sidebar, #sidebarOverlay, .navbar, .btn, .no-print { display: none !important; }
    #page-content-wrapper { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }
    body { font-size: 11px; }
    canvas { max-width: 100% !important; }
}
</style>

<!-- HEADER -->
<div class="d-flex justify-content-between align-items-start align-items-md-center flex-wrap gap-3 mb-4">
    <h4 class="fw-bold text-dark m-0">
        <i class="fa fa-chart-pie text-primary me-2"></i>
        Statistiche & Report &mdash; <?php echo htmlspecialchars($page_cfg['titolo'] ?? 'Area'); ?>
    </h4>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a href="?p_id=<?php echo $filtro_p; ?>&export_csv=1" class="btn btn-outline-secondary btn-sm fw-bold shadow-sm">
            <i class="fa fa-file-csv me-1"></i> CSV
        </a>
        <a href="?p_id=<?php echo $filtro_p; ?>&export_excel=1" class="btn btn-success btn-sm fw-bold shadow-sm">
            <i class="fa fa-file-excel me-1"></i> Excel
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm fw-bold shadow-sm">
            <i class="fa fa-print me-1"></i> Stampa
        </button>
    </div>
</div>

<!-- KPI: riga 1 - iscrizioni -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card shadow-sm border-0 border-start border-5 border-success kpi-card h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="kpi-icon bg-success bg-opacity-10 text-success"><i class="fa fa-check-circle"></i></div>
                <div>
                    <p class="text-muted fw-bold small text-uppercase mb-0" style="font-size:.65rem;">Confermati</p>
                    <h3 class="fw-bold text-success m-0 lh-1"><?php echo $kpi['confermate']; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card shadow-sm border-0 border-start border-5 border-primary kpi-card h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="kpi-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-user-check"></i></div>
                <div>
                    <p class="text-muted fw-bold small text-uppercase mb-0" style="font-size:.65rem;">Presenze</p>
                    <h3 class="fw-bold text-primary m-0 lh-1"><?php echo $kpi['presenti']; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card shadow-sm border-0 border-start border-5 kpi-card h-100" style="border-color:#0dcaf0!important;">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="kpi-icon text-info" style="background:rgba(13,202,240,.1);"><i class="fa fa-percent"></i></div>
                <div>
                    <p class="text-muted fw-bold small text-uppercase mb-0" style="font-size:.65rem;">Tasso presenza</p>
                    <h3 class="fw-bold text-info m-0 lh-1"><?php echo $tasso_presenza; ?>%</h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card shadow-sm border-0 border-start border-5 border-danger kpi-card h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="kpi-icon bg-danger bg-opacity-10 text-danger"><i class="fa fa-ban"></i></div>
                <div>
                    <p class="text-muted fw-bold small text-uppercase mb-0" style="font-size:.65rem;">Annullate</p>
                    <h3 class="fw-bold text-danger m-0 lh-1"><?php echo $kpi['annullate']; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card shadow-sm border-0 border-start border-5 border-warning kpi-card h-100">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="kpi-icon bg-warning bg-opacity-10 text-warning"><i class="fa fa-hourglass-half"></i></div>
                <div>
                    <p class="text-muted fw-bold small text-uppercase mb-0" style="font-size:.65rem;">In attesa</p>
                    <h3 class="fw-bold text-warning m-0 lh-1"><?php echo $kpi['attesa']; ?></h3>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card shadow-sm border-0 border-start border-5 kpi-card h-100" style="border-color:#6f42c1!important;">
            <div class="card-body p-3 d-flex align-items-center gap-3">
                <div class="kpi-icon" style="background:rgba(111,66,193,.1);color:#6f42c1;"><i class="fa fa-users"></i></div>
                <div>
                    <p class="text-muted fw-bold small text-uppercase mb-0" style="font-size:.65rem;">Riempimento</p>
                    <h3 class="fw-bold m-0 lh-1" style="color:#6f42c1;"><?php echo $tasso_riempimento; ?>%</h3>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- GRAFICI riga 1: doughnut + presenti/assenti -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="fw-bold text-dark m-0"><i class="fa fa-chart-pie text-primary me-2"></i> Ripartizione Iscritti</h6>
            </div>
            <div class="card-body d-flex justify-content-center align-items-center">
                <div style="width:100%;max-width:260px;">
                    <canvas id="chartStati"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="fw-bold text-dark m-0"><i class="fa fa-chart-column text-success me-2"></i> Presenti vs Assenti per Evento</h6>
            </div>
            <div class="card-body">
                <canvas id="chartPresenze" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- GRAFICO riga 2: trend iscrizioni -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h6 class="fw-bold text-dark m-0"><i class="fa fa-chart-line text-primary me-2"></i> Trend Iscrizioni &mdash; ultimi 30 giorni</h6>
    </div>
    <div class="card-body">
        <canvas id="chartTrend" height="100"></canvas>
    </div>
</div>

<!-- TABELLA DETTAGLIATA -->
<?php
$render_table = function($rows, $label) {
    if (empty($rows)) {
        echo "<p class='text-muted fst-italic py-2 px-1'>Nessun turno.</p>";
        return;
    }
    ?>
    <div class="table-responsive">
    <table class="table table-hover align-middle m-0">
        <thead class="table-light">
            <tr>
                <th class="px-3 py-3">Evento</th>
                <th>Data e Ora</th>
                <th class="text-center">Cap.</th>
                <th class="text-center text-success">Confermati</th>
                <th class="text-center text-primary">Presenti</th>
                <th class="text-center text-danger">Annullate</th>
                <th class="text-center">Riempimento</th>
                <th class="text-center text-warning">Attesa</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $st):
            $is_libero = $st['max_posti'] >= 9000;

            if ($is_libero) {
                $cap_label  = '<span class="badge bg-secondary">Libero</span>';
                $fill_bar   = '';
                $liberi_str = '&infin;';
                $fill_perc  = 0;
            } else {
                $fill_perc  = ($st['max_posti'] > 0) ? min(100, round(($st['confermati'] / $st['max_posti']) * 100)) : 0;
                $bar_col    = $fill_perc >= 90 ? 'bg-danger' : ($fill_perc >= 60 ? 'bg-warning' : 'bg-success');
                $cap_label  = $st['max_posti'];
                $fill_bar   = '<div class="progress progress-sm mt-1"><div class="progress-bar '.$bar_col.'" style="width:'.$fill_perc.'%"></div></div>';
            }

            $pres_perc = ($st['confermati'] > 0) ? round(($st['presenti'] / $st['confermati']) * 100) : 0;
        ?>
        <tr>
            <td class="px-3 py-3 fw-bold text-dark"><?php echo htmlspecialchars($st['evento_titolo']); ?></td>
            <td>
                <?php if (!empty($st['nome_turno'])): ?><div class="fw-semibold"><i class="fa fa-tag text-secondary me-1 small"></i><?php echo htmlspecialchars($st['nome_turno']); ?></div><?php endif; ?>
                <?php if (!empty($st['data_turno'])): ?><div><i class="fa fa-calendar-day text-secondary me-1 small"></i><?php echo date('d/m/Y', strtotime($st['data_turno'])); ?></div><?php endif; ?>
                <?php if (orario_turno($st) !== ''): ?><div class="small text-muted"><i class="fa fa-clock me-1"></i><?php echo orario_turno($st); ?></div><?php endif; ?>
            </td>
            <td class="text-center fw-bold"><?php echo $cap_label; ?></td>
            <td class="text-center">
                <span class="fw-bold text-success fs-6"><?php echo $st['confermati']; ?></span>
                <?php if (!$is_libero): ?>
                    <div class="text-muted small"><?php echo $fill_perc; ?>%</div>
                    <?php echo $fill_bar; ?>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <span class="fw-bold text-primary fs-6"><?php echo $st['presenti']; ?></span>
                <?php if ($st['confermati'] > 0): ?>
                    <div class="text-muted small"><?php echo $pres_perc; ?>%</div>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <?php if ($st['annullate'] > 0): ?>
                    <span class="fw-bold text-danger fs-6"><?php echo $st['annullate']; ?></span>
                <?php else: ?>
                    <span class="text-muted opacity-50">0</span>
                <?php endif; ?>
            </td>
            <td class="text-center" style="min-width:90px;">
                <?php if (!$is_libero): ?>
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1 progress-sm">
                            <?php $bar_col2 = $fill_perc >= 90 ? 'bg-danger' : ($fill_perc >= 60 ? 'bg-warning' : 'bg-success'); ?>
                            <div class="progress-bar <?php echo $bar_col2; ?>" style="width:<?php echo $fill_perc; ?>%"></div>
                        </div>
                        <span class="small fw-bold text-dark"><?php echo $fill_perc; ?>%</span>
                    </div>
                <?php else: ?>
                    <span class="badge bg-secondary">Libero</span>
                <?php endif; ?>
            </td>
            <td class="text-center fw-bold text-warning fs-6">
                <?php echo $st['attesa'] > 0 ? $st['attesa'] : '<span class="text-muted opacity-50">0</span>'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
};
?>

<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-white py-0">
        <ul class="nav nav-tabs card-header-tabs pt-2" id="tabTurni" role="tablist">
            <li class="nav-item">
                <button class="nav-link fw-bold active" data-bs-toggle="tab" data-bs-target="#tab-futuri" type="button">
                    <i class="fa fa-calendar-check text-success me-1"></i>
                    Live / Prossimi
                    <span class="badge bg-success ms-1"><?php echo count($turni_futuri); ?></span>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" data-bs-toggle="tab" data-bs-target="#tab-passati" type="button">
                    <i class="fa fa-history text-secondary me-1"></i>
                    Storico
                    <span class="badge bg-secondary ms-1"><?php echo count($turni_passati); ?></span>
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body p-0">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-futuri">
                <?php $render_table($turni_futuri, 'Live / Prossimi'); ?>
            </div>
            <div class="tab-pane fade" id="tab-passati">
                <?php $render_table($turni_passati, 'Storico'); ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {

    // Grafico 1: Doughnut ripartizione
    new Chart(document.getElementById('chartStati').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Confermati', 'Presenti', 'In Attesa', 'Annullate', 'Persi/Scaduti'],
            datasets: [{
                data: [<?php echo $kpi['confermate']; ?>, <?php echo $kpi['presenti']; ?>, <?php echo $kpi['attesa']; ?>, <?php echo $kpi['annullate']; ?>, <?php echo $kpi['perse']; ?>],
                backgroundColor: ['#198754', '#0d6efd', '#ffc107', '#dc3545', '#6c757d'],
                borderWidth: 2, hoverOffset: 4
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } } }
    });

    // Grafico 2: Presenti vs Assenti stacked per evento
    <?php if (!empty($ev_labels_pres)): ?>
    new Chart(document.getElementById('chartPresenze').getContext('2d'), {
        type: 'bar',
        data: {
            labels: [<?php echo implode(',', $ev_labels_pres); ?>],
            datasets: [
                { label: 'Presenti',   data: [<?php echo implode(',', $ev_presenti); ?>], backgroundColor: '#0d6efd', borderRadius: 4 },
                { label: 'Assenti',    data: [<?php echo implode(',', $ev_assenti); ?>],  backgroundColor: '#e2e8f0', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } },
            plugins: { legend: { position: 'top' } }
        }
    });
    <?php else: ?>
    document.getElementById('chartPresenze').parentElement.innerHTML =
        '<p class="text-muted text-center py-5">Nessuna presenza registrata.</p>';
    <?php endif; ?>

    // Grafico 3: Trend iscrizioni line
    <?php if (!empty($trend_labels)): ?>
    new Chart(document.getElementById('chartTrend').getContext('2d'), {
        type: 'line',
        data: {
            labels: [<?php echo implode(',', $trend_labels); ?>],
            datasets: [{
                label: 'Nuove iscrizioni',
                data: [<?php echo implode(',', $trend_counts); ?>],
                borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.08)',
                fill: true, tension: 0.3, pointRadius: 4, pointHoverRadius: 6,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });
    <?php else: ?>
    document.getElementById('chartTrend').parentElement.innerHTML =
        '<p class="text-muted text-center py-4">Nessuna iscrizione negli ultimi 30 giorni.</p>';
    <?php endif; ?>

});
</script>

<?php require_once 'admin_footer.php'; ?>
