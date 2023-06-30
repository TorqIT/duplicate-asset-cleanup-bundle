<?php

namespace TorqIT\DuplicateAssetCleanupBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;

class TorqITDuplicateAssetCleanupBundle extends AbstractPimcoreBundle
{
    public function getJsPaths()
    {
        return [
            '/bundles/torqitduplicateassetcleanup/js/pimcore/startup.js'
        ];
    }
}