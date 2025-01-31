<?php

namespace App\Controller;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Routing\Annotation\Route;
use Pimcore\Tool;
use Knp\Snappy\Pdf;

class Rspropdfconvert extends FrontendController
{
    /**
     * @Route("/convert-xlsx-to-pdf", name="convert_xlsx_to_pdf")
     */
    public function pdfconvert(Request $request): Response
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

            // Path to header and footer HTML files
            $headerHtmlPath = 'http://pimcore.altiussolution.com/Pdftemplates/header.html'; // Replace with the actual path
            $footerHtmlPath = 'http://pimcore.altiussolution.com/Pdftemplates/footer.html'; // Replace with the actual path

            foreach ($rowIterator as $row) {
                $rowIndex = $row->getRowIndex();
                $identifier = $sheet->getCell("A$rowIndex")->getValue(); // Adjust column for unique identifier

                // Generate PDF content
                $htmlContent = $this->generateHtmlContent($sheet, $rowIndex);

                // Path to wkhtmltopdf binary
                $wkhtmltopdfPath = '/usr/bin/wkhtmltopdf'; // Adjust the path if necessary

                // Initialize KnpSnappy Pdf
                $snappy = new Pdf($wkhtmltopdfPath);

                //Set options for wkhtmltopdf
                $snappy->setOption('encoding', 'utf-8');
                $snappy->setOption('enable-local-file-access', true); 
                $snappy->setOption('page-size', 'A4');
                $snappy->setOption('orientation', 'Portrait');
                $snappy->setOption('header-html', $headerHtmlPath); 
                $snappy->setOption('footer-html', $footerHtmlPath); 
                $snappy->setOption('margin-top', '20mm'); 
                $snappy->setOption('margin-bottom', '20mm'); 

                // Generate filename with timestamp
                $formattedDate = (new \DateTime())->format('Y-m-d_H-i-s');
                $pdfFileName = "{$identifier}_{$formattedDate}.pdf";

                // Generate PDF content
                $pdfContent = $snappy->getOutputFromHtml($htmlContent);

                // Store PDF file information
                $pdfFiles[] = ['pdf' => $pdfContent, 'filename' => $pdfFileName];
            }

            // Create ZIP file
            $zipFilename = sys_get_temp_dir() . '/pdfs_' . uniqid() . '.zip';
            $this->createZipFile($pdfFiles, $zipFilename);

            // Send ZIP file as response for download
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
    private function columnLetterToIndex($columnLetter)
    {
        $columnLetter = strtoupper($columnLetter);
        $index = 0;
        $length = strlen($columnLetter);

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + ord($columnLetter[$i]) - ord('A') + 1;
        }

        return $index;
    }

    private function generateHtmlContent($sheet, int $rowIndex): string
    {
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
            throw new \Exception("Error: Missing required columns (image or features).");
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
            throw new \Exception("Error: Missing required columns (attribute name, value, or group).");
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
        $pageContent = '';
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

// $headerHtmlPath = '/Pdftemplates/header.html';
//echo $headerHtmlPath;
// exit;
   $content = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>HTML to PDF</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
<style>
   
   body {
     margin: 0;
     padding: 0;
   }
   #content-to-print {
     width: 794px;
     /* height:1123px; */
     margin: 0 auto;
     padding: 20px;
     background: white;
     position: relative;
     /* overflow: hidden; */
   }

h1, h2, h3, h4, h5, h6{
 font-family: "Poppins", serif;
 font-weight: 500;
 font-style: normal;
}
li, ol, a, div, p {
 font-family: "Poppins", serif;
 font-weight: 400;
 font-style: normal;
}
li, ol, a, p {font-size:14px;}
.right-alignment{text-align:right;}
.pdf-header{
 border-bottom: solid 4px #c00000;
 padding: 8px;
}
.pdf-header h5{
 margin-bottom: 0;
   color: #c00000;
   font-weight: 500;
   font-size: 20px;
   margin-top: 0;
}
.sidebar-section{
 background-color: #c00000;
 margin-top: 50px;
}
.features-list li{color: #fff;margin-bottom: 5px;}
.sidebar-section h4{
 background-color: #fff;
   color: #c00000;
   padding: 10px 0;
   text-align: center;
   font-size: 24px;
}
.main-section h5{color:#A6A6A6;font-size: 18px;margin-top: 0;}
.product-img{border:solid 2px #000;padding:50px 70px;max-width: 270px;}
.pdf-container-fluid{background-color: #c00000;padding: 0;}
.page-section{margin: 5px 0 40px;}
.main-section{padding-left: 30px;margin-top: 50px;}
.main-section h2, .main-section h5{margin-bottom:20px;}
.para-section{margin-top:50px;}
.features-list{padding: 50px;}
.page-footer p,.page-footer a {
 color:#c00000;
 margin-bottom:0;  
 font-size: 14px;
 margin-top: 0;
 text-decoration: none;
}
.page-footer{
 border-top: solid 4px #c00000;
   padding: 20px 0;
   position: absolute;
   width: 95.3%;
   bottom: 0;
   background-color:#fff;
}
.main-section h2{color:#c00000;}
.header-row{
 display:flex;
 flex-wrap:wrap;
 align-items: center;  
}
.header-row2{
 display:flex;
 flex-wrap:wrap;
}

/* second page of pdf */

.secondary-heading{
 width: fit-content;
   color: #fff;
   background-color: #c00000;
   padding: 5px 25px;
   border-radius: 10px 10px 0 0;
   margin-bottom: 0;
   font-size: 14px;
}
.page-section hr{
   margin: 0;
   color: #c00000;
   border-top: solid 3px #c00000;
   opacity: 1;
}
.content-box{
 margin-top: 20px;
   padding: 5px 10px;
   border: solid 1px #F9D4B9
}
.content-box h4{
 margin-bottom: 15px;
   font-size: 18px;
   margin-top: 8px;
}
table {
 width: 100%;
 border-collapse: collapse;
   }
   .page-section th, .page-section td {
     border: 1px solid #F9D4B9;
     text-align: left;
     padding: 5px 8px;
     font-size: 14px;
     font-weight: 500;
   }
   th {
     background-color: #fbe4d5;
   }
   .table-data{margin-top:20px;}
   .table-data-with-header th, .table-data-with-header td {text-align:center;}  
.specification-table td{width:50%;}
.page-break {
           page-break-before: always;
       }
 </style>
</head>
<body>


<div id="content-to-print">    
<div class="page-section">
 <table style="width: 100%;height: 100%;">
   <tr>
     <td style="vertical-align:top;background-color: #c00000;">
       <div class="sidebar-section">
         <div class="sidebar-section">
           <h4>FEATURES </h4>
           <ul class="features-list">
             <li>Compact and handheld</li>
             <li>Digital 2000 count LCD display</li>
             <li>3-year warranty</li>
             <li>104 x 70 x 48mm dimension</li>
             <li>Continuity tester</li>
             <li>Functions measured: AC and DC Current, AC and DC Voltage, Resistance, Temperature Measurement</li>
           </ul>
      
         </div>
       </div>
     </td>
     <td style="vertical-align:top;">
       <div class="half">  
         <div class="main-section">
             <div class="main-para-section"><h2>RS PRO Digital Multimeters- RS14 Handheld Digital Multimeter</h2>
             <h5>RS Stock No: 123-1938</h5>
             <div class="image-section"><img src="http://pimcore.altiussolution.com//RS_Pro/1233415.png" alt="product-img" class="product-img"></div>
         </div>
           <div class="para-section"><p>RS Professionally Approved Products bring to you professional quality parts across all product categories. Our product range has been tested by engineers and provides a comparable quality to the leading brands without paying a premium price</p></div>
         </div>
     </div>
     </td>        
   </tr>
 </table>
</div>

</div>

<div class="page-break"></div>

<div id="content-to-print">

<div class="page-section">
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
</div>

<div class="page-section">
 <div class="container-fluid">
   <div class="inner-section">
   {$tableHtml}
        
   </div>
 </div>



</div>




</body>
</html>


HTML;
return $content;
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
