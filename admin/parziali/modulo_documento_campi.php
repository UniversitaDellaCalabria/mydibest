<?php
// modulo_documento_campi.php - I campi di un modulo che sono comuni a un documento da scaricare e a un modulo online: titolo, categoria, tipo, descrizione, file e link.
// Li includono la scheda «Moduli e documenti» della Didattica (admin/didattica_moduli.php: tutti i campi) e la scheda «Modulistica» dell'Amministrazione
// (admin/amministrazione_modulistica.php, con $solo_documento = true: senza la scelta del tipo, perché là non ci sono moduli online). Variabili della pagina:
// $f (il modulo o i valori vuoti), $h (escape), $categorie (per i suggerimenti) e, facoltativo, $suggerimento_categoria (il testo di esempio nel campo
// Categoria: senza, quello della Didattica). Il markup è quello di sempre: la Didattica non cambia.
// Guardia: non si apre da solo. Lo includono solo le pagine del pannello, dopo admin_header.php (che definisce nega_accesso_pannello() e applica la mappa
// PermessiPagine). Le pagine che lo includono non hanno una costante propria (forms.php, pubblicazioni.php): si usa il segno che admin_header.php è passato.
if (!function_exists('nega_accesso_pannello')) { http_response_code(403); exit('Accesso negato.'); }
$solo_documento = $solo_documento ?? false;
$suggerimento_categoria = $suggerimento_categoria ?? 'es. Tirocini, Piani di studio';
?>
            <div data-parte="info" class="col-md-6"><label class="form-label small fw-bold" for="mTit">Titolo <span class="text-danger">*</span></label><input type="text" class="form-control" id="mTit" name="titolo" value="<?php echo $h($f['titolo']); ?>" required maxlength="200"></div>
            <div data-parte="info" class="<?php echo $solo_documento ? 'col-md-6' : 'col-md-3'; ?>"><label class="form-label small fw-bold" for="mCat">Categoria</label><input type="text" class="form-control" id="mCat" name="categoria" value="<?php echo $h($f['categoria']); ?>" list="mCatList" placeholder="<?php echo $h($suggerimento_categoria); ?>" maxlength="100">
                <datalist id="mCatList"><?php foreach ($categorie as $c): ?><option value="<?php echo $h($c); ?>"><?php endforeach; ?></datalist></div>
            <?php if (!$solo_documento): ?><div data-parte="info" class="col-md-3"><label class="form-label small fw-bold" for="mTipo">Tipo</label><select class="form-select" id="mTipo" name="tipo">
                <option value="documento"<?php echo $f['tipo'] === 'documento' ? ' selected' : ''; ?>>Documento da scaricare</option>
                <option value="online"<?php echo $f['tipo'] === 'online' ? ' selected' : ''; ?>>Modulo online (apre una pratica)</option></select></div><?php endif; ?>

            <div data-parte="info" class="col-12"><label class="form-label small fw-bold" for="mDes">Descrizione e istruzioni</label><textarea class="form-control editor-html" id="mDes" name="descrizione" rows="4"><?php echo $h($f['descrizione']); ?></textarea></div>
            <div data-parte="info" class="col-md-6 dd-doc"><label class="form-label small fw-bold" for="mFile">File da scaricare<?php echo $f['file_path'] ? ' (carica solo per sostituirlo)' : ''; ?></label><input type="file" class="form-control" id="mFile" name="file_modulo" accept=".pdf,.doc,.docx,.odt,.xls,.xlsx,.ods,.rtf">
                <?php if ($f['file_path']): ?><div class="form-text"><a href="../<?php echo $h($f['file_path']); ?>" target="_blank" rel="noopener">File attuale</a></div><?php endif; ?></div>
            <div data-parte="info" class="col-md-6"><label class="form-label small fw-bold" for="mLink">Link esterno (facoltativo)</label><input type="url" class="form-control" id="mLink" name="link" value="<?php echo $h($f['link']); ?>" placeholder="https://www.unical.it/..."></div>
