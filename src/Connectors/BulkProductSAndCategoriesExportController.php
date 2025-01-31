<?php

namespace App\Connectors;

use ReflectionObject;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use SeoBundle\Middleware\MiddlewareDispatcherInterface;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\CategoryOrTaxonomy;
use Pimcore\Model\DataObject\PlumbingAndSafety;
use Pimcore\Model\DataObject\WoocommerceConfigurations;
use Pimcore\Model\DataObject\PlumbingAndSafety\Data\Objectbricks;
use Pimcore\Logger;
use Pimcore\Log\FileObject;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Model\Asset\Document;
use Symfony\Component\Process\Process;

class BulkProductSAndCategoriesExportController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	 */
	public function defaultAction(Request $request, ApplicationLogger $logger): Response
	{
		$datas = $request->get('datas');
		$php = \Pimcore\Tool\Console::getExecutable('php');
		$cmd = $php . ' ' . PIMCORE_PROJECT_ROOT . '/bin/console product-data:wooexport ' . $datas;
		$process = Process::fromShellCommandline($cmd);
		$process->setTimeout(null);
		$process->run();

		return $this->json(['status' => 'success', 'message' => 'Processing Bulk Products Export to Woocommerce']);
	}
}
