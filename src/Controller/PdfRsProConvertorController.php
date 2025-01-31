<?php

namespace App\Controller;

use ReflectionObject;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use SeoBundle\Middleware\MiddlewareDispatcherInterface;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject;
use Pimcore\Logger;
use Pimcore\Log\FileObject;
use Pimcore\Log\ApplicationLogger;
use Pimcore\Model\Asset\Document;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Dompdf\Dompdf;
use Dompdf\Options;
use Pimcore\Tool;
use Pimcore\Model\Asset;
use Pimcore\Templating\Template;

class PdfRsProConvertorController extends FrontendController
{
    public function PdfConvertor(Request $request, ApplicationLogger $logger): Response
    {
        
            // Get the uploaded file
            $file = $request->files->get('xlsx_to_pdf');

            // Validate the uploaded file
            if (!$file || !$file->isValid()) {
                return new Response('Invalid file uploaded', Response::HTTP_BAD_REQUEST);
            }
    
            $filePath = $file->getPathname();
    
            // Ensure the file exists
            if (!file_exists($filePath)) {
                return new Response('File does not exist', Response::HTTP_BAD_REQUEST);
            }
    
            try {
                // Load the Excel file
                $reader = IOFactory::createReader('Xlsx');
                $spreadsheet = $reader->load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
    
                // Retrieve header data (first row) for reference
                $headingData = [];
                foreach ($sheet->getRowIterator(1, 1) as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $headingData[] = $cell->getValue();
                    }
                }
    
                $pdfFiles = [];
                $rowIterator = $sheet->getRowIterator(2); // Data starts from the second row
    
                foreach ($rowIterator as $row) {
                    $rowIndex = $row->getRowIndex();
                    $identifier = $sheet->getCell("A$rowIndex")->getValue(); // Adjust column for unique identifier
    
                    // Generate PDF content
                    $htmlContent = $this->generateHtmlContent($sheet, $rowIndex);
                 
                    // Initialize Dompdf
                    $options = new Options();
                    $options->set('isHtml5ParserEnabled', true);
                    $dompdf = new Dompdf($options);
                    $dompdf->setPaper('A4', 'landscape');
                    $dompdf->loadHtml($htmlContent);
                    $dompdf->render();
    
                    // Generate filename
                    $formattedDate = (new \DateTime())->format('Y-m-d_H-i-s');
                    $pdfFileName = "{$identifier}_{$formattedDate}.pdf";
    
                    // Save PDF data
                    $pdfFiles[] = ['pdf' => $dompdf->output(), 'filename' => $pdfFileName];
                }
           
                // Create ZIP file
                $zipFilename = sys_get_temp_dir() . '/pdfs_' . uniqid() . '.zip';
                $this->createZipFile($pdfFiles, $zipFilename);
    
                // Send ZIP file as response
                return new Response(
                    file_get_contents($zipFilename),
                    200,
                    [
                        'Content-Type' => 'application/zip',
                        'Content-Disposition' => 'attachment; filename="pdf_files.zip"',
                    ]
                );
    
            } catch (\Exception $e) {
                return new Response('Error processing file: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
            }
    }
  // Function to convert column letter to numeric index
  private function columnLetterToIndex($columnLetter) {
    $columnLetter = strtoupper($columnLetter);
    $index = 0;
    $length = strlen($columnLetter);

    for ($i = 0; $i < $length; $i++) {
        $index = $index * 26 + ord($columnLetter[$i]) - ord('A') + 1;
    }

    return $index;
  }


  private function generateHtmlContent($sheet, int $rowIndex): string {
   // Get column indices dynamically based on the header row
$headerRow = $sheet->getRowIterator(1)->current();
$imageColumnIndex = null;
$featuresColumnIndex = null;

foreach ($headerRow->getCellIterator() as $cell) {
    $columnValue = strtolower(trim($cell->getValue()));
    if (strpos($columnValue, 'image') !== false) {
        $imageColumnIndex = $this->columnLetterToIndex($cell->getColumn());
    } elseif (strpos($columnValue, 'features') !== false) {
        $featuresColumnIndex = $this->columnLetterToIndex($cell->getColumn());
    }
}

// Throw an error if required columns are missing
if ($imageColumnIndex === null || $featuresColumnIndex === null) {
    throw new Exception("Error: Missing required columns (image or features).");
}

// Get data from the row dynamically
$imagePath = $sheet->getCellByColumnAndRow($imageColumnIndex, $rowIndex)->getValue();
$features = $sheet->getCellByColumnAndRow($featuresColumnIndex, $rowIndex)->getValue();
$identifier = $sheet->getCell("A$rowIndex")->getValue(); // Unique identifier column

// Initialize variables
$imageUrl = '';
if (!empty($imagePath)) {
    $imageAsset = \Pimcore\Model\Asset::getByPath($imagePath);
    if ($imageAsset instanceof \Pimcore\Model\Asset\Image) {
        $imageUrl = $imageAsset->getFullPath(); // Full URL of the image
    }
}

// Existing logic for attributes
$attributeColumns = [];
$columnNames = ['attribute name', 'attribute value', 'attribute group'];
$headerRow = $sheet->getRowIterator(1)->current();

foreach ($headerRow->getCellIterator() as $cell) {
    $columnValue = strtolower(trim($cell->getValue()));
    foreach ($columnNames as $columnName) {
        if (strpos($columnValue, strtolower($columnName)) !== false) {
            $columnIndex = $this->columnLetterToIndex($cell->getColumn());
            $attributeColumns[$columnName][] = $columnIndex;
        }
    }
}

$attributeNameColumns = $attributeColumns['attribute name'] ?? [];
$attributeValueColumns = $attributeColumns['attribute value'] ?? [];
$attributeGroupColumns = $attributeColumns['attribute group'] ?? [];

if (empty($attributeNameColumns) || empty($attributeValueColumns) || empty($attributeGroupColumns)) {
    throw new Exception("Error: Missing required columns (attribute name, value, or group).");
}

$attributeNameData = [];
foreach ($attributeNameColumns as $index => $attributeNameColumn) {
    $attributeValueColumn = $attributeValueColumns[$index] ?? null;
    $attributeGroupColumn = $attributeGroupColumns[$index] ?? null;

    if ($attributeValueColumn && $attributeGroupColumn) {
        $attributeNameValue = $sheet->getCellByColumnAndRow($attributeNameColumn, $rowIndex)->getValue();
        $attributeValueValue = $sheet->getCellByColumnAndRow($attributeValueColumn, $rowIndex)->getValue();
        $attributeGroupValue = $sheet->getCellByColumnAndRow($attributeGroupColumn, $rowIndex)->getValue();

        if (!empty($attributeNameValue) || !empty($attributeValueValue)) {
            $attributeNameData[] = [
                'attribute_name' => $attributeNameValue,
                'attribute_value' => $attributeValueValue,
                'attribute_group' => $attributeGroupValue,
            ];
        }
    }
}

// Generate HTML for the specified row
$displayedGroups = [];
foreach ($attributeNameData as $data) {
    $groupKey = strtolower(trim($data['attribute_group']));
    $attributeRow = <<<HTML
    <tr style="text-align:center;">
        <td>{$data['attribute_name']}</td>
        <td>{$data['attribute_value']}</td>
    </tr>
HTML;

    if (!isset($displayedGroups[$groupKey])) {
        $displayedGroups[$groupKey] = [
            'header' => <<<HTML
                <h4 class="secondary-heading">{$data['attribute_group']}</h4>
                <hr>
                <table class="table-data">
                    <tbody>
HTML,
            'rows' => '',
        ];
    }

    $displayedGroups[$groupKey]['rows'] .= $attributeRow;
}

$tableHtml = '';
foreach ($displayedGroups as $group) {
    $tableHtml .= $group['header'] . $group['rows'] . '</tbody></table>';
}

$baseUrl = Tool::getHostUrl(); // Retrieve the base URL dynamically
$productUrl = $baseUrl . $imagePath;

// echo $productUrl;
// exit;
return  $test = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>XLSX to PDF</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <style>
    /* Your existing styles */
    body {
      margin: 0;
      padding: 0;
    }
    #content-to-print {
      width: 794px; /* Standard A4 width in pixels */
      margin: 0 auto;
      padding: 20px;
      background: white;
    }
 /* font family */
 @import url(https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap);

h1, h2, h3, h4, h5, h6, li, ol, a, div, p {
  font-family: "Poppins", serif;
  font-weight: 400;
  font-style: normal;
}
.right-alignment{text-align:right;}
.pdf-header{
  border-bottom: solid 4px #c00000;
  padding: 8px;
}
.pdf-header h5{
  margin-bottom: 0;
    color: #c00000;
    font-weight: 500;
}
.sidebar-section{
  background-color: #c00000;
  margin-top: 50px; 
}
.features-list li{color: #fff;margin-bottom: 5px;}
.sidebar-section h4{
  background-color: #fff;
    color: #c00000;
    padding: 20px 0;
    text-align: center;
}
.image-section img {
    width: 100%; /* Set the image width to 100% of its container */
    height: auto; /* Maintain aspect ratio */
    display: block; /* Prevent extra spacing around the image */
  }
.main-section h5{color:#A6A6A6;}
.product-img{border:solid 2px #000;padding:50px 70px;}
.pdf-container-fluid{background-color: #c00000;padding: 0;}
.page-section{margin: 50px 0;}
.main-section{padding-left: 30px;margin-top: 50px;}
.main-section h2, .main-section h5{margin-bottom:20px;}
.para-section{margin-top:50px;}
.features-list{padding: 50px;}
.page-footer p,.page-footer a {color:#c00000;margin-bottom:0;font-size: 12px;}
.page-footer{border-top: solid 4px #c00000;padding: 20px 0;}
.secondary-heading{
  width: fit-content;
    color: #fff;
    background-color: #c00000;
    padding: 8px 25px;
    border-radius: 10px 10px 0 0;
    margin-bottom: 0;
    font-size: 16px;
    margin-top: 35px;
}
.page-section hr{
    margin: 0;
    color: #c00000;
    border-top: solid 3px #c00000;
    opacity: 1;
}
.content-box{
  margin-top: 20px;
    padding: 30px 10px;
    border: solid 1px #F9D4B9
}
.content-box h4{margin-bottom:20px;}
table {
  width: 100%;
  border-collapse: collapse;
    }
    th, td {
      border: 1px solid #F9D4B9;
      text-align: left;
      padding: 8px;
    }
    th {
      background-color: #fbe4d5;
    }
    .table-data{margin-top:20px;}
    .table-data-with-header th, .table-data-with-header td {text-align:center;}
   
  </style>
</head>
<body>
  <div id="content-to-print">
    <!-- Your HTML content -->
    <header class="pdf-header">
      <div class="container-fluid">
        <div class="row align-items-center justify-content-center">
          <div class="col-lg-6 col-md-6 col-sm-6 col-xs-12">
            <h5>Digital Multimeters</h5>
          </div>
          <div class="col-lg-6 col-md-6 col-sm-6 col-xs-12 right-alignment">
            <img src="http://pimcore.altiussolution.com/RS%20_Pro/header-img.png" alt="header-img">
          </div>
        </div>
      </div>
    </header>
  
    <section class="page-section">
      <div class="container-fluid">
        <div class="row">
          <div class="col-lg-4 col-md-4 col-sm-4 col-xs-12 pdf-container-fluid">
            <div class="sidebar-section">
              <h4>FEATURES </h4>
              <ul class="features-list">
              {$features}
              </ul>
          
            </div>
          </div>
          <div class="col-lg-8 col-md-8 col-sm-8 col-xs-12">
  
              <div class="main-section">
                  <div class="main-para-section"><h2>RS PRO Digital Multimeters- RS14 Handheld Digital Multimeter</h2>
                  <h5>RS Stock No.: {$identifier} </h5>
                  <div class="image-section"><img src="$productUrl" alt="product-img" class="product-img"></div>
              </div>  
  
                <div class="para-section"><p>RS Professionally Approved Products bring to you professional quality parts across all product categories. Our product range has been tested by engineers and provides a comparable quality to the leading brands without paying a premium price</p></div>
  
              </div>
          </div>
        </div>
      </div>
    </section>

    <footer class="page-footer">
      <div class="container-fluid">
        <div class="row align-items-center justify-content-center">
          <div class="col-lg-8 col-md-8 col-sm-8 col-xs-12">
            <p>RS Components – Buy this product from <a href="https://uk.rs-online.com/">https://uk.rs-online.com/ </a></p>
          </div>
          <div class="col-lg-4 col-md-4 col-sm-4 col-xs-12 right-alignment">
            <p>Page 1 of 8 </p>
          </div>
        </div>
      </div>
    </footer>    
  </div>

  <div class="pdf-width" id="content-to-print">
  
  <header class="pdf-header">
    <div class="container-fluid">
      <div class="row align-items-center justify-content-center">
        <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
          <h5>Digital Multimeters</h5>
        </div>
        <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12 right-alignment">
          <img src="http://pimcore.altiussolution.com/RS%20_Pro/header-img.png" alt="header-img">
        </div>
      </div>
    </div>
  </header>

  <section class="page-section">
    <div class="container-fluid">
      <div class="inner-section">
          <h4 class="secondary-heading">Product Description </h4>
            <hr>
          <div class="content-box">
              <h4>RS PRO RS14 Digital Multimeter</h4>    
              <p>The RS PRO RS14 digital multimeter (DMM) is a handheld tool which can measure capacitance, voltage, 
                electrical current and resistance with diode and continuity check. There is also a “hold” function available 
                in the compact design making it very user-friendly. The multimeter is also CAT III rated for 600V.</p>
          </div>    
      </div>
    </div>
  </section>

  <section class="page-section">
    <div class="container-fluid">
      <div class="inner-section">
            {$tableHtml}
      </div>
    </div>
  </section>
  <footer class="page-footer">
    <div class="container-fluid">
      <div class="row align-items-center justify-content-center">
        <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
          <p>RS Components – Buy this product from <a href="https://uk.rs-online.com/">https://uk.rs-online.com/</a></p>
        </div>
        <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12 right-alignment">
          <p>Page 2 of 8 </p>
        </div>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

</div>
</body>
</html>

HTML;
echo $test;
exit;
  
  
  }

  private function createZipFile(array $pdfFiles, string $zipFilename): void
  {
      $zip = new \ZipArchive();

      if ($zip->open($zipFilename, \ZipArchive::CREATE) === TRUE) {
          foreach ($pdfFiles as $file) {
              $zip->addFromString($file['filename'], $file['pdf']);
          }
          $zip->close();
      } else {
          throw new \Exception('Failed to create ZIP file');
      }
  }
           
}