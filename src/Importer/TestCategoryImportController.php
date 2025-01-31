<?php

namespace App\Importer;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
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

class TestCategoryImportController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	 */
	public function defaultAction(Request $request, ApplicationLogger $logger): Response
	{
		$file = $request->files->get('category_import');
$reader = new XlsxReader();
$reader->setReadDataOnly(true);
$reader->setReadEmptyCells(false);

$chunkSize = 100; // Number of rows to read per chunk
$startRow = 2; // Skip header row

$success = 0;
// Open the file for reading
$spreadsheet = $reader->load($file);

// Get the active worksheet
$worksheet = $spreadsheet->getActiveSheet()->toArray();

$columns = array_shift($worksheet);

foreach ($worksheet as $arr) {
    $categoryName = trim($arr[0]);
    $categoryCode = trim($arr[1]);
    $parentCategoryName = trim($arr[2]);
    $folderPath = trim($arr[3]);
    $imagePath = trim($arr[4]);

    // Check if the category already exists
    $category_listing = new DataObject\CategoryOrTaxonomy\Listing();
    $category_listing->setCondition("CategoryName = ?", [$categoryName]);
    $categories = $category_listing->load();

    if (empty($categories)) {
        // Sanitize the key
        $sanitizedKey = preg_replace('/[^a-zA-Z0-9_ -&,]/', '|', $categoryName);

        // Create a new category
        $cat = new DataObject\CategoryOrTaxonomy();
        $cat->setKey($sanitizedKey);
        $cat->setPublished(true);
        $cat->setCategoryName($categoryName);
        $cat->setCategoryCode($categoryCode);

        // Set the parent folder path
        $parentFolder = PimcoreFolder::getByPath($folderPath);
        if ($parentFolder && $parentFolder->getId() !== $cat->getId()) {
            $cat->setParent($parentFolder);
        }

        // Assign an image if provided
        if (!empty($imagePath)) {
            $imageAsset = \Pimcore\Model\Asset::getByPath($imagePath);
            if ($imageAsset instanceof \Pimcore\Model\Asset\Image) {
                $cat->setCategoryImage($imageAsset);
            }
        }

        // Save the new category initially
        $cat->save();
        var_dump($cat);die;
        // Set the parent category if applicable
        if ($parentCategoryName && $parentCategoryName !== $categoryName) {
            $pcategory_listing = new DataObject\CategoryOrTaxonomy\Listing();
            $pcategory_listing->setCondition("CategoryName = ?", [$parentCategoryName]);
            $parent_categories = $pcategory_listing->load();

            if (!empty($parent_categories)) {
                foreach ($parent_categories as $parent_category) {
                    // Ensure the category isn't being set as its own parent
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

        if ($cat->getID() !== null) {
            $success = 1;
        } else {
            $success = 0;
        }
    } else {
        // Category already exists, handle this case as needed
        // Optionally log or output a message
        // $this->logger->info("Category '{$categoryName}' already exists.");
    }
}

		if ($success == 1) {
			return $this->json(['status' => 'success', 'message' => 'Category/Taxonomy Import Successful']);
		} else {
			return $this->json(['status' => 'Failure', 'message' => 'Category/Taxonomy Import Failed']);
		}
	}

}