<?php
// admin/statistiche.php - Dashboard Avanzata e Reportistica
ob_start(); 
require_once 'admin_header.php';

if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-ban me-2'></i> Accesso negato alle statistiche.</div>";
    require_once 'admin_footer.php';
    exit;
}

// ============================================================================
// 1. ESPORTAZIONE EXCEL / CSV
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
                <th>Posti Prenotati</th><th>Data Prenotazione</th>
              </tr>";
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo chr(0xEF).chr(0xBB).chr(0xBF); 
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID Ticket', 'Stato', 'Evento', 'Turno (Data e Ora)', 'Nome', 'Cognome', 'Email', 'Matricola', 'Posti Prenotati', 'Data Prenotazione'], ';');
    }
    
    $sql_export = "SELECT p.*, e.titolo as evento_titolo, t.data_turno, t.orario_inizio 
                   FROM prenotazioni p 
                   JOIN turni t ON p.turno_id = t.id 
                   JOIN eventi e ON t.evento_id = e.id 
                   JOIN pagine_eventi pe ON e.pagina_id = pe.id
                   WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
                   ORDER BY t.data_turno ASC, t.orario_inizio ASC, p.data_prenotazione ASC";
                   
    $res_export = $conn->query($sql_export);
    if ($res_export) {
        while ($r = $res_export->fetch_assoc()) {
            $turno_str = date('d/m/Y', strtotime($r['data_turno'])) . " ore " . substr($r['orario_inizio'], 0, 5);
            $data_prenotazione = date('d/m/Y H:i', strtotime($r['data_prenotazione']));
            $stato_str = strtoupper($r['stato']);
            
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
                echo "<td>" . $data_prenotazione . "</td>";
                echo "</tr>";
            } else {
                fputcsv($output, [
                    $r['codice_prenotazione'], $stato_str, $r['evento_titolo'], $turno_str,
                    $r['nome'], $r['cognome'], $r['email'], $r['matricola'], 
                    $r['num_posti'], $data_prenotazione
                ], ';');
            }
        }
    }
    
    if ($is_excel) { echo "</table></body></html>"; } else { fclose($output); }
    exit; 
}

// ============================================================================
// 2. CALCOLO KPI (Dati Riassuntivi in Alto)
// ============================================================================
$kpi = get_kpi_statistiche($conn, $filtro_p, $sql_filtro_eventi_rbac);
$kpi_confermate        = $kpi['confermate'];
$kpi_attesa            = $kpi['attesa'];
$kpi_scadute_rifiutate = $kpi['perse'];
$kpi_capienza_totale   = $kpi['capienza'];

// ============================================================================
// 3. DATI PER I GRAFICI E PER LA TABELLA DETTAGLIATA
// ============================================================================
$grafico             = get_dati_grafico_eventi($conn, $filtro_p, $sql_filtro_eventi_rbac);
$nomi_eventi         = $grafico['nomi'];
$posti_occupati_eventi = $grafico['occupati'];
$capienza_eventi     = $grafico['capienza'];

$stats_turni = get_stats_turni($conn, $filtro_p, $sql_filtro_eventi_rbac);
?>

<style>
@media print {
    /* override: #wrapper ha classe no-print, lo rendiamo visibile */
    #wrapper { display: block !important; }
    #sidebar, #sidebarOverlay, .navbar, .btn, .no-print { display: none !important; }
    /* eccetto il content wrapper che deve restare visibile */
    #page-content-wrapper { display: block !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }
    body { font-size: 11px; }
    canvas { max-width: 100% !important; }
}
</style>

<div class="d-flex justify-content-between align-items-start align-items-md-center flex-wrap gap-3 mb-4">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-chart-pie text-primary me-2"></i> Statistiche & Report - <?php echo htmlspecialchars($page_cfg['titolo'] ?? 'Area'); ?></h4>
    <div class="d-flex flex-wrap gap-2">
        <a href="?p_id=<?php echo $filtro_p; ?>&export_csv=1" class="btn btn-outline-secondary fw-bold shadow-sm">
            <i class="fa fa-file-csv me-1"></i> Scarica CSV
        </a>
        <a href="?p_id=<?php echo $filtro_p; ?>&export_excel=1" class="btn btn-success fw-bold shadow-sm" style="background-color: #198754; border: none;">
            <i class="fa fa-file-excel me-1"></i> Esporta Excel
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary fw-bold shadow-sm">
            <i class="fa fa-print me-1"></i> Stampa / PDF
        </button>
    </div>
</div>

<!-- SCHEDE KPI -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-5 border-success h-100">
            <div class="card-body">
                <p class="text-muted fw-bold small text-uppercase mb-1">Posti Confermati</p>
                <h2 class="fw-bold text-success m-0"><?php echo $kpi_confermate; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-5 border-warning h-100">
            <div class="card-body">
                <p class="text-muted fw-bold small text-uppercase mb-1">In Lista d'Attesa</p>
                <h2 class="fw-bold text-warning m-0"><?php echo $kpi_attesa; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-5 border-secondary h-100">
            <div class="card-body">
                <p class="text-muted fw-bold small text-uppercase mb-1">Scadute / Annullate</p>
                <h2 class="fw-bold text-secondary m-0"><?php echo $kpi_scadute_rifiutate; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-5 border-primary h-100">
            <div class="card-body">
                <p class="text-muted fw-bold small text-uppercase mb-1">Capienza Totale Area</p>
                <h2 class="fw-bold text-primary m-0"><?php echo $kpi_capienza_totale; ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- GRAFICI -->
<div class="row g-4 mb-4">
    <!-- Grafico a Ciambella -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="fw-bold text-dark m-0"><i class="fa fa-chart-donut text-primary me-2"></i> Ripartizione Iscritti</h6>
            </div>
            <div class="card-body d-flex justify-content-center align-items-center">
                <div style="width: 100%; max-width: 300px;">
                    <canvas id="chartStati"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Grafico a Barre -->
    <div class="col-md-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="fw-bold text-dark m-0"><i class="fa fa-chart-column text-danger me-2"></i> Tasso di Riempimento per Evento</h6>
            </div>
            <div class="card-body">
                <canvas id="chartRiempimento" height="250"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- TABELLA DETTAGLIATA PER TURNO -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark m-0"><i class="fa fa-list text-success me-2"></i> Dettaglio per Singolo Evento e Turno</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0">
                <thead class="table-light">
                    <tr>
                        <th class="px-4 py-3">Evento</th>
                        <th>Data e Ora</th>
                        <th class="text-center">Capienza Max</th>
                        <th class="text-center text-success">Confermati</th>
                        <th class="text-center text-warning">In Attesa</th>
                        <th class="text-center text-primary">Posti Rimasti</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stats_turni)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Nessun turno trovato.</td></tr>
                    <?php else: ?>
                        <?php foreach ($stats_turni as $st): 
                            
                            $is_libero = $st['max_posti'] >= 9000; 
                            
                            if ($is_libero) {
                                $perc = 0;
                                $bar_color = 'bg-success';
                                $capienza_label = '<span class="badge bg-secondary">Ingresso Libero</span>';
                                $liberi_label = '<span class="badge bg-light text-dark fs-6 border">∞</span>';
                            } else {
                                $perc = ($st['max_posti'] > 0) ? round(($st['confermati'] / $st['max_posti']) * 100) : 0;
                                if($perc > 100) $perc = 100;
                                $bar_color = $perc >= 90 ? 'bg-danger' : ($perc >= 60 ? 'bg-warning' : 'bg-success');
                                $capienza_label = $st['max_posti'];
                                
                                $liberi = $st['max_posti'] - $st['confermati'];
                                if ($liberi < 0) $liberi = 0;
                                $liberi_label = $liberi == 0 ? '<span class="badge bg-danger">Esaurito</span>' : '<span class="badge bg-primary fs-6">'.$liberi.'</span>';
                            }
                        ?>
                        <tr>
                            <td class="px-4 py-3 fw-bold text-dark"><?php echo htmlspecialchars($st['evento_titolo']); ?></td>
                            <td>
                                <div><i class="fa fa-calendar-day text-secondary me-1"></i> <?php echo date('d/m/Y', strtotime($st['data_turno'])); ?></div>
                                <div class="small text-muted"><i class="fa fa-clock me-1"></i> <?php echo substr($st['orario_inizio'],0,5) . ' - ' . substr($st['orario_fine'],0,5); ?></div>
                            </td>
                            <td class="text-center fw-bold fs-5"><?php echo $capienza_label; ?></td>
                            <td class="text-center">
                                <span class="fw-bold text-success fs-5"><?php echo $st['confermati']; ?></span>
                                <?php if (!$is_libero): ?>
                                <div class="progress mt-1 mx-auto" style="height: 5px; width: 60%;">
                                    <div class="progress-bar <?php echo $bar_color; ?>" style="width: <?php echo $perc; ?>%;"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold text-warning fs-5">
                                <?php echo $st['attesa'] > 0 ? $st['attesa'] : '<span class="text-muted opacity-50">0</span>'; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $liberi_label; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Libreria Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    
    // --- GRAFICO 1: Ciambella ---
    const ctxStati = document.getElementById('chartStati').getContext('2d');
    new Chart(ctxStati, {
        type: 'doughnut',
        data: {
            labels: ['Confermati', 'In Attesa', 'Persi/Scaduti'],
            datasets: [{
                data: [<?php echo $kpi_confermate; ?>, <?php echo $kpi_attesa; ?>, <?php echo $kpi_scadute_rifiutate; ?>],
                backgroundColor: ['#198754', '#ffc107', '#6c757d'],
                borderWidth: 2,
                hoverOffset: 4
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // --- GRAFICO 2: Barre ---
    const ctxRiempimento = document.getElementById('chartRiempimento').getContext('2d');
    new Chart(ctxRiempimento, {
        type: 'bar',
        data: {
            labels: [<?php echo empty($nomi_eventi) ? '"Nessun dato"' : implode(',', $nomi_eventi); ?>],
            datasets: [
                { label: 'Posti Occupati', data: [<?php echo empty($posti_occupati_eventi) ? '0' : implode(',', $posti_occupati_eventi); ?>], backgroundColor: '#0d6efd', borderRadius: 4 },
                { label: 'Capienza Max', data: [<?php echo empty($capienza_eventi) ? '0' : implode(',', $capienza_eventi); ?>], backgroundColor: '#e2e8f0', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } },
            plugins: { legend: { position: 'top' } }
        }
    });
});
</script>

<?php require_once 'admin_footer.php'; ?>
