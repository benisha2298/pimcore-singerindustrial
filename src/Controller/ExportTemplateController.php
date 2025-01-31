<?php
namespace App\Controller;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Pimcore\Model\Asset;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExportTemplateController extends FrontendController
{
    /**
     * @Route("/admin/get-xlsx-headers", name="get_xlsx_headers", methods={"GET"})
     */
    public function getXlsxHeaders(Request $request): JsonResponse
    {
        $assetId = $request->get('assetId');
        $headers = [];

        if ($assetId) {
            $asset = Asset::getById($assetId);
            if ($asset && $asset->getMimetype() === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                $filePath = $asset->getFileSystemPath();
                $spreadsheet = IOFactory::load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();

                foreach ($worksheet->getColumnIterator() as $column) {
                    $cell = $worksheet->getCell($column->getColumnIndex() . '1');
                    $headers[] = $cell->getValue();
                }
            }
        }

        return new JsonResponse($headers);
    }
}
