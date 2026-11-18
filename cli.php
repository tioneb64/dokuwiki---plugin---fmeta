<?php

use dokuwiki\Extension\CLIPlugin;
use splitbrain\phpcli\Options;

/** php bin/plugin.php fmeta reindex */
class cli_plugin_fmeta extends CLIPlugin
{
    /** @inheritdoc */
    protected function setup(Options $options)
    {
        $options->setHelp('Reconstruit l\'index des en-têtes de fiches.');
        $options->registerCommand('reindex', 'Relit toutes les pages et reconstruit l\'index');
    }

    /** @inheritdoc */
    protected function main(Options $options)
    {
        /** @var helper_plugin_fmeta $helper */
        $helper = plugin_load('helper', 'fmeta');
        $this->success(count($helper->rebuild()) . ' fiches indexées');
    }
}
