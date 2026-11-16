<?php
// robots.php - Indicazioni per i motori di ricerca (/robots.txt): il pannello e i file caricati non vanno indicizzati.
// Nella copia di prova (AMBIENTE=prova) non va indicizzato niente.
require_once 'config.php';
require_once 'functions.php';

while (ob_get_level()) ob_end_clean();
header('Content-Type: text/plain; charset=utf-8');
if (\App\Core\Ambiente::corrente()->inProva()) {
    header('Cache-Control: no-store');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
$base = rtrim(\App\Core\App::per($conn)->get(\App\Core\Sito::class)->urlBase(), '/');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\nDisallow: " . parse_url($base, PHP_URL_PATH) . "/admin/\nDisallow: " . parse_url($base, PHP_URL_PATH) . "/uploads/\n\nSitemap: $base/sitemap.xml\n";
