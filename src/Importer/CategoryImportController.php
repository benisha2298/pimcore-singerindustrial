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
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\Data\ImageData;
//use Google\Service\Contentwarehouse\ImageData;
use Pimcore\Model\DataObject\Data\ImageGallery;
use Pimcore\Model\Asset\Image;
use Pimcore\Model\DataObject\Data\Gallery;
use Symfony\Component\Process\Process;

use Pimcore\Logger;
use Pimcore\Log\FileObject;
use Pimcore\Log\ApplicationLogger;

class CategoryImportController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	 */
	public function CategoryImport(Request $request, ApplicationLogger $logger): Response
	{
		$file = $request->files->get('category_import');
		if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
            return $this->json(['status' => 'Failure', 'message' => 'Invalid file uploaded']);
        }

        $filePath = $file->getPathname();
        
        // Ensure the file path is correct
        if (!file_exists($filePath)) {
            return $this->json(['status' => 'Failure', 'message' => 'File does not exist: ' . $filePath]);
        }

        // Command to run
        $php = \Pimcore\Tool\Console::getExecutable('php');
        $cmd = $php . ' ' . PIMCORE_PROJECT_ROOT . '/bin/console category-data:catimport ' . escapeshellarg($filePath);
        // print_r($cmd);
        // Process to execute the command
        $process = Process::fromShellCommandline($cmd, PIMCORE_PROJECT_ROOT);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->json(['status' => 'Failure', 'message' => $process->getErrorOutput()]);
        }else{
			return $this->json(['status' => 'success', 'message' => 'Categories Import to Pimcore Successful']);
		}
	}
	
	
}