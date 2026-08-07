<?php

/**
 * Copia las fuentes incluidas en DomPDF a storage/fonts para que el
 * informe PDF las encuentre. Se ejecuta en post-autoload-dump.
 *
 * Va en un archivo (no inline en composer.json) porque el one-liner
 * `@php -r "... $f ..."` rompe en shells tipo bash: el `$f` lo expande
 * el shell antes de llegar a PHP y genera un parse error.
 */

@mkdir('storage/fonts', 0775, true);

foreach (glob('vendor/dompdf/dompdf/lib/fonts/*') as $font) {
    copy($font, 'storage/fonts/' . basename($font));
}

@copy('storage/fonts/installed-fonts.dist.json', 'storage/fonts/installed-fonts.json');

echo 'DomPDF fonts copied to storage/fonts/' . PHP_EOL;
