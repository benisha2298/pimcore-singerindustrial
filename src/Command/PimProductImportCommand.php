<?php

namespace App\Command;

use Pimcore\Console\AbstractCommand;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\PlumbingAndSafety;
use Pimcore\Model\DataObject\PlumbingAndSafety\Listing;
use Pimcore\Model\DataObject\Folder as PimcoreFolder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Data\ImageData;
//use Google\Service\Contentwarehouse\ImageData;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\Data\Gallery;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Pimcore\Model\DataObject\Classificationstore\KeyConfig;
use Pimcore\Model\DataObject\QuantityValue\Unit;
use Pimcore\Model\DataObject\Data\InputQuantityValue;
use Pimcore\Model\DataObject\Classificationstore;
use Pimcore\Model\DataObject\Classificationstore\Group;
use Pimcore\Model\DataObject\Classificationstore\GroupConfig;
use Pimcore\Logger;
use Pimcore\Log\FileObject;
use Pimcore\Log\ApplicationLogger;


// use \Pimcore\Model\WebsiteSetting;

use Pimcore\Log\Simple;

class PimProductImportCommand extends AbstractCommand
{
	private $logger;

    public function __construct(ApplicationLogger $logger)
    {
        $this->logger = $logger;
        parent::__construct();
    }
	protected function configure(): void
    {
        $this
            ->setName('product-data:pimimport')
            ->setDescription('Run a Product Import.')
            ->addArgument('file', InputArgument::REQUIRED, 'XLSX File')
			->addArgument('batchSize', InputArgument::OPTIONAL, 'Number of items per batch', 100);;
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
		set_time_limit(0);
        try {
            $file = $input->getArgument('file');
            $batchSize = (int) $input->getArgument('batchSize');
            $this->importPimProducts($file);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
			$this->logger->error('Exception '.$e->getMessage());
            return Command::FAILURE;
        }
    }
	
    public function importPimProducts($file)
    {
        
        // Initialize the PhpSpreadsheet reader with chunk reading enabled
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $chunkSize = 100; // Number of rows to read per chunk
        $startRow = 2; // Skip header row

        $success = 0;
        
        try {
            // Open the file for reading
            $spreadsheet = $reader->load($file);
           
            // Get the active worksheet
            $worksheet = $spreadsheet->getActiveSheet();
			$worksheet = $spreadsheet->getActiveSheet()->toArray();

			$columns = array_shift($worksheet);
            // $this->logger->info('Product Import start', [
            //     'fileObject'    => '',
            //     'relatedObject' => null,
            //     'component'     => 'Products Import to Pimcore',
            //     'source'        => 'Products Import to Pimcore successfull '. $columns, // optional, if empty, gets automatically filled with 
            // ]);
			// Initialize an empty array to hold the indexed data
			$indexedData = [];

			// Iterate through the remaining rows and index the data
			foreach ($worksheet as $row) {
				$indexedRow = [];
				foreach ($columns as $index => $column) {
					$indexedRow[$column] = $row[$index] ?? null;
				}
				$indexedData[] = $indexedRow;
               
			}
			$row_val = array();
           
			foreach ($indexedData as  $row_value) {
                
				$product_exist = new DataObject\PlumbingAndSafety\Listing();
				$product_exist->setCondition("o_key LIKE '%" . trim($row_value['Key']) . "%'");
				$product_exist->setUnpublished(true);
				$prod_data = null;
				$products = $product_exist->load();
				if($products){
					foreach($products as $product_data){
						$prod = $product_data;
						break;
					}
					if(isset($prod)){
						$prod_data = $prod;
					}else{
						$product_exist = new DataObject\PlumbingAndSafety\Listing();
						$product_exist->setCondition("o_key LIKE '%" . trim($row_value['Key']) . "%'");
						$objects = $product_exist->load();
						if($objects){
							foreach($objects as $parents){
								$parent = $parents;
								break;
							}
							if(isset($parent)){
								$prod_data = $parent;
							}else{
								break;
							}
						}
					}
				}
				if(isset($prod_data)){

				}else{
					// Ensure the "Products" folder exists
					$productsFolderPath = '/Products';
					$productsFolder = PimcoreFolder::getByPath($productsFolderPath);
			
					if (!$productsFolder instanceof PimcoreFolder) {
						// Create the "Products" folder if it doesn't exist
						$productsFolder = new PimcoreFolder();
						$productsFolder->setKey('Products');
						$productsFolder->setParentId(1); // Set the ID of the root folder as the parent
						$productsFolder->save();
					}
			
					$full_path = $row_value['Taxonomy'];
					$exploded_path = explode('>', $full_path);
					$firstElement = trim(reset($exploded_path));
					$lastElement = trim(end($exploded_path));
					$parentFolderPath = $productsFolderPath . '/' . $firstElement;
					$parentFolder = PimcoreFolder::getByPath($parentFolderPath);
			
					if (!$parentFolder instanceof PimcoreFolder) {
						// Create the parent folder if it doesn't exist
						$parentFolder = new PimcoreFolder();
						$parentFolder->setKey($firstElement);
						$parentFolder->setParent($productsFolder); // Set the "Products" folder as the parent
						$parentFolder->save();
					}
			
					$currentParentFolder = $parentFolder;
					$childFolderPath = $parentFolderPath . '/' . $lastElement;
			
					// Check if the child folder exists
					$childFolder = PimcoreFolder::getByPath($childFolderPath);
					if (!$childFolder instanceof PimcoreFolder) {
						// Create the child folder if it doesn't exist
						$childFolder = new PimcoreFolder();
						$childFolder->setKey($lastElement);
						$childFolder->setParent($currentParentFolder);
						$childFolder->save();
					}

				
					// Update the current parent folder to be the current child folder
					$currentParentFolder = $childFolder;
					$product = new DataObject\PlumbingAndSafety;
					// Set the parent folder for the new data object
					$product->setParent($currentParentFolder);
					$all_keys = array();
					$index = 0;
					// return $this->json(['status' => 'success', 'message' => $row_value]);
					foreach ($row_value as $key => $value) {
						
						if($key == 'ProductImage' || $key == 'AdditionalImages' || $key == 'SpecificationSheet' || $key == 'BrochureCatalogLink' || $key == 'MSDSSDSSheetLink' || $key == 'InstructionInstallationManualLink' || $key == 'OwnerManual' || $key == 'DrawingSheetLineDrawingPartsListLink' || $key == 'VideoUrl'){
							
							// if($key == 'Categories'){
							// 	$categories = explode('>', $value);
							// 	$product->setCategories(array($value));
							// }
							if ($key == 'ProductImage') {
								if($value != '' || $value != null){
									$imageAsset = \Pimcore\Model\Asset::getByPath($value);
									if ($imageAsset instanceof \Pimcore\Model\Asset\Image) {
										$product->setProductImage($imageAsset);
									}
								}
							}
							if($key == 'AdditionalImages'){
								$add_imgs = explode(',', $value);
								$galleryItems = array();
								foreach ($add_imgs as $a_key => $a_value) {
									if ($a_value != '') {
										$imageGallery = \Pimcore\Model\Asset::getByPath($a_value);
										if ($imageGallery instanceof \Pimcore\Model\Asset\Image) {
											$galleryItem = new \Pimcore\Model\DataObject\Data\Hotspotimage();
                							$galleryItem->setImage($imageGallery);
											$galleryItems[] = $galleryItem;
										}
									}
								}
								$product->setAdditionalImages(new \Pimcore\Model\DataObject\Data\ImageGallery($galleryItems));

							}
							if ($key == 'VideoUrl') {
								
								if($value != '' || $value != null){
									if (strpos($value, 'youtube.com') !== false || strpos($value, 'youtu.be') !== false) {
										$product->setYoutubeLink($value);
									} else {
										
										$videoData = file_get_contents($value);
											
										if ($videoData !== false) {
											$filename = basename($value);
											$videoAsset = \Pimcore\Model\Asset::getByPath('/Assets/Videos/' . $filename);
											
											if (!$videoAsset instanceof \Pimcore\Model\Asset\Video) {
												
												// If the video asset doesn't exist, create a new one
												$videoAsset = new \Pimcore\Model\Asset\Video();
												$videoAsset->setFilename($filename);
												$videoAsset->setData($videoData);
												$videoAsset->setParentId(680);
												$videoAsset->save();
											}
											
											// Create a new video data object
											$videoDataObject = new \Pimcore\Model\DataObject\Data\Video();
											$videoDataObject->setData($videoAsset->getId()); // Set the ID of the video asset to the data object

											// Set poster image
											$assetImage = \Pimcore\Model\Asset::getById(773);
											if ($assetImage instanceof \Pimcore\Model\Asset\Image) {
												
												$videoDataObject->setPoster($assetImage->getId());
											}

											// Set title and description
											$videoDataObject->setType("asset");
											$videoDataObject->setTitle("My Title");
											$videoDataObject->setDescription("My Description");

											// Set the video data object to the product
											$product->setVideoUrl($videoDataObject);
											$product->save();
										
											
										}
									}
								}
							}				
												
							if($key == 'SpecificationSheet'){
								$pdfUrl = $value;
								if($pdfUrl != '' || $pdfUrl != null){
									$pdfAsset = \Pimcore\Model\Asset::getByPath($pdfUrl);

									if ($pdfAsset instanceof \Pimcore\Model\Asset\Document) {
										$product->setSpecificationSheet($pdfAsset);
									}
									
								}
								
							}

							if($key == 'MSDSSDSSheetLink'){
								$pdfUrl = $value;
								if($pdfUrl != '' || $pdfUrl != null){
									$pdfAsset = \Pimcore\Model\Asset::getByPath($pdfUrl);

									if ($pdfAsset instanceof \Pimcore\Model\Asset\Document) {
										$product->setSpecificationSheet($pdfAsset);
									}
									
								}
								
							}
							if($key == 'BrochureCatalogLink'){
								$pdfUrl = $value;
								if($pdfUrl != '' || $pdfUrl != null){
									$pdfAsset = \Pimcore\Model\Asset::getByPath($pdfUrl);

									if ($pdfAsset instanceof \Pimcore\Model\Asset\Document) {
										$product->setBrochureCatalogLink($pdfAsset);
									}
									
								}
							}
							if($key == 'InstructionInstallationManualLink'){
								$pdfUrl = $value;
								if($pdfUrl != '' || $pdfUrl != null){
									$pdfAsset = \Pimcore\Model\Asset::getByPath($pdfUrl);

									if ($pdfAsset instanceof \Pimcore\Model\Asset\Document) {
										$product->setInstructionInstallationManualLink($pdfAsset);
									}
									
								}
							}
							if($key == 'OwnerManual'){
								$pdfUrl = $value;
								if($pdfUrl != '' || $pdfUrl != null){
									$pdfAsset = \Pimcore\Model\Asset::getByPath($pdfUrl);
									if ($pdfAsset instanceof \Pimcore\Model\Asset\Document) {
										$product->setOwnerManual($pdfAsset);
									}
									
								}
							}
							if ($key == 'DrawingSheetLineDrawingPartsListLink') {
								$pdfUrl = $value;
								if ($pdfUrl != '' || $pdfUrl != null) {
									$pdfAsset = \Pimcore\Model\Asset::getByPath($pdfUrl);
									if ($pdfAsset instanceof \Pimcore\Model\Asset\Document) {
										$product->setDrawingSheetLineDrawingPartsListLink($pdfAsset);
									}
									
								}
							}
						}else{
							// $all_keys[] = $key;
							$key = trim($key);
							$searchTerm = 'Att Name';
							if (str_contains($key, 'Att Name')) {
								$index++;
								// echo $index;
								// // echo "The string 'lazy' was found in the string\n";
								$all_keys[] = array(
									'attribute_name' => $row_value['Att Name '.$index],
									'attribute_value' => $row_value['Att Value '.$index],
									'attribute_uom' => $row_value['Att UOM '.$index]
								);
							}
							$property = 'set' . $key;
							if (method_exists($product, $property)) {
								// echo "Calling method: $property with value: $value\n";
								$product->$property($value);
							}
						}
						// return $this->json(['status' => 'success', 'message' => $value]);
						
					}
					$encodedName = htmlspecialchars($row_value['EndNode'], ENT_QUOTES, 'UTF-8');
					$groupId = $this->getClassificationGroup($encodedName);

					if (!empty($groupId)) {
						$attribute = $product->getTaxonomySpecificAttributes(); // Replace with your actual method to get the classification store field

						if ($attribute instanceof Classificationstore) {
							$attribute->setActiveGroups([$groupId => true]);
							
							foreach ($all_keys as $attributes) {
								$atkey = $this->getClassificationKey(htmlspecialchars($attributes['attribute_name'], ENT_QUOTES, 'UTF-8'));
								
								if (!empty($atkey)) {
									$type = $this->getClassificationKeyType(htmlspecialchars($attributes['attribute_name'], ENT_QUOTES, 'UTF-8'));
									// $definition = '';
									if ($type != 'inputQuantityValue') {
										$definition = $attributes['attribute_value'];
									} else {
										$definitionClassTypeName = '\\Pimcore\\Model\\DataObject\\Data\\InputQuantityValue';
										$unit = null;

										if (!empty($attributes['attribute_uom'])) {
											$unit = Unit::getByAbbreviation($attributes['attribute_uom']);
										}

										$definition = new $definitionClassTypeName($attributes['attribute_value'], $unit);
									}
									
									$attribute->setLocalizedKeyValue($groupId, $atkey, $definition);
									// print_r($attribute); die;
								}
							}
						}
					}
					$product->setPublished(true);
					$product->save();
                    $this->logger->info('Product Import', [
                        'fileObject'    => (string)$product->getId(),
                        'relatedObject' => $product,
                        'component'     => 'Products Import to Pimcore',
                        'source'        => 'Products Import to Pimcore successfull '. $product->getId(), // optional, if empty, gets automatically filled with 
                    ]);
					//print_r("published product is " . $product);
					//die;
					$success = 1;
				}
			}
			
            // return $this->json(['status' => 'success', 'message' => $all_keys]);
			

            // Set success flag if any rows are processed
            if ($success > 0) {
                return true;
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception '.$e->getMessage());
            // Handle any exceptions
            return false;
        }

        // Return failure if no rows are processed
        return false;
    }
	public function getClassificationKey($name)
    {
        $config = KeyConfig::getByName($name);
        return !empty($config) ? $config->getId() : null;
    }

    public function getClassificationKeyType($name)
    {
        $config = KeyConfig::getByName($name);
        return !empty($config) ? $config->getType() : null;
    }

    public function getClassificationGroup($endNode)
    {
        // Convert the input name to the stored format (e.g., "Lab Coat &amp; Jackets")
        // $encodedName = htmlspecialchars($endNode, ENT_QUOTES, 'UTF-8');
        $config = GroupConfig::getByName($endNode);
        return !empty($config) ? $config->getId() : null;
    }
}