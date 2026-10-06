<?php

namespace justinholtweb\pigeon\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The control panel conversation view's styles, written against Craft's own CSS variables so they
 * follow the CP's theme instead of fighting it.
 */
class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->css = ['cp.css'];

        parent::init();
    }
}
