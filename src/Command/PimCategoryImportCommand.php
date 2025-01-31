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
use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\CategoryOrTaxonomy;
use Pimcore\Model\DataObject\CategoryOrTaxonomy\Listing;
use Pimcore\Model\DataObject\Folder as PimcoreFolder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Data\ImageData;
//use Google\Service\Contentwarehouse\ImageData;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\Data\Gallery;
use Pimcore\Logger;
use Pimcore\Log\FileObject;
use Pimcore\Log\ApplicationLogger;


// use \Pimcore\Model\WebsiteSetting;

use Pimcore\Log\Simple;

class PimCategoryImportCommand extends AbstractCommand
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
            ->setName('category-data:catimport')
            ->setDescription('Run a Category Import.')
            ->addArgument('file', InputArgument::REQUIRED, 'XLSX File')
			->addArgument('batchSize', InputArgument::OPTIONAL, 'Number of items per batch', 100);;
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
		set_time_limit(0);
        try {
            $file = $input->getArgument('file');
            $batchSize = (int) $input->getArgument('batchSize');
            $this->importPimCategories($file);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
			$this->logger->error('Exception '.$e->getMessage());
            return Command::FAILURE;
        }
    }
	
    public function importPimCategories($file)
    {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $chunkSize = 100;
        $startRow = 2;

        $success = 0;
        
        try {
            // Open the file for reading
            $spreadsheet = $reader->load($file);
           
            // Get the active worksheet
            $worksheet = $spreadsheet->getActiveSheet();
			$worksheet = $spreadsheet->getActiveSheet()->toArray();

			$columns = array_shift($worksheet);
			$indexedData = [];
			foreach ($worksheet as $arr) {
                $category_listing = new DataObject\CategoryOrTaxonomy\Listing();
                $category_listing->setCondition("CategoryName = ?", [$arr[0]]);
                $categories = $category_listing->load();

                if (empty($categories)) {
                    $key = trim($arr[0]);
                    $sanitizedKey = preg_replace('/[^a-zA-Z0-9_ -&,]/', '|', $key);
                    // The category does not exist, so create a new one
                    $cat = new DataObject\CategoryOrTaxonomy();
                    
                    $folderPath = $arr[3];
                    $cat->setParent(PimcoreFolder::getByPath($folderPath));
                    
                    $cat->setKey($sanitizedKey);
                    $cat->setPublished(true);
                    $cat->setCategoryName($arr[0]);
                    $cat->setCategoryCode($arr[1]);
                    $cat->setParentCategory($arr[2]);

                    if (!empty($arr[4])) {
                        $imageAsset = \Pimcore\Model\Asset::getByPath($arr[4]);
                        if ($imageAsset instanceof \Pimcore\Model\Asset\Image) {
                            $cat->setCategoryImage($imageAsset);
                        }
                    }

                    $cat->save();

                    // Set the parent category if applicable
                    $parentCategoryName = $arr[2];
                    if ($parentCategoryName && $parentCategoryName !== $cat->getCategoryName()) {
                        // Load potential parent categories that match the parent category name
                        $pcategory_listing = new DataObject\CategoryOrTaxonomy\Listing();
                        $pcategory_listing->setCondition("CategoryName = ?", [$parentCategoryName]);
                        $parent_categories = $pcategory_listing->load();
                    
                        if (!empty($parent_categories)) {
                            foreach ($parent_categories as $parent_category) {
                                if ($parent_category->getId() !== $cat->getId()) {
                                    $cat->setParent($parent_category);
                                    $cat->save();
                                    break; // Exit the loop after successfully setting the parent
                                }
                            }
                        } else {
                            // Log warning if parent category is not found
                            // $this->logger->warning("Parent category '{$parentCategoryName}' not found for category '{$cat->getKey()}'.");
                        }
                    }
                    if ($cat->getID() != '' && $cat->getID() != null) {
                        $this->logger->info('Categories Import', [
                            'fileObject'    => (string)$cat->getId(),
                            'relatedObject' => $cat,
                            'component'     => 'Categories Import to Pimcore',
                            'source'        => 'Categories Import to Pimcore successfull '. $cat->getId(), // optional, if empty, gets automatically filled with 
                        ]);
                        $success = 1;
                    } else {
                        $success = 0;
                    }
                }
            }
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
	
}