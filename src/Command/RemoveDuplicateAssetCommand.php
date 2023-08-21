<?php

namespace TorqIT\DuplicateAssetCleanupBundle\Command;

use Codeception\Lib\Console\Output;
use Exception;
use Pimcore;
use Pimcore\Console\AbstractCommand;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\DataObject\Listing;
use Pimcore\Model\Exception\NotFoundException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveDuplicateAssetCommand extends AbstractCommand
{
    private const ASSET_ID_OPTION = "asset-id";
    private const LIMIT_OPTION = "limit";

    protected function configure()
    {
        $this
            ->setName('torq:cleanup:duplicate-assets')
            ->setDescription('Removes all duplicates for whichever asset has the most duplicates and updates all references' .
                ' to that asset to point to the new unified asset.')
            ->addOption(self::ASSET_ID_OPTION, ["a", "i"], InputOption::VALUE_REQUIRED, "The ID of a specific asset that should have its duplicates removed." . 
                " (Note: given asset may not be selected as the base asset)")
            ->addOption(self::LIMIT_OPTION, "l", InputOption::VALUE_REQUIRED, "A numeric limit on how many duplicates should be deleted");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $targetAssetId = intval($input->getOption(self::ASSET_ID_OPTION));
        $removalLimit = intval($input->getOption(self::LIMIT_OPTION));

        // TODO query to see how many duplicate files before asking?
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('This command cannot be undone. Are you sure you want to continue? [Yes | No]: ', false, '/^Yes|Y/i');

        if (!$helper->ask($input, $output, $question)) {
            $output->writeln("Command cancelled");
            return 0;
        }

        $hash = $targetAssetId > 0 ? $this->getAssetHash($targetAssetId) : $this->getHashWithMostDuplicates();
        $duplicateIds = $this->getDuplicateAssetsForHash($hash);
        $duplicateCount = count($duplicateIds) - 1; //-1 to account for the base asset being on this list

        if($duplicateCount === 0)
        {
            $this->output->writeln($targetAssetId > 0 ? "Specified asset has no duplicates!" : "No duplicate assets detected!" );
            return 0;
        }

        $baseAsset = $this->findFirstValidAsset($duplicateIds);

        if($targetAssetId > 0)
        {
            $this->output->writeln("Specified asset has $duplicateCount duplicates. Using {$baseAsset->getKey()} as the unified asset.");
        }
        else 
        {
            $this->output->writeln("Found asset ({$baseAsset->getKey()}) with $duplicateCount duplicates");
        }

        $this->removeDuplicates($duplicateIds, $baseAsset, $removalLimit);

        return 0;
    }

    private function getAssetHash($targetAssetId)
    {
        return Db::get()->createQueryBuilder()
            ->select("groupedVersions.binaryFileHash")
            ->from("versions", "groupedVersions")
            ->innerJoin("groupedVersions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion", 
                "groupedVersions.cid = maxVersion.cid AND groupedVersions.versionCount = maxVersion.version")
            ->innerJoin("groupedVersions", "assets", "assets", "assets.id = groupedVersions.cid")
            ->where("groupedVersions.ctype = 'asset' AND groupedVersions.cid = ?")
            ->setParameter(0, $targetAssetId)
            ->execute()
            ->fetchOne();
    }

    private function getHashWithMostDuplicates()
    {
        return Db::get()->createQueryBuilder()
            ->select("groupedVersions.binaryFileHash")
            ->from("versions", "groupedVersions")
            ->innerJoin("groupedVersions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion", 
                "groupedVersions.cid = maxVersion.cid AND groupedVersions.versionCount = maxVersion.version")
            ->innerJoin("groupedVersions", "assets", "assets", "assets.id = groupedVersions.cid")
            ->where("groupedVersions.ctype = 'asset'")
            ->groupBy("groupedVersions.binaryFileHash")
            ->orderBy("COUNT(1)", "DESC")
            ->setMaxResults(1)
            ->execute()
            ->fetchOne();
    }

    /** @return int[] */
    private function getDuplicateAssetsForHash(string $hash)
    {
        return Db::get()->createQueryBuilder()
            ->select("versions.cid")
            ->from("versions")
            ->innerJoin("versions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion",
                "versions.cid = maxVersion.cid AND versions.versionCount = maxVersion.version")
            ->where("binaryFileHash = ?")
            ->orderBy("versions.cid")
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

    /** @param int[] $assetIds */
    private function findFirstValidAsset(array $assetIds)
    {
        foreach($assetIds as $assetId)
        {
            $asset = Asset::getById($assetId);

            if($asset->getData())
            {
                return $asset;
            }
        }

        throw new Exception("None of the duplicate assets have an associated file that actually exists");
    }

    private function getImageGalleryClasses()
    {
        $classDefinitions = (new ClassDefinition\Listing())->load();

        $imageGalleryFields = [];

        foreach($classDefinitions as $def)
        {
            $classGalleryFields = [];

            foreach($def->getFieldDefinitions() as $field)
            {
                if($field->getFieldtype() === "imageGallery")
                {
                    $classGalleryFields[] = $field->getName();
                }
            }

            if(!empty($classGalleryFields))
            {
                $imageGalleryFields[] = [
                    "className" => $def->getName(),
                    "tableId" => $def->getId(),
                    "fields" => $classGalleryFields
                ];
            }
        }

        return $imageGalleryFields;
    }

    /**
     * @param int[] $duplicateIds
     */
    private function removeDuplicates(array $duplicateIds, Asset $baseAsset, int $limit)
    {
        $imageGalleryClasses = $this->getImageGalleryClasses();
        
        $progressBar = null;

        if($this->output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE)
        {
            $progressBar = new ProgressBar($this->output, $limit > 0 ? min(count($duplicateIds) - 1, $limit) : count($duplicateIds) - 1);
        }

        $count = 0;

        foreach($duplicateIds as $duplicateId)
        {
            $this->output->writeln("Replacing all instances of asset $duplicateId", OutputInterface::VERBOSITY_VERBOSE);

            if($duplicateId != $baseAsset->getId())
            {
                $this->replaceAsset($duplicateId, $baseAsset, $imageGalleryClasses);
                $this->checkAssetDependencyAndDelete($duplicateId);
                $progressBar?->advance();
                $count++;
            }


            if($limit > 0 && $count >= $limit)
            {
                $this->output->writeln("");
                $this->output->writeln("The limit ($limit duplicates) has been reached. Stopping here.");
                return;
            }
        }
        
        $this->output->writeln("");
    }

    private function replaceAsset(int $oldAssetId, Asset $newAsset, array $imageGalleryClasses)
    {
        foreach($imageGalleryClasses as $galleryClass)
        {   
            $objects = $this->getObjectsThatReferenceAsset($oldAssetId, $galleryClass["className"], $galleryClass["tableId"]);
            $count = count($objects);
            
            if($count > 0)
            {
                $this->replaceAssetReferencesInImageGalleries($oldAssetId, $newAsset, $objects, $galleryClass["fields"]);
            }
        }
    }

    /**
     *  @param string[] $galleryFields 
     *  @return Concrete[]
    */
    private function getObjectsThatReferenceAsset(int $assetId, string $className, string $tableId)
    {
        $listingClass = "Pimcore\Model\DataObject\\$className\Listing";

        /** @var Listing */
        $listing = new $listingClass();

        $listing->onCreateQueryBuilder(
            function (\Doctrine\DBAL\Query\QueryBuilder $queryBuilder) use($tableId) {
                $queryBuilder->innerJoin("object_$tableId", 'dependencies', 'deps', 'oo_id = deps.sourceid');
            }
        );

        $listing->setCondition("deps.targetid = ? AND deps.targettype = 'asset' AND deps.sourcetype = 'object'", [$assetId]);

        return $listing->load();
    }

    /**
     *  @param Concrete[] $objects
     *  @param string[] $galleryFields
     */
    private function replaceAssetReferencesInImageGalleries(int $oldAssetId, Asset $newAsset, array $objects, array $galleryFields)
    {
        foreach($objects as $object)
        {
            foreach($galleryFields as $field)
            {
                /** @var ImageGallery */
                $imageGallery = $object->get($field);
                $assetIndex = $this->findAssetIndex($imageGallery, $oldAssetId);

                if($assetIndex !== null)
                {
                    $items = $imageGallery->getItems();
                    $items[$assetIndex] = new Hotspotimage($newAsset);
                    $imageGallery->setItems($items);
                }   
            }

            $object->save();
        }
    }

    private function findAssetIndex(ImageGallery $gallery, int $targetId)
    {
        foreach($gallery->getItems() as $index => $item)
        {
            if($item->getImage()->getId() === $targetId)
            {
                return $index;
            }
        }

        return null;
    }

    private function checkAssetDependencyAndDelete($assetId)
    {
        $asset = Asset::getById($assetId);

        $count = Db::get()->createQueryBuilder()
            ->select("COUNT(1)")
            ->from("dependencies", "deps")
            ->where("targettype = 'asset' AND targetid = ?")
            ->setParameter(0, $assetId)
            ->execute()
            ->fetchOne();

        if($count > 0)
        {
            $this->output->writeln("");
            $this->output->writeln("<comment>WARNING: {$asset->getKey()} still has $count dependencies and will not be deleted</comment>");
        }
        else
        {
            $asset->delete();
        }
    }
}
