<?php

namespace App\Support;

/**
 * HtmlSanitizer — limpieza defensiva de HTML proveniente del usuario.
 *
 * Permite un set acotado de tags inline + bloque útiles para rich text
 * (mensajes, replies, contenido de inbox) y elimina cualquier atributo
 * que no esté en la whitelist. Strip total de event handlers (`on*`),
 * scripts, iframes, formularios, estilos inline y URLs javascript:.
 *
 * No depende de extensiones — usa DOMDocument + DOMXPath de PHP estándar.
 * Fallback a strip_tags si DOM no está disponible.
 */
final class HtmlSanitizer
{
    /** Tags que sobreviven (inline + bloque básico). */
    private const ALLOWED_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 'br', 'p', 'div', 'span',
        'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
    ];

    /** Atributos permitidos por tag. Cualquier otro se borra. */
    private const ALLOWED_ATTRS = [
        'a' => ['href', 'title', 'target', 'rel'],
    ];

    /** Protocolos válidos en href. */
    private const SAFE_PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

    /**
     * Limpia $html y devuelve una versión segura para `v-html`.
     * Si la entrada es null/vacía devuelve ''.
     */
    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') return '';

        if (!class_exists(\DOMDocument::class)) {
            return strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
        }

        // Wrap en un root para que DOMDocument parsee correctamente fragmentos.
        $wrapped = '<?xml encoding="UTF-8"?><div id="__root__">' . $html . '</div>';

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        // LIBXML_HTML_NOIMPLIED: no agrega <html>/<body> wrappers.
        // LIBXML_HTML_NODEFDTD:  no agrega doctype.
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->getElementById('__root__');
        if (!$root) return '';

        self::sanitizeNode($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }

    /**
     * Walk recursivo. Si un elemento NO está en la whitelist lo desenvuelve
     * (saca el tag pero conserva los hijos); si está, sanitiza sus atributos.
     */
    private static function sanitizeNode(\DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);

                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Tag no permitido — mover hijos al padre y eliminar el elemento.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                self::sanitizeAttributes($child, $tag);
                self::sanitizeNode($child);
            } elseif ($child instanceof \DOMComment) {
                $node->removeChild($child);
            }
        }
    }

    private static function sanitizeAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];

        // Iterar sobre una copia: removeAttribute durante el foreach corrompe DOMNamedNodeMap.
        $attrNames = [];
        foreach ($el->attributes as $attr) {
            $attrNames[] = $attr->nodeName;
        }
        foreach ($attrNames as $name) {
            if (!in_array(strtolower($name), $allowed, true)) {
                $el->removeAttribute($name);
            }
        }

        // Si es <a href="...">, valida protocolo y fuerza rel="noopener noreferrer" si target="_blank".
        if ($tag === 'a') {
            $href = $el->getAttribute('href');
            if ($href !== '' && !self::isSafeUrl($href)) {
                $el->removeAttribute('href');
            }
            if ($el->getAttribute('target') === '_blank') {
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') return false;

        // URL relativa (sin protocolo) — permitida.
        if (!preg_match('#^([a-z][a-z0-9+\-.]*):#i', $url)) {
            return true;
        }

        $proto = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
        return in_array($proto, self::SAFE_PROTOCOLS, true);
    }
}
