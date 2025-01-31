<?php

namespace App\Importer;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Logger;
use Pimcore\Log\FileObject;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Model\Asset\Document;
use Symfony\Component\Process\Process;

class ProductsImportController extends FrontendController
{
    
	/**
	 * @param Request $request
	 * @return Response
	 */
	public function ProductsImport(Request $request, ApplicationLogger $logger): Response
	{
        $file = $request->files->get('products_import');

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
        $cmd = $php . ' ' . PIMCORE_PROJECT_ROOT . '/bin/console product-data:pimimport ' . escapeshellarg($filePath);
        // print_r($cmd);
        // Process to execute the command
        $process = Process::fromShellCommandline($cmd, PIMCORE_PROJECT_ROOT);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->json(['status' => 'Failure', 'message' => $process->getErrorOutput()]);
        }else{
			return $this->json(['status' => 'success', 'message' => 'Products Import to Pimcore Successful']);
		}
    }
}
