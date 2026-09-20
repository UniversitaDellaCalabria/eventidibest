<?php
// master_archivio.php - Motore Frontend per l'Archivio Storico (Raggruppato per Anno)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php';
require_once 'functions.php';
sync_sso_user($conn);

// FIX ANTI-ERRORE: Rimuoviamo forzatamente "_archivio" dallo slug, sia che provenga dalla variabile, sia dal nome del file
$current_filename = $page_slug ?? basename($_SERVER['PHP_SELF'], '.php');
$current_filename = str_replace('_archivio', '', $current_filename);

// Recupero configurazione area
$stmt_p = $conn->prepare("SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1");
$stmt_p->bind_param("s", $current_filename);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
$page_cfg = ($res_p && $res_p->num_rows > 0) ? $res_p->fetch_assoc() : [];
$p_id = (int)($page_cfg['id'] ?? 0);

if ($p_id === 0) { die("Errore: Area di lavoro non trovata nel database. Assicurati che lo slug sia corretto."); }

$col_primaria = $page_cfg['colore_primario'] ?? '#990000';

// RECUPERO EVENTI ARCHIVIATI RAGGRUPPATI PER ANNO
$sql_arch = "SELECT e.*, sc.nome as nome_sottocategoria, 
            (SELECT YEAR(MIN(data_turno)) FROM turni WHERE evento_id = e.id) as anno_evento
            FROM eventi e 
            LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id 
            WHERE e.pagina_id = $p_id AND e.archiviato = 1 
            ORDER BY anno_evento DESC, sc.ordine ASC, e.ordine ASC";

$res_arch = $conn->query($sql_arch);
$eventi_per_anno = [];

if ($res_arch) {
    while($row = $res_arch->fetch_assoc()) {
        $anno = $row['anno_evento'] ?: 'Anno Sconosciuto';
        $eventi_per_anno[$anno][] = $row;
    }
}

// Inclusione Header Frontend
require_once 'header.php';
?>

<div class="container my-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
    
    <!-- Hero Banner Archivio -->
    <div class="text-center mb-5 p-4 rounded-4 shadow-sm" style="background-color: #f8f9fa; border-top: 5px solid #6c757d;">
        <h1 class="fw-black display-5 m-0 mb-2 text-dark" style="font-weight: 900; letter-spacing: -1px;">
            <i class="fa fa-history text-secondary me-2"></i> ARCHIVIO STORICO
        </h1>
        <h3 class="fw-bold fs-4 mb-3" style="color: <?php echo $col_primaria; ?>;">
            <?php echo htmlspecialchars($page_cfg['titolo']); ?>
        </h3>
        <p class="text-secondary mx-auto" style="max-width: 700px; font-size: 1.1rem;">
            Esplora le edizioni passate dei nostri eventi. Consulta i programmi storici e i materiali delle iniziative concluse.
        </p>
    </div>

    <?php if (empty($eventi_per_anno)): ?>
        <div class="alert alert-light text-center border p-5 shadow-sm rounded-4">
            <i class="fa fa-folder-open fs-1 text-muted mb-3 d-block"></i>
            <h5 class="fw-bold text-dark">Nessun evento in archivio</h5>
            <p class="text-secondary m-0">Questa sezione si popolerà automaticamente non appena verranno conclusi e archiviati i primi eventi.</p>
        </div>
    <?php else: ?>
        
        <!-- Raggruppamento per Anno -->
        <?php foreach ($eventi_per_anno as $anno => $eventi_anno): ?>
            
            <div class="mb-5">
                <div class="d-flex align-items-center mb-4">
                    <h2 class="fw-bold m-0 pe-3 text-dark" style="font-size: 2.5rem; letter-spacing: -1px;"><?php echo $anno; ?></h2>
                    <div class="flex-grow-1" style="height: 3px; background-color: <?php echo $col_primaria; ?>; opacity: 0.2;"></div>
                </div>

                <div class="row g-4">
                    <?php foreach ($eventi_anno as $ev): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card shadow-sm border-0 h-100 bg-white" style="border-radius: 8px; overflow: hidden; filter: grayscale(10%); transition: filter 0.3s ease;">
                                
                                <?php if (!empty($ev['locandina_path'])): ?>
                                    <img src="<?php echo htmlspecialchars($ev['locandina_path']); ?>" class="card-img-top border-bottom opacity-75" alt="Locandina" style="max-height: 200px; object-fit: cover;">
                                <?php endif; ?>
                                
                                <div class="card-body p-4 d-flex flex-column">
                                    <div class="badge bg-secondary mb-2 align-self-start">CONCLUSO</div>
                                    <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($ev['titolo']); ?></h5>
                                    
                                    <?php if (!empty($ev['nome_sottocategoria'])): ?>
                                        <small class="text-muted fw-bold d-block mb-2"><i class="fa fa-folder-open me-1"></i> <?php echo htmlspecialchars($ev['nome_sottocategoria']); ?></small>
                                    <?php endif; ?>

                                    <div class="text-secondary mb-3 flex-grow-1" style="font-size: 0.85rem; line-height: 1.5;">
                                        <?php echo strip_tags($ev['descrizione']); ?>
                                    </div>
                                    
                                    <div class="d-flex justify-content-end align-items-center mt-auto border-top pt-3">
                                        <?php if (!empty($ev['allegato_pdf'])): ?>
                                            <a href="<?php echo htmlspecialchars($ev['allegato_pdf']); ?>" target="_blank" class="btn btn-outline-secondary btn-sm fw-bold">
                                                <i class="fa fa-file-pdf me-1"></i> Scarica Programma
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>

                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php require_once 'footer.php'; ?>