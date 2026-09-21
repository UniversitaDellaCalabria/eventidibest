<?php
// ricerca.php - Motore di Ricerca Globale Frontend
require_once 'config.php';
require_once 'functions.php';

// Disabilita il titolo dinamico dell'header
$page_cfg['titolo'] = "Ricerca Eventi";

require_once 'header.php';

// Recupero e sanificazione della query
$q_raw = trim($_GET['q'] ?? '');
$q_safe = htmlspecialchars($q_raw);
$q_like = '%' . $q_raw . '%';

$risultati = !empty($q_raw) ? cerca_eventi($conn, $q_raw) : [];
?>

<div class="container my-5">
    
    <!-- Intestazione Ricerca -->
    <div class="mb-5 border-bottom pb-4">
        <h1 class="fw-bold text-dark display-5" style="letter-spacing: -1px;">
            <i class="fa fa-search text-primary me-2"></i> Risultati della ricerca
        </h1>
        <?php if (!empty($q_raw)): ?>
            <p class="text-secondary fs-5 mt-2">
                Hai cercato: <strong>"<?php echo $q_safe; ?>"</strong>. 
                Trovati <strong><?php echo count($risultati); ?></strong> risultati.
            </p>
        <?php else: ?>
            <p class="text-secondary fs-5 mt-2">Inserisci una parola chiave per cercare un evento, un laboratorio o un'aula.</p>
            
            <form action="ricerca.php" method="GET" class="mt-4" style="max-width: 500px;">
                <div class="input-group input-group-lg shadow-sm">
                    <input type="text" name="q" class="form-control border-primary" placeholder="Cerca..." required>
                    <button class="btn btn-primary fw-bold px-4" type="submit">Cerca</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Risultati -->
    <?php if (!empty($q_raw) && empty($risultati)): ?>
        <div class="alert alert-light text-center border p-5 shadow-sm rounded-4">
            <i class="fa fa-search-minus fs-1 text-muted mb-3 d-block"></i>
            <h5 class="fw-bold text-dark">Nessun evento trovato</h5>
            <p class="text-secondary m-0">Non abbiamo trovato alcun evento o laboratorio che corrisponda a "<?php echo $q_safe; ?>". Prova con termini più generici.</p>
        </div>
    <?php elseif (!empty($risultati)): ?>
        
        <div class="row g-4">
            <?php foreach ($risultati as $ev): 
                $is_archived = ($ev['archiviato'] == 1);
                $col_area = $ev['colore_primario'] ?? '#0056b3';
                
                // Determinare il link corretto (Pagina Attiva o Archivio Storico)
                $link_destinazione = $is_archived ? ($ev['slug_area'] . '_archivio.php') : ($ev['slug_area'] . '.php');
            ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card shadow-sm border-0 h-100 bg-white" style="border-radius: 8px; overflow: hidden; <?php echo $is_archived ? 'filter: grayscale(40%);' : ''; ?>">
                        
                        <div style="height: 5px; background-color: <?php echo $col_area; ?>;"></div>
                        
                        <div class="card-body p-4 d-flex flex-column">
                            
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge" style="background-color: <?php echo $col_area; ?>;">
                                    <?php echo htmlspecialchars($ev['nome_area']); ?>
                                </span>
                                <?php if ($is_archived): ?>
                                    <span class="badge bg-secondary">Archiviato</span>
                                <?php endif; ?>
                            </div>
                            
                            <h5 class="fw-bold text-dark mt-2 mb-3"><?php echo htmlspecialchars($ev['titolo']); ?></h5>
                            
                            <?php if (!empty($ev['luogo'])): ?>
                                <small class="text-muted fw-bold d-block mb-3"><i class="fa fa-map-marker-alt me-1 text-danger"></i> <?php echo htmlspecialchars($ev['luogo']); ?></small>
                            <?php endif; ?>

                            <div class="text-secondary mb-4 flex-grow-1" style="font-size: 0.9rem; line-height: 1.5;">
                                <?php echo mb_strimwidth(strip_tags($ev['descrizione']), 0, 120, '...'); ?>
                            </div>
                            
                            <div class="mt-auto">
                                <a href="<?php echo htmlspecialchars($link_destinazione); ?>" class="btn btn-outline-primary btn-sm fw-bold w-100" style="border-color: <?php echo $col_area; ?>; color: <?php echo $col_area; ?>;">
                                    Vai alla pagina <i class="fa fa-arrow-right ms-1"></i>
                                </a>
                            </div>
                        </div>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<?php require_once 'footer.php'; ?>