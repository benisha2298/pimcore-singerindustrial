<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;
use Pimcore\Model\DataObject\Classificationstore\KeyConfig;
use Pimcore\Model\DataObject\Classificationstore\GroupConfig;
use Pimcore\Model\DataObject\Classificationstore\CollectionConfig;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Logger;
use Pimcore\Log\ApplicationLogger;

class ClassificationStoreController extends AbstractController
{
    public function __construct(private ApplicationLogger $logger)
    {
    }

    /**
     * @Route("/api/import-classification-store", name="import_classification_store", methods={"POST"})
     */
    public function importClassificationStore(Request $request, ApplicationLogger $logger): JsonResponse
    {
        //$this->logger->info(" working inside ClassificationStoreController");
        // Check if the request contains a file
        // if (!$request->files->has('file')) {
        //     return $this->json(["status" => "failure", "message" => "No file uploaded"]);
        // }

        /** @var UploadedFile $file */
        $file = $request->files->get('product_schema_import');

        // Validate the file
        if (!$file) {
            return $this->json(["status" => "failure", "message" => "File not found in the request"]);
        }

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $spreadsheet = $reader->load($file->getPathname());
            $sheetData = $spreadsheet->getActiveSheet()->toArray();

            if (empty($sheetData) || !isset($sheetData[0])) {
                return $this->json(["status" => "failure", "message" => "Empty or invalid sheet"]);
            }

            // Filter and flip headers to create the $headers array
            $headers = array_filter($sheetData[0], function($value) {
                return !empty($value) && (is_string($value) || is_int($value));
            });
            $headers = array_flip($headers);

            // $requiredHeaders = ['Taxonomy L2', 'End Node', 'Attribute Name', 'Attribute Type'];

            // // Check if all required headers are present
            // foreach ($requiredHeaders as $requiredHeader) {
            //     if (!isset($headers[$requiredHeader])) {
            //         return $this->json(["status" => "failure", "message" => "Missing header: " . $requiredHeader]);
            //     }
            // }

            unset($sheetData[0]); // Remove the header row

            foreach ($sheetData as $rowIndex => $data) {
                // Ensure all required fields are present in the row
                // foreach ($requiredHeaders as $header) {
                //     if (!isset($data[$headers[$header]])) {
                //         return $this->json(["status" => "failure", "message" => "Missing data for: " . $header . " in row " . ($rowIndex + 2)]);
                //     }
                // }

                $groupCollectionName = htmlspecialchars($data[$headers['Taxonomy L2']], ENT_QUOTES, 'UTF-8');
                $groupName = htmlspecialchars($data[$headers['End Node']], ENT_QUOTES, 'UTF-8');
                $keyName = htmlspecialchars($data[$headers['Attribute Name']], ENT_QUOTES, 'UTF-8');
                $keyType = htmlspecialchars($data[$headers['Attribute Type']], ENT_QUOTES, 'UTF-8');

                $this->importKey($keyName, $keyType);
                $this->importGroup($groupName);
                $this->importCollection($groupCollectionName);

                $this->mapKeyToGroup($groupName, $keyName);
                $this->mapGroupToCollection($groupCollectionName, $groupName);
            }

            return $this->json(["status" => "success", "message" => "Import successful"]);

        } catch (PhpSpreadsheetException $e) {
            return $this->json(["status" => "failure", "message" => "Spreadsheet error: " . $e->getMessage()]);
        } catch (\Exception $e) {
            return $this->json(["status" => "failure", "message" => "Error: " . $e->getMessage()]);
        }
    }

    protected function importKey($name, $type)
    {
        $keyConfig = KeyConfig::getByName($name);
        if (!$keyConfig) {
            $keyConfig = new KeyConfig();
            $keyConfig->setName($name);
            $keyConfig->setType($type);
            $keyConfig->setEnabled(true);
            //$keyConfig->setDefinition(json_encode(['title' => ''])); 
            $keyConfig->setDefinition(json_encode(['title' => $name])); 
            $keyConfig->save();
            $this->logger->info("Created new key", ['name' => $name, 'type' => $type]);
        } else {
            $this->logger->info("Key already exists", ['name' => $name]);
        }
        
    }

    protected function importGroup($name)
    {
       
        $groupConfig = GroupConfig::getByName($name);
        
        if (!$groupConfig) {
            $groupConfig = new GroupConfig();
            $groupConfig->setName($name);
            $groupConfig->save();
           ;
            $this->logger->info("Created new group", ['name' => $name]);
        } else {
            
            $this->logger->info("Group already exists", ['name' => $name]);
        }

    }

    protected function importCollection($name)
    {
        
        $collectionConfig = CollectionConfig::getByName($name);

        if (!$collectionConfig) {
            $collectionConfig = new CollectionConfig();
            $collectionConfig->setName($name);
            $collectionConfig->save();
            $this->logger->info("Created new collection", ['name' => $name]);
        } else {
            $this->logger->info("Collection already exists", ['name' => $name]);
        }
    }

    protected function mapKeyToGroup($groupName, $keyName)
    {
        $groupConfig = GroupConfig::getByName($groupName);
        $keyConfig = KeyConfig::getByName($keyName);
    
        if ($groupConfig && $keyConfig) {
            // Check if the mapping already exists
            $existingRelation = Classificationstore\KeyGroupRelation::getByGroupAndKeyId($groupConfig->getId(), $keyConfig->getId());
    
            if (!$existingRelation) {
                // Create a new mapping if it doesn't exist
                $relation = new Classificationstore\KeyGroupRelation();
                $relation->setGroupId($groupConfig->getId());
                $relation->setKeyId($keyConfig->getId());
                $relation->save();
                $this->logger->info("Mapped key to group", ['group' => $groupName, 'key' => $keyName]);
            }
        }
    }
    

    protected function mapGroupToCollection($collectionName, $groupName)
    {
        $collectionConfig = CollectionConfig::getByName($collectionName);
        $groupConfig = GroupConfig::getByName($groupName);

        if ($collectionConfig && $groupConfig) {
            $relation = new Classificationstore\CollectionGroupRelation();
            $relation->setColId($collectionConfig->getId());
            $relation->setGroupId($groupConfig->getId());
            $relation->save();

            $this->logger->info("Mapped group to collection", ['collection' => $collectionName, 'group' => $groupName]);
        }
    }
    

}
