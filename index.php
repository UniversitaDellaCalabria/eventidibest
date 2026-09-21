<?php
$page_title = "EventiDiBEST - Portale Eventi e Laboratori Dipartimentali";
require_once 'header.php';

$pagine = get_pagine_eventi_visibili($conn);
?>

<style>
    .card-portal { background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; transition: transform 0.25s ease, box-shadow 0.25s ease; height: 100%; overflow: hidden; display: flex; flex-direction: column; }
    .card-portal:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.08); }
    .card-portal-header { padding: 25px; color: white; }
    .card-portal-body { padding: 25px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
    .btn-esplora { font-weight: 800; text-transform: uppercase; font-size: 0.85rem; padding: 12px 24px; border-radius: 8px; border: none; text-decoration: none; display: inline-block; text-align: center; }
</style>

<!-- CONTENUTO PRINCIPALE HOME -->
<div class="container my-5" style="max-width: 1200px;">
    <div class="row g-4 justify-content-center">
        <?php if (empty($pagine)): ?>
            <div class="col-12 text-center p-5">
                <i class="fa fa-calendar-times text-muted display-1 mb-3"></i>
                <h4 class="text-secondary fw-bold">Nessun evento o area di lavoro attualmente disponibile.</h4>
            </div>
        <?php else: ?>
            <?php foreach ($pagine as $p): ?>
                <!-- col-lg-6 ripristina la disposizione affiancata a 2 card per riga -->
                <div class="col-md-6 col-lg-6 d-flex align-items-stretch">
                    <div class="card-portal w-100">
                        <div class="card-portal-header" style="background: linear-gradient(135deg, <?php echo htmlspecialchars($p['colore_primario']); ?> 0%, <?php echo htmlspecialchars($p['colore_secondario']); ?> 100%);">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-white text-dark fw-bold text-uppercase"><?php echo htmlspecialchars($p['sidebar_intervallo_date'] ? $p['sidebar_intervallo_date'] : 'Aperto'); ?></span>
                                <i class="fa fa-calendar-check fs-4 text-white-50"></i>
                            </div>
                            <h2 class="fw-bold mt-3 mb-0"><?php echo htmlspecialchars($p['titolo'] . ' ' . ($p['sottotitolo'] ?? '')); ?></h2>
                        </div>
                        <div class="card-portal-body">
                            <div class="mb-4">
                                <p class="text-secondary m-0" style="display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden;">
                                    <?php echo strip_tags($p['hero_descrizione'] ?? ''); ?>
                                </p>
                            </div>
                            <div>
                                <a href="<?php echo htmlspecialchars($p['slug']); ?>.php" class="btn-esplora w-100 text-white shadow-sm" style="background-color: <?php echo htmlspecialchars($p['colore_primario']); ?>;">
                                    Esplora Programma e Prenota <i class="fa fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
