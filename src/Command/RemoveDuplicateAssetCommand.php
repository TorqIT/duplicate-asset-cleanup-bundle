<?php

namespace TorqIT\DuplicateAssetCleanupBundle\Command;

use Pimcore\Console\AbstractCommand;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveDuplicateAssetCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('torq:cleanup:most-duplicated-asset')
            ->setDescription('Removes all duplicates for whichever asset has the most duplicates and updates all references' .
        ' to that asset to point to the new unified asset.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO query to see how many duplicate files before asking?
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('This command cannot be undone. Are you sure you want to continue? [Yes | No]: ', false, '/^Yes|Y/i');

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln("Command cancelled");
            return 0;
        }

        $result = $this->getAssetIdWithMostDuplicates();

        $this->output->writeln("Removing {$result["total"]} duplicates for asset ID: {$result["targetAssetId"]}");

        return 0;
    }

    private function getAssetIdWithMostDuplicates()
    {
        $subQuery = Db::get()->createQueryBuilder()
            ->select("cid", "MAX(versionCount) as version")
            ->from("versions")
            ->where("binaryFileId IS NULL AND ctype = 'asset'")
            ->groupBy("cid");

        $results = Db::get()->createQueryBuilder()
            ->select("MIN(groupedVersions.cid) as targetAssetId", "COUNT(1) AS total")
            ->from("versions", "groupedVersions")
            ->innerJoin("groupedVersions", "({$subQuery->getSQL()})", "maxVersion", 
                "groupedVersions.cid = maxVersion.cid AND groupedVersions.versionCount = maxVersion.version")
            ->groupBy("groupedVersions.binaryFileHash")
            ->orderBy("total", "DESC")
            ->setMaxResults(1)
            ->execute();

        return $results->fetchAssociative();
    }
}
