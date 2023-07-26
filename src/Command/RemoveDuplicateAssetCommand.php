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

        $result = $this->getHashWithMostDuplicates();
        $duplicates = $this->getDuplicateAssetsForHash($result["binaryFileHash"]);

        $dupString = implode(", ", $duplicates);

        //This message is temporary and is just here to demonstrate that the query is working
        $this->output->writeln("Found asset with {$result["total"]} duplicates ($dupString)");

        return 0;
    }

    private function getHashWithMostDuplicates()
    {
        return Db::get()->createQueryBuilder()
            ->select("groupedVersions.binaryFileHash", "COUNT(1) AS total")
            ->from("versions", "groupedVersions")
            ->innerJoin("groupedVersions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion", 
                "groupedVersions.cid = maxVersion.cid AND groupedVersions.versionCount = maxVersion.version")
            ->groupBy("groupedVersions.binaryFileHash")
            ->orderBy("total", "DESC")
            ->setMaxResults(1)
            ->execute()
            ->fetchAssociative();
    }

    private function getDuplicateAssetsForHash(string $hash)
    {
        return Db::get()->createQueryBuilder()
            ->select("versions.cid")
            ->from("versions")
            ->innerJoin("versions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion",
                "versions.cid = maxVersion.cid AND versions.versionCount = maxVersion.version")
            ->where("binaryFileHash = ?")
            ->setParameter(0, $hash)
            ->execute()
            ->fetchFirstColumn();
    }

    //We can't just query the version table raw since a single asset with 30 versions will show
    // up 30 times. Instead, we filter our queries by inner joining to a subquery which fetches
    // the latest version of each asset (which will for sure have the latest binaryFileHash)
    private function buildMostRecenVersionSubquery()
    {
        return Db::get()->createQueryBuilder()
            ->select("cid", "MAX(versionCount) as version")
            ->from("versions")
            ->where("ctype = 'asset'")
            ->groupBy("cid")
            ->getSQL();
    }
}
