<?php

namespace TorqIT\DuplicateAssetCleanupBundle\Command;

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

        //This message is temporary and is just here to demonstrate that the query is working
        $this->output->writeln("Found asset with {$result["total"]} duplicates");

        $duplicateIds = $this->getDuplicateAssetsForHash($result["binaryFileHash"]);
        $baseAsset = $this->findFirstValidAsset($duplicateIds);

        $this->output->writeln("Selected {$baseAsset->getKey()} as the unified asset");

        $imageGalleryClasses = $this->getImageGalleryClasses();

        foreach($duplicateIds as $duplicateId)
        {
            if($duplicateId !== $baseAsset->getId())
            {
                foreach($imageGalleryClasses as $galleryClass)
                {   
                    $objects = $this->getObjectsThatReferenceAsset($duplicateId, $galleryClass["className"], $galleryClass["tableId"], $galleryClass["fields"]);
                    $count = count($objects);
                    
                    if($count > 0)
                    {
                        //This message is temporary and is just here to demonstrate that the query is working
                        $this->output->writeln("Found $count {$galleryClass["className"]} objects that reference asset ID $duplicateId");
                        $this->replaceAssetReferencesInImageGalleries($duplicateId, $baseAsset, $objects, $galleryClass["fields"]);
                    }
                }
            }
        }

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
     *  @param string[] $galleryFields 
     *  @return Concrete[]
    */
    private function getObjectsThatReferenceAsset(int $assetId, string $className, string $tableId, array $galleryFields)
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
}
