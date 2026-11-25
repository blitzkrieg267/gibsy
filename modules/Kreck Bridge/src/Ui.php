<?php
/**
 * Kreck Bridge — small UI helpers shared by the module's hand-written pages.
 *
 * KreckUi::assets() returns the searchable-picker CSS + JS (js/kreck-picker.js,
 * css/kreck-picker.css) as one inline block, once per request. Inlining avoids
 * asset-URL problems (the module folder name contains a space) and works from
 * raw-HTML pages, dashboard panels and AJAX fragments alike.
 *
 * KreckUi::select() renders a <select data-kpicker> from a value=>label list
 * (labels may also be [label, meta]) so pages stop hand-writing <option> loops.
 */
class KreckUi
{
    private static $emitted = false;

    /** The picker assets, emitted only the first time it is called in a request. */
    public static function assets(): string
    {
        if (self::$emitted) {
            return '';
        }
        self::$emitted = true;
        $base = dirname(__DIR__);
        $css = @file_get_contents($base . '/css/kreck-picker.css') ?: '';
        $js = @file_get_contents($base . '/js/kreck-picker.js') ?: '';
        // A literal "</script" inside the JS would end the block early; none exists, but be safe.
        $js = str_ireplace('</script', '<\/script', $js);
        return "<style id=\"kreck-picker-css\">\n" . $css . "\n</style>\n<script id=\"kreck-picker-js\">\n" . $js . "\n</script>\n";
    }

    /**
     * Render a searchable <select>.
     *
     * @param string       $name     field name (use "tags[]" with $opt['multiple'])
     * @param array        $options  value => label  |  value => [label, meta]
     * @param string|array $selected selected value (or values when multiple)
     * @param array        $opt      id, required, multiple, create, placeholder, message, class, onchange, blank (label for an empty option)
     */
    public static function select(string $name, array $options, $selected = '', array $opt = []): string
    {
        $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $multiple = !empty($opt['multiple']);
        $sel = is_array($selected) ? array_map('strval', $selected) : [(string) $selected];

        $attrs = ' name="' . $esc($name) . '"';
        if (!empty($opt['id'])) { $attrs .= ' id="' . $esc($opt['id']) . '"'; }
        if (!empty($opt['class'])) { $attrs .= ' class="' . $esc($opt['class']) . '"'; }
        if ($multiple) { $attrs .= ' multiple'; }
        if (!empty($opt['required'])) { $attrs .= ' required'; }
        $attrs .= ' data-kpicker';
        if (!empty($opt['create'])) { $attrs .= ' data-kpicker-create'; }
        if (!empty($opt['placeholder'])) { $attrs .= ' data-placeholder="' . $esc($opt['placeholder']) . '"'; }
        if (!empty($opt['message'])) { $attrs .= ' data-kp-message="' . $esc($opt['message']) . '"'; }
        if (!empty($opt['onchange'])) { $attrs .= ' onchange="' . $esc($opt['onchange']) . '"'; }

        $html = '<select' . $attrs . '>';
        if (!$multiple && array_key_exists('blank', $opt)) {
            $html .= '<option value="">' . $esc($opt['blank']) . '</option>';
        }
        foreach ($options as $value => $label) {
            $meta = '';
            if (is_array($label)) { $meta = (string) ($label[1] ?? ''); $label = (string) ($label[0] ?? ''); }
            $isSel = in_array((string) $value, $sel, true) ? ' selected' : '';
            $html .= '<option value="' . $esc($value) . '"' . $isSel
                . ($meta !== '' ? ' data-meta="' . $esc($meta) . '"' : '') . '>' . $esc($label) . '</option>';
        }
        return $html . '</select>';
    }
}
