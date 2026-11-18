<?php

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Parsing\Handler;

/**
 * Rend l'en-tête YAML d'une page sous forme de fiche.
 *
 * action.php remplace les lignes --- de l'en-tête par <f> avant l'analyse ; le bloc
 * <f> ... <f> est alors reconnu ici.
 */
class syntax_plugin_fmeta extends SyntaxPlugin
{
    /** @inheritdoc */
    public function getType()
    {
        return 'substition';
    }

    /** @inheritdoc */
    public function getPType()
    {
        return 'block';
    }

    /** @inheritdoc */
    public function getSort()
    {
        return 1;
    }

    /** @inheritdoc */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<f>(?=\n).*?\n<f>(?=\n)', $mode, 'plugin_fmeta');
    }

    /** @inheritdoc */
    public function handle($match, $state, $pos, Handler $handler)
    {
        /** @var helper_plugin_fmeta $helper */
        $helper = $this->loadHelper('fmeta');
        $yaml = substr($match, 4, -4);
        return $helper->normalize($helper->parseYaml($yaml));
    }

    /** @inheritdoc */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format === 'xhtml') {
            /** @var helper_plugin_fmeta $helper */
            $helper = $this->loadHelper('fmeta');
            $renderer->doc .= $helper->card($data);
            return true;
        }
        if ($format === 'metadata') {
            // l'en-tête est visible des autres plugins dans les métadonnées de la page
            $renderer->meta['plugin']['fmeta'] = $data;
            return true;
        }
        return false;
    }
}
