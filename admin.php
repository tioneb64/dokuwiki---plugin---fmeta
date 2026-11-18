<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;

/** Reconstruction de l'index des fiches */
class admin_plugin_fmeta extends AdminPlugin
{
    protected $done = null;

    /** @inheritdoc */
    public function forAdminOnly()
    {
        return true;
    }

    /** @inheritdoc */
    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    /** @inheritdoc */
    public function handle()
    {
        global $INPUT;
        if ($INPUT->post->has('fmeta_rebuild') && checkSecurityToken()) {
            /** @var helper_plugin_fmeta $helper */
            $helper = plugin_load('helper', 'fmeta');
            $this->done = count($helper->rebuild());
        }
    }

    /** @inheritdoc */
    public function html()
    {
        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
        echo '<p>' . hsc($this->getLang('admin_intro')) . '</p>';
        if ($this->done !== null) {
            echo '<p><strong>' . hsc(sprintf($this->getLang('admin_done'), $this->done)) . '</strong></p>';
        }
        $form = new Form();
        $form->setHiddenField('fmeta_rebuild', '1');
        $form->addButton('go', $this->getLang('admin_button'))->attr('type', 'submit');
        echo $form->toHTML();
    }
}
