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
$kpi_confermate = 0;
$kpi_attesa = 0;
$kpi_scadute_rifiutate = 0;
$kpi_capienza_totale = 0;

$sql_kpi = "SELECT 
              SUM(CASE WHEN p.stato IN ('confermata', 'richiesta_conferma') THEN p.num_posti ELSE 0 END) as tot_confermate,
              SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END) as tot_attesa,
              SUM(CASE WHEN p.stato IN ('scaduta', 'rifiutata') THEN p.num_posti ELSE 0 END) as tot_perse
            FROM prenotazioni p
            JOIN turni t ON p.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            JOIN pagine_eventi pe ON e.pagina_id = pe.id
            WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac";
            
$res_kpi = $conn->query($sql_kpi);
if ($res_kpi && $row = $res_kpi->fetch_assoc()) {
    $kpi_confermate = (int)$row['tot_confermate'];
    $kpi_attesa = (int)$row['tot_attesa'];
    $kpi_scadute_rifiutate = (int)$row['tot_perse'];
}

$sql_capienza = "SELECT SUM(t.max_posti) as capienza_max 
                 FROM turni t 
                 JOIN eventi e ON t.evento_id = e.id 
                 JOIN pagine_eventi pe ON e.pagina_id = pe.id
                 WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac AND t.max_posti < 9000";
$res_cap = $conn->query($sql_capienza);
if ($res_cap && $row_cap = $res_cap->fetch_assoc()) {
    $kpi_capienza_totale = (int)$row_cap['capienza_max'];
}

// ============================================================================
// 3. DATI PER I GRAFICI E PER LA TABELLA DETTAGLIATA
// ============================================================================
$nomi_eventi = [];
$posti_occupati_eventi = [];
$capienza_eventi = [];
$stats_turni = []; 

$sql_grafico_eventi = "SELECT e.id, e.titolo, 
                       COALESCE((SELECT SUM(max_posti) FROM turni WHERE evento_id = e.id AND max_posti < 9000), 0) as cap_max
                       FROM eventi e 
                       JOIN pagine_eventi pe ON e.pagina_id = pe.id
                       WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
                       ORDER BY e.id ASC";
$res_ge = $conn->query($sql_grafico_eventi);
if ($res_ge) {
    while ($ev = $res_ge->fetch_assoc()) {
        $ev_id = $ev['id'];
        $sql_occ = "SELECT COALESCE(SUM(p.num_posti), 0) as occupati 
                    FROM prenotazioni p JOIN turni t ON p.turno_id = t.id 
                    WHERE t.evento_id = $ev_id AND p.stato IN ('confermata', 'richiesta_conferma')";
        $occ = $conn->query($sql_occ)->fetch_assoc()['occupati'];
        
        $titolo_corto = mb_strlen($ev['titolo']) > 25 ? mb_substr($ev['titolo'], 0, 22) . '...' : $ev['titolo'];
        
        if ($occ > 0 || $ev['cap_max'] > 0) {
            $nomi_eventi[] = '"' . addslashes($titolo_corto) . '"';
            $posti_occupati_eventi[] = $occ;
            $capienza_eventi[] = $ev['cap_max'];
        }
    }
}

$sql_turni = "SELECT 
                e.titolo as evento_titolo, 
                t.id as turno_id,
                t.data_turno, 
                t.orario_inizio, 
                t.orario_fine,
                t.max_posti,
                COALESCE(SUM(CASE WHEN p.stato IN ('confermata', 'richiesta_conferma') THEN p.num_posti ELSE 0 END), 0) as confermati,
                COALESCE(SUM(CASE WHEN p.stato = 'in_attesa' THEN p.num_posti ELSE 0 END), 0) as attesa
              FROM turni t
              JOIN eventi e ON t.evento_id = e.id
              JOIN pagine_eventi pe ON e.pagina_id = pe.id
              LEFT JOIN prenotazioni p ON p.turno_id = t.id
              WHERE e.pagina_id = $filtro_p $sql_filtro_eventi_rbac
              GROUP BY t.id
              ORDER BY t.data_turno ASC, t.orario_inizio ASC";

$res_turni = $conn->query($sql_turni);
if ($res_turni) {
    while ($row = $res_turni->fetch_assoc()) {
        $stats_turni[] = $row;
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-chart-pie text-primary me-2"></i> Statistiche & Report - <?php echo htmlspecialchars($page_cfg['titolo'] ?? 'Area'); ?></h4>
    <div>
        <a href="?p_id=<?php echo $filtro_p; ?>&export_csv=1" class="btn btn-outline-secondary fw-bold shadow-sm me-2">
            <i class="fa fa-file-csv me-1"></i> Scarica CSV
        </a>
        <a href="?p_id=<?php echo $filtro_p; ?>&export_excel=1" class="btn btn-success fw-bold shadow-sm" style="background-color: #198754; border: none;">
            <i class="fa fa-file-excel me-1"></i> Esporta Excel
        </a>
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
