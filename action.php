<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Fmeta : préparation du texte avant l'analyse, tenue de l'index à l'enregistrement,
 * et page de liste (?do=fmlist&field=tag&value=ia).
 */
class action_plugin_fmeta extends ActionPlugin
{
    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('PARSER_WIKITEXT_PREPROCESS', 'BEFORE', $this, 'preprocess');
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, 'onWrite');
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'actPreprocess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'tplUnknown');
    }

    /** Le texte analysé : l'en-tête est balisé pour que la syntaxe l'affiche */
    public function preprocess(Event $event)
    {
        /** @var helper_plugin_fmeta $helper */
        $helper = plugin_load('helper', 'fmeta');
        $event->data = $helper->mark($event->data);
    }

    /** À l'enregistrement : ajoute le --- d'ouverture manquant et met l'index à jour */
    public function onWrite(Event $event)
    {
        // seules les versions courantes comptent, pas les anciennes révisions
        if (!empty($event->data[3])) return;

        /** @var helper_plugin_fmeta $helper */
        $helper = plugin_load('helper', 'fmeta');
        [$ns, $name] = [$event->data[1], $event->data[2]];
        $id = ($ns ? $ns . ':' : '') . $name;

        $text = $event->data[0][1];
        // date de dernière modification, sauf pour les enregistrements automatiques (case à cocher...)
        // et la suppression d'une page (texte vide)
        if (trim($text) !== '' && empty($GLOBALS['fmeta_keep_updated'])) {
            $touched = $helper->touchUpdated($text);
            if ($touched !== $text) {
                $text = $touched;
                $event->data[0][1] = $text;
            }
        }
        $block = $helper->split($text);
        if ($block !== null && !$block['open']) {
            $text = "---\n" . $text;
            $event->data[0][1] = $text;
        }
        $helper->indexPage($id, $text);
    }

    /** Les pages ?do=fmlist restent à traiter par TPL_ACT_UNKNOWN */
    public function actPreprocess(Event $event)
    {
        if ($event->data === 'save') {
            global $ID, $PRE, $TEXT, $SUF;
            /** @var helper_plugin_fmeta $helper */
            $helper = plugin_load('helper', 'fmeta');
            if ($helper->ownerMissing((string)$ID, (string)con($PRE, $TEXT, $SUF, true))) {
                msg($helper->getLang('owner_missing'), -1);
                $event->data = 'preview';      // le texte saisi est conservé dans l'éditeur
            }
            return;
        }
        if ($event->data === 'fmlist') {
            $event->preventDefault();
            $event->stopPropagation();
        }
    }

    /** Affichage de la liste des fiches */
    public function tplUnknown(Event $event)
    {
        if ($event->data !== 'fmlist') return;
        $event->preventDefault();

        global $INPUT;
        /** @var helper_plugin_fmeta $helper */
        $helper = plugin_load('helper', 'fmeta');

        $field = preg_replace('/[^\p{L}\p{N}_-]/u', '', $INPUT->str('field', 'tag'));
        $value = $INPUT->str('value');
        $rows = $helper->query([$field => $value]);

        $label = $helper->getLang('f_' . $field) ?: ucfirst($field);
        $shown = in_array($field, ['created', 'updated', 'revue', 'expire'], true) ? $helper->dateLabel($value) : $value;
        if ($field === 'tag') $shown = '#' . $shown;

        echo '<h1>' . hsc(sprintf($helper->getLang('list_title'), $label, $shown)) . '</h1>';
        echo '<p>' . hsc(sprintf($helper->getLang('list_count'), count($rows))) . '</p>';
        echo $helper->table($rows, true);
    }
}
