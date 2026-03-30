<?php declare(strict_types=1);

namespace TorqIT\DuplicateAssetCleanupBundle;

use Pimcore\Db;
use Pimcore\Extension\Bundle\Installer\SettingsStoreAwareInstaller;

class Installer extends SettingsStoreAwareInstaller
{
    private const INDEX_NAME = 'IX_versions_binaryFileHas_binaryFileId';

    public function install(): void
    {
        $this->safelyRemoveIndex();
        $this->createDatabaseIndex();

        parent::install();
    }

    public function uninstall(): void
    {
        $this->safelyRemoveIndex();

        parent::uninstall();
    }

    private function createDatabaseIndex(): void
    {
        $proc = <<<SQL
        ALTER TABLE `versions` ADD INDEX `IX_versions_binaryFileHas_binaryFileId` (`binaryFileHash`, `binaryFileId`);
        SQL;

        Db::get()->executeStatement($proc);
    }

    private function safelyRemoveIndex(): void
    {
        $indexCount = Db::get()->executeQuery(
            'SHOW INDEX FROM `versions` WHERE `Key_name` = ?',
            [self::INDEX_NAME]
        );

        if ($indexCount->rowCount() > 0) {
            Db::get()->executeStatement(
                'DROP INDEX `' . self::INDEX_NAME . '` ON `versions`'
            );
        }
    }
}
