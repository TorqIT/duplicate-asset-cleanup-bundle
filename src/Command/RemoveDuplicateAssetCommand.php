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
use Symfony\Component\Console\Helper\ProgressBar;
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

        if($result["total"] === 1)
        {
            $this->output->writeln("No duplicate assets detected!");
            return 0;
        }

        $duplicateIds = $this->getDuplicateAssetsForHash($result["binaryFileHash"]);
        $baseAsset = $this->findFirstValidAsset($duplicateIds);

        $this->output->writeln("Found asset ({$baseAsset->getKey()}) with {$result["total"]} duplicates");
        $imageGalleryClasses = $this->getImageGalleryClasses();

        $progressBar = null;

        if($output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE)
        {
            $progressBar = new ProgressBar($output, count($duplicateIds));
        }

        foreach($duplicateIds as $duplicateId)
        {
            $output->writeln("Replacing all instances of asset $duplicateId", OutputInterface::VERBOSITY_VERBOSE);

            if($duplicateId != $baseAsset->getId())
            {
                $this->replaceAsset($duplicateId, $baseAsset, $imageGalleryClasses);
                $this->checkAssetDependencyAndDelete($duplicateId);
            }

            $progressBar?->advance();
        }
        
        $output->writeln("");

        return 0;
    }

    private function getHashWithMostDuplicates()
    {
        return Db::get()->createQueryBuilder()
            ->select("groupedVersions.binaryFileHash", "COUNT(1) AS total")
            ->from("versions", "groupedVersions")
            ->innerJoin("groupedVersions", "({$this->buildMostRecenVersionSubquery()})", "maxVersion", 
                "groupedVersions.cid = maxVersion.cid AND groupedVersions.versionCount = maxVersion.version")
            ->innerJoin("groupedVersions", "assets", "assets", "assets.id = groupedVersions.cid")
            ->groupBy("groupedVersions.binaryFileHash")
            ->orderBy("total", "DESC")
            ->setMaxResults(1)
            ->execute()
            ->fetchAssociative();
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
