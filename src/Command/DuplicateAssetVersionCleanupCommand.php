<?php

namespace TorqIT\DuplicateAssetCleanupBundle\Command;

use Pimcore\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class DuplicateAssetVersionCleanupCommand extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('torq:cleanup:duplicate-asset-versions')
            ->setDescription('Point duplicate assets to the same version binary in the asset versions file structure, removing duplicates.');
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

        $output->writeln("Continuing cleanup command");

        $deletedIds = $this->findDuplicateAssetVersions($output);

        foreach($deletedIds as $duplicate) {
            // TODO update database FK first as it's safe if the operation fails afterwards

            // TODO Delete the redundant binary file
            $this->deleteVersionBinary($duplicate["id"], $output);
        }


        return 0;
    }

    private function findDuplicateAssetVersions(OutputInterface $output)
    {
        $db = \Pimcore\Db::get();

        $query = <<<EOQ
SELECT 
	id, minId
FROM versions v
left outer join (
	SELECT 
		min(id) minId, binaryFileHash
    from versions
    where binaryFileId is null
    group by binaryFileHash
) as vg
on vg.binaryFileHash = v.binaryFileHash
Where minId is not null 
	and minId <> id 
    and v.binaryFileId is null
EOQ;

        // TODO query to find ID of asset version and earliest version ID in versions table
        $statement = $db->prepare($query);
        $statement->execute();

        $result = $statement->executeQuery();

        $results = $result->fetchAllAssociative();

//        $cacheClearInput = new ArrayInput(array());
//        $cacheCommand = $this->getApplication()->find('cache:clear');
//        $cacheCommand->run($cacheClearInput, $output);

        return $results;
    }

    private function deleteVersionBinary(int $id, OutputInterface $output)
    {
        $output->writeln("Deleting verion binary ".$id.".bin");

        $deletedIdGroup = 10000 * floor($id / 10000);

        $versionFile = PIMCORE_PRIVATE_VAR . "/versions/object/g$deletedIdGroup/$id/$id.bin";

        //shell_exec("rm $versionFile");
        $output->writeln("rm $versionFile");
    }
}
