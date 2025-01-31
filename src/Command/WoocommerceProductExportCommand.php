<?php

namespace App\Command;

use Pimcore\Console\AbstractCommand;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;

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
use Pimcore\Model\Asset;



// use \Pimcore\Model\WebsiteSetting;

use Pimcore\Log\Simple;

class WoocommerceProductExportCommand extends AbstractCommand
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
            ->setName('product-data:wooexport')
            ->setDescription('Run a Product Export.')
            ->addArgument('datas', InputArgument::REQUIRED, 'Product ID')
			->addArgument('batchSize', InputArgument::OPTIONAL, 'Number of IDs per batch', 100);;
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
		set_time_limit(0);
        try {
            $datas = $input->getArgument('datas');
            $ids = explode(',', $datas);
            $batchSize = (int) $input->getArgument('batchSize');
            $chunks = array_chunk($ids, $batchSize);

            foreach ($chunks as $batch) {
                $this->processBatch($batch);
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
			$this->logger->error('Exception '.$e->getMessage());
            return Command::FAILURE;
        }
    }
	private function processBatch(array $batch)
    {
        foreach ($batch as $id) {
            $response = $this->importProducts(trim($id));

            if (!$response) {
				$this->logger->error("Failed to import product with ID: $id");
            } else {
                // $io->success("Successfully imported product with ID: $id");
				$this->logger->info('Successfully imported product ', [
					'fileObject'    => new FileObject(json_encode($response)),
					'relatedObject' => null,
					'component'     => 'Plumbing & Safety Products',
					'source'        => 'Plumbing and Safety Export to Woocommerce'. json_encode($response), // optional, if empty, gets automatically filled with 
				]);
            }
        }
    }

    public function importProducts($id)
    {
        $config = DataObject\WoocommerceConfigurations::getByID(25101);
        $consumer_id = '';
        $consumer_secret = '';
        $api_base_url = '';
		$username = '';
		$password = '';
        if ($config->getCurrentlyActiveStore() == 'Live') {
            $consumer_id = $config->getLiveConsumerId();
            $consumer_secret = $config->getLiveConsumerSecret();
            $api_base_url = $config->getLiveApiBaseUrl();
			$username = $config->getLiveUsername();
			$password = $config->getLivePassword();
        } else {
            $consumer_id = $config->getDevConsumerId();
            $consumer_secret = $config->getDevConsumerSecret();
            $api_base_url = $config->getDevApiBaseUrl();
			$username = $config->getDevUsername();
			$password = $config->getDevPassword();
        }

        $auth = base64_encode($consumer_id . ':' . $consumer_secret);
        $product_data = DataObject\PlumbingAndSafety::getByID($id);

        if (!$product_data) {
            $this->logger->error('Product not found with id '.$id);
            return false;
        }

        $description = $product_data->getFeatureCopy() . "\n" . $product_data->getFeatureBullets();
        $categories_array = $this->getCategories($product_data->getCategories());
        $image = $this->getImages($product_data);

		$pdf_files = array();
		if(!empty($product_data->getBrochureCatalogLink())){
			$document = $product_data->getBrochureCatalogLink();
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'Brochure Catalog',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
		}else{
            $pdf_files[] = array(
                'key' => 'Brochure Catalog',
                'value' => ''
            );
        }

		if (!empty($product_data->getSpecificationSheet())) {
			$document = $product_data->getSpecificationSheet();
			$pdfAssetId = $document->getId();
			
			if ($pdfAssetId) {
				$pdfAsset = Asset::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'Specification Sheet',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
		}else{
            $pdf_files[] = array(
                'key' => 'Specification Sheet',
                'value' => ''
            );
        }

		if(!empty($product_data->getInstructionInstallationManualLink())){
			$document = $product_data->getInstructionInstallationManualLink();
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'Instruction Manual',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
		}else{
            $pdf_files[] = array(
                'key' => 'Instruction/Installation Manual',
                'value' => ''
            );
        }

		if(!empty($product_data->getOwnerManual())){
			$document = $product_data->getOwnerManual(); 
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'Owner Manual',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
		}else{
            $pdf_files[] = array(
                'key' => 'Owner Manual',
                'value' => ''
            );
        }

		if(!empty($product_data->getDrawingSheetLineDrawingPartsListLink())){
			$document = $product_data->getDrawingSheetLineDrawingPartsListLink();
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'Drawing Sheet Line Drawing Parts List',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
		}else{
            $pdf_files[] = array(
                'key' => 'Drawing Sheet Line Drawing Parts List',
                'value' => ''
            );
        }
		if(!empty($product_data->getMSDSSDSSheetLink())){
			$document = $product_data->getMSDSSDSSheetLink();
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'MSD/SSDS Sheet',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
		}else{
            $pdf_files[] = array(
                'key' => 'MSD/SSDS Sheet',
                'value' => ''
            );
        }

        $attributesArray = $this->getAttributes($product_data);

        $product_array = $this->prepareProductArray($product_data, $description, $categories_array, $image, $attributesArray, $pdf_files);
        
        $endpoint = $product_data->getWoocommerceProductId() ? '/products/' . $product_data->getWoocommerceProductId() : '/products';
        $method = $product_data->getWoocommerceProductId() ? 'PUT' : 'POST';
        $request_url = $api_base_url . $endpoint;
        

        $response = $this->woocommerce_api_call($auth, $request_url, $product_array, $method);
		$this->logger->info('Plumbing and Safety Export to Woocommerce ', [
			'fileObject'    => new FileObject(json_encode($response)),
			'relatedObject' => $product_data,
			'component'     => 'Plumbing & Safety Products',
			'source'        => 'Plumbing and Safety Export to Woocommerce'. json_encode($response), // optional, if empty, gets automatically filled with 
		]);
		// return $response;
        if (isset($response->id)) {
            $product_data->setWoocommerceProductId($response->id);
            $product_data->setLastUpdated(\Carbon\Carbon::now());
            $product_data->setLastWoocommerceStatus('Success');
            $product_data->save();
            return true;
        } else {
            $product_data->setLastUpdated(\Carbon\Carbon::now());
            $product_data->setLastWoocommerceStatus('Failed');
            return false;
        }
    }

    private function getCategories($pim_categories)
    {
        $categories_array = [];
        $category_listing = new DataObject\CategoryOrTaxonomy\Listing();
        $details = $category_listing->setCondition("CategoryName LIKE '%" . $pim_categories . "%'");

        foreach ($details as $sep_category) {
            if ($sep_category->getWoocommerceCategoryId()) {
                $cat_arr = [
                    'id' => (int)$sep_category->getWoocommerceCategoryId(),
                    'name' => (string)$sep_category->getWoocommerceCategoryName(),
                    'slug' => (string)$sep_category->getWoocommerceCategorySlug()
                ];
                $categories_array[] = $cat_arr;

                if ($sep_category->getWoocommerceCategoryParentId()) {
                    $parent_category_data = $category_listing->setCondition("CategoryName LIKE '%" . $sep_category->getParentCategory() . "%'");
                    foreach ($parent_category_data as $parent_category) {
                        $cat_arr = [
                            'id' => (int)$parent_category->getWoocommerceCategoryId(),
                            'name' => (string)$parent_category->getWoocommerceCategoryName(),
                            'slug' => (string)$parent_category->getWoocommerceCategorySlug()
                        ];
                        $categories_array[] = $cat_arr;
                    }
                }
            }
        }
        return $categories_array;
    }

    private function getImages($product_data)
    {
		$image = [];
		if ($product_data->getProductImage() != null || $product_data->getProductImage() != '') {
			$alt = "";
			if($product_data->getProductImage()->getMetadata("alt") != null){
				$alt = $product_data->getProductImage()->getMetadata("alt");
			}else{
				$alt = "";
			}
            if(!empty($product_data->getProductImage())){
                $image[] = array(
                    'alt' => $alt,
                    'src' => 'http://pimcore.altiussolution.com' . $product_data->getProductImage()
                );
            }
			
			
		}
		$addlImages = $product_data->getAdditionalImages();
		$i = 2;
		if(!empty($addlImages) || $addlImages != null){
			foreach ($addlImages as $img) {
				$alt = "";
				if($img->getImage()->getMetadata("alt") != null){
					$alt = $img->getImage()->getMetadata("alt");
				}else{
					$alt = "";
				}
                if(!empty($img->getImage())){
                    $image[] = array(
                        'alt' => $alt,
                        'src' => 'http://pimcore.altiussolution.com' . $img->getImage()
                    );
                }
				
				$i++;
			}
		}
		return $image;
    }

    private function getAttributes($product_data)
    {
        $attributesArray = [
            [
                'name' => 'Manufacturer Part Number 1',
                'position' => 2,
                'visible' => !empty($product_data->getMFRPartNumber_1()),
                'variation' => false,
                'options' => [(string)$product_data->getMFRPartNumber_1()]
            ],
            [
                'name' => 'Manufacturer Part Number 2',
                'position' => 3,
                'visible' => !empty($product_data->getMFRPartNumber_2()),
                'variation' => false,
                'options' => [(string)$product_data->getMFRPartNumber_2()]
            ],
            [
                'name' => 'UPC',
                'position' => 4,
                'visible' => false,
                'variation' => false,
                'options' => [(string)$product_data->getUPC()]
            ],
            [
                'name' => 'UNSPSC',
                'position' => 5,
                'visible' => !empty($product_data->getUNSPSC()),
                'variation' => false,
                'options' => [(string)$product_data->getUNSPSC()]
            ],
            [
                'name' => 'Warranty',
                'position' => 6,
                'visible' => !empty($product_data->getWarranty()),
                'variation' => false,
                'options' => [(string)$product_data->getWarranty()]
            ],
            [
                'name' => 'ManufacturerName',
                'position' => 7,
                'visible' => !empty($product_data->getManufacturerName()),
                'variation' => false,
                'options' => [(string)$product_data->getManufacturerName()]
            ]
        ];

        $count = 7;
        foreach ($product_data->getTaxonomySpecificAttributes()->getItems() as $groupId => $group) {
            foreach ($group as $keyId => $key) {

                if ($product_data->getTaxonomySpecificAttributes()->getLocalizedKeyValue($groupId, $keyId) instanceof \Pimcore\Model\DataObject\Data\QuantityValue) {
                    $value = (string)$product_data->getTaxonomySpecificAttributes()->getLocalizedKeyValue($groupId, $keyId)->getValue();
                } else {
                    $value = (string)$product_data->getTaxonomySpecificAttributes()->getLocalizedKeyValue($groupId, $keyId);
                }
				if(\Pimcore\Model\DataObject\Classificationstore\KeyConfig::getById($keyId)->getName() == 'Brand'){

					$attribute = [
						'name' => 'Brand',
						'position' => 1,
						'visible' => !empty($value) ? true : false,
						'variation' => false,
						'options' => [
							(string) $value
						]
						];
				}else{
					$attribute = [
						'name' => \Pimcore\Model\DataObject\Classificationstore\KeyConfig::getById($keyId)->getName(),
						'position' => $count,
						'visible' => !empty($value) ? true : false,
						'variation' => false,
						'options' => [
							(string)$value
						]
					];
				}
                
                $count++;
                $attributesArray[] = $attribute;
            }
        }
        return $attributesArray;
    }

    private function prepareProductArray($product_data, $description, $categories_array, $image, $attributesArray, $pdf_files)
    {
		$description = $product_data->getFeatureCopy() . "\n" . $product_data->getFeatureBullets();
		$product_array = array(
			"name"=> $product_data->getShortDescription(),
			"slug"=> $product_data->getShortDescription(),
			"type"=> "simple",
			"status"=> "publish",
			"featured"=> false,
			"catalog_visibility"=> "visible",
			"description"=> $description,
			"short_description"=> $product_data->getShortDescription(),
			'weight' => $product_data->getProductWeight(),
			"sku"=> $product_data->getKey(),
			"price"=> $product_data->getMRP(),
			"regular_price"=> $product_data->getMRP(),
			"sale_price"=> "",
			"price_html"=> "<span class=\"woocommerce-Price-amount amount\"><span class=\"woocommerce-Price-currencySymbol\">&#36;</span>$".$product_data->getMRP()."</span>",
			"categories"=> $categories_array,
			"tags"=> array(),
			"images"=> $image,
			"attributes"=> $attributesArray,
			// 'downloads' => $pdf_files,
			'meta_data' => $pdf_files

		);
		return $product_array;
    }
	public function shortenSlug($slug)
	{
		$maxLength = 28;
		if (strlen($slug) > $maxLength) {
			return substr($slug, 0, $maxLength);
		}
		return $slug;
	}
    public function woocommerce_api_call($auth, $request_url, $query = array(), $method = 'POST', $request_headers = array())
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $request_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($query),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/json'
            ],
        ]);
        $response = curl_exec($curl);
        $error_number = curl_errno($curl);
        $error_message = curl_error($curl);
        curl_close($curl);

        if ($error_number) {
            $this->logger->error('Failed to upload PDF. HTTP Code: ' . $error_number . ' Response: ' . json_encode($error_message));
            return $error_message;
        } else {
            return json_decode($response);
        }
    }
	
}