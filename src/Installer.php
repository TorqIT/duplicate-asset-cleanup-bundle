<?php

namespace TorqIT\DuplicateAssetCleanupBundle;

//use Doctrine\DBAL\Migrations\Version;
//use Doctrine\DBAL\Schema\Schema;
use Pimcore\Db;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;

class Installer extends SettingsStoreAwareInstaller
{
    const INDEX_NAME = 'IX_versions_binaryFileHas_binaryFileId';

    public function install() : void
    {
        $this->safelyRemoveIndex();

        $proc = <<<EOT
        ALTER TABLE `Versions` ADD INDEX `?` (`binaryFileHash`, `binaryFileId`);
        EOT;

        Db::get()->query($proc, self::INDEX_NAME);

        parent::install();
    }

    public function uninstall() : void
    {
        $this->safelyRemoveIndex();

        parent::uninstall();
    }

    private function safelyRemoveIndex()
    {
        $indexCount = Db::get()->query('SHOW INDEX FROM `versions` WHERE `Key_name` = ?', self::INDEX_NAME);

        if ($indexCount->rowCount() > 0) {
            Db::get()->query('DROP INDEX ? ON `Versions`', self::INDEX_NAME);
        }
    }

}
