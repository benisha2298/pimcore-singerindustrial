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
use Pimcore\Model\Asset;

class DassProductCategoriesExportController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	*/
	public function defaultAction(Request $request, ApplicationLogger $logger): Response
	{
		$object = DataObject\AbstractObject::getById($request->get('id'));
		

		if ($object->getClassName() == 'CategoryOrTaxonomy') {
			$response = $this->CategoriesExport($request->get('id'));

			if ($response['status'] == 'success') {
				$logger->info('Categories Export to Dass Woocommerce ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Categories',
					'source'        => 'Categories Export to Dass Woocommerce - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			} else {
				$logger->error('Categories Export to Dass Woocommerce ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Categories',
					'source'        => 'Categories Export to Dass Woocommerce - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			}

			return $this->json($response);

		}else if($object->getClassName() == 'PlumbingAndSafety'){
			$response = $this->ProductsExportToWoocommerce($request->get('id'));
			if ($response['status'] == 'success') {
				$logger->info('Product Export to Dass Woocommerce ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Export',
					'source'        => 'Product Export to Dass Woocommerce - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			} else {
				$logger->error('Product Export to Dass Woocommerce ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Export',
					'source'        => 'Product Export to Dass Woocommerce - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			}

			return $this->json($response);
		}else{
			return $this->json(['status' => 'failure', 'message' => 'Selected Item Not in Scope']);
		}
	}
	public function CategoriesExport($id)
	{
		$success = 0;
		$config = DataObject\WoocommerceConfigurations::getByID(29323);
		$consumer_id = '';
		$consumer_secret = '';
		$api_base_url = '';
		if ($config->getCurrentlyActiveStore() == 'Live') {
			$consumer_id = $config->getLiveConsumerId();
			$consumer_secret = $config->getLiveConsumerSecret();
			$api_base_url = $config->getLiveApiBaseUrl();
		} else {
			$consumer_id = $config->getDevConsumerId();
			$consumer_secret = $config->getDevConsumerSecret();
			$api_base_url = $config->getDevApiBaseUrl();
		}
		$auth = base64_encode($consumer_id . ':' . $consumer_secret);
		$category_listing = new DataObject\CategoryOrTaxonomy\Listing();
		$category_data = DataObject\CategoryOrTaxonomy::getByID($id);
		$category_name = $category_data->getCategoryName();
		$category_code = $category_data->getCategoryCode();
		$parent_category = $category_data->getParentCategory();
		$category_slug_initial = str_replace(array(',', '&', ' '), '-', $category_name);
		$category_slug_final = preg_replace('/-+/', '-', $category_slug_initial);

		$woo_cat_id = $category_data->getDassWoocommerceCategoryId();
		$woo_par_cat_id = $category_data->getDassWoocommerceCategoryParentId();
		$woo_cat_slug = $category_data->getDassWoocommerceCategorySlug();
        $category_image_path = $category_data->getCategoryImage();
        $img = array();
        if(!empty($category_image_path)){
            $img = array(
				"src"=> 'http://pimcore.altiussolution.com' . $category_data->getCategoryImage(),
				"name"=> "",
				"alt"=> ""
			);
        }else{
            $img = array();
        }
		if(!empty($woo_cat_id)){
			$woo_id = (int)$category_data->getDassWoocommerceCategoryId();
			$get_parent = $category_data->getDassWoocommerceCategoryParentId();
			$category_array = array(
				"name" => $category_name,
				"slug" => $woo_cat_slug,
				"parent" => (int)$woo_par_cat_id,
				"description" => "",
				"display" => "both",
				"image" => $img,
				"menu_order" => 0,
				"count" => 36
			);
			$category_data_final[] = $category_array;
			$success = 1;
			$method = 'PUT';
			$endpoint = '/products/categories/'.$woo_id;
			$request_url = $api_base_url . $endpoint;
			$woocommerce_data = $this->woocommerce_api_call($auth, $request_url, $category_array, $method, $request_headers = array());
			if(!empty($woocommerce_data->id)){
				$success = 1;
				$category_data->setDassLastUpdated(\Carbon\Carbon::now());
				$category_data->setDassLastWoocommerceStatus('Success');
				$category_data->save();
			}
		}else{
			$parent_category = $category_data->getParentCategory();
			$parent_data = $category_listing->setCondition("CategoryName = ?", [$parent_category]);
			if(!empty($parent_category) && isset($parent_data) && $parent_data!=''){
				$export_category = array();
				foreach($parent_data as $pcat_key => $pcat_value){
					
					if($pcat_value->getWoocommerceCategoryId() !=0 && !empty($pcat_value->getWoocommerceCategoryId())){
						$export_category = array(
							"name" => $category_name,
							"slug" => $category_slug_final,
							"parent" => (int)$pcat_value->getWoocommerceCategoryId(),
							"description" => "",
							"display" => "both",
							"image" => $img,
							"menu_order" => 0,
							"count" => 36
						);
					}else{
						$success = 3;
					}
				}
				// print_r($export_category);
				$category_data_final[] = $export_category;
				$method = 'POST';
				$endpoint = '/products/categories';
				$request_url = $api_base_url . $endpoint;
				$woocommerce_data = $this->woocommerce_api_call($auth, $request_url, $export_category, $method, $request_headers = array());
				if(!empty($woocommerce_data->id)){
					$success = 1;
					$category_data->setWoocommerceCategoryName($woocommerce_data->name);
					$category_data->setWoocommerceCategoryId($woocommerce_data->id);
					$category_data->setWoocommerceCategorySlug($woocommerce_data->slug);
					$category_data->setWoocommerceCategoryParentId($woocommerce_data->parent);
					$category_data->setLastUpdated(\Carbon\Carbon::now());
					$category_data->setLastWoocommerceStatus('Success');
					$category_data->save();
				}
			}else{
				$category_array = array(
					"name" => $category_name,
					"slug" => $category_slug_final,
					"parent" => 0,
					"description" => "",
					"display" => "both",
					"image" => $img,
					"menu_order" => 0,
					"count" => 36
				);
				$category_data_final[] = $category_array;
				$method = 'POST';
				$endpoint = '/products/categories';
				$request_url = $api_base_url . $endpoint;
				$woocommerce_data = $this->woocommerce_api_call($auth, $request_url, $category_array, $method, $request_headers = array());
                // print_r($category_array);
				if(!empty($woocommerce_data->id)){
					$success = 1;
					$category_data->setDassWoocommerceCategoryName($woocommerce_data->name);
					$category_data->setDassWoocommerceCategoryId($woocommerce_data->id);
					$category_data->setDassWoocommerceCategorySlug($woocommerce_data->slug);
					$category_data->setDassWoocommerceCategoryParentId(0);
					$category_data->setDassLastUpdated(\Carbon\Carbon::now());
					$category_data->setDassLastWoocommerceStatus('Success');
					$category_data->save();
				}

			}
			
		}
		
		if($success == 1){
			return ['status' => 'success', 'message' => 'Category exported to dass woocommerce', 'query' => $category_data_final];
		}elseif($success == 2) {
			$category_data->setDassLastUpdated(\Carbon\Carbon::now());
			$category_data->setDassLastWoocommerceStatus('Failed');
			$category_data->save();
			return ['status' => 'failure', 'message' => 'Category already exist in woocommerce', 'query' => array()];
		}elseif($success == 3){
			$category_data->setDassLastUpdated(\Carbon\Carbon::now());
			$category_data->setDassLastWoocommerceStatus('Failed');
			$category_data->save();
			return ['status' => 'failure', 'message' => 'Parent Category not exist in woocommerce', 'query' => array()];
		}else{
			$category_data->setDassLastUpdated(\Carbon\Carbon::now());
			$category_data->setDassLastWoocommerceStatus('Failed');
			$category_data->save();
			return ['status' => 'failure', 'message' => 'Category export failed', 'query' => array()];
		}
	}


	public function ProductsExportToWoocommerce($id)
	{
		$config = DataObject\WoocommerceConfigurations::getByID(29323);
		$consumer_id = '';
		$consumer_secret = '';
		$api_base_url = '';
		if ($config->getCurrentlyActiveStore() == 'Live') {
			$consumer_id = $config->getLiveConsumerId();
			$consumer_secret = $config->getLiveConsumerSecret();
			$api_base_url = $config->getLiveApiBaseUrl();
		} else {
			$consumer_id = $config->getDevConsumerId();
			$consumer_secret = $config->getDevConsumerSecret();
			$api_base_url = $config->getDevApiBaseUrl();
		}
		$auth = base64_encode($consumer_id . ':' . $consumer_secret);
		$products_listing = new DataObject\PlumbingAndSafety\Listing();
		$product_data = DataObject\PlumbingAndSafety::getByID($id);
		$classDefinition = $product_data->getClass();
		$fieldDefinitions = $classDefinition->getFieldDefinitions();
		$brick_fieldnames = array();
		
		$not_required = array('fieldname', 'doDelete', 'object', 'objectId', 'dao', 'loadedLazyKeys', 'type', '_fulldump', 'o_dirtyFields', 'FeatureCopy', 'FeatureBullets');
		
		$attributesArray = [
			[
				'name' => 'Manufacturer Part Number 1',
				'position' => 2,
				'visible' => !empty($product_data->getMFRPartNumber_1()) ? true : false,
				'variation' => false,
				'options' => [
					(string) $product_data->getMFRPartNumber_1()
				]
			],
			[
				'name' => 'Manufacturer Part Number 2',
				'position' => 3,
				'visible' => !empty($product_data->getMFRPartNumber_2()) ? true : false,
				'variation' => false,
				'options' => [
					(string) $product_data->getMFRPartNumber_2()
				]
			],
			[
				'name' => 'UPC',
				'position' => 4,
				'visible' => false,
				'variation' => false,
				'options' => [
					(string) $product_data->getUPC()
				]
			],
			[
				'name' => 'UNSPSC',
				'position' => 5,
				'visible' => !empty($product_data->getUNSPSC()) ? true : false,
				'variation' => false,
				'options' => [
					(string) $product_data->getUNSPSC()
				]
			],
			[
				'name' => 'Warranty',
				'position' => 6,
				'visible' => !empty($product_data->getWarranty()) ? true : false,
				'variation' => false,
				'options' => [
					(string) $product_data->getWarranty()
				]
			],
			[
				'name' => 'ManufacturerName',
				'position' => 7,
				'visible' => !empty($product_data->getManufacturerName()) ? true : false,
				'variation' => false,
				'options' => [
					(string) $product_data->getManufacturerName()
				]
			]

		];
		// $attributesArray = array();
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
							$value
						]
					];
				}
                
                $count++;
                $attributesArray[] = $attribute;
            }
        }
		
		$description = $product_data->getFeatureCopy() . "\n" . $product_data->getFeatureBullets();
		$categories_array = array();
		$pim_categories = $product_data->getCategories();
		// return ['status' => 'Success', 'message' => $categories_array];
		$category_listing = new DataObject\CategoryOrTaxonomy\Listing();
		$details = $category_listing->setCondition("CategoryName LIKE '%".$pim_categories."%'");
		
		if(!empty($details)){
			foreach($details as $sep_category){
			// return ['status' => 'Success', 'message' => $sep_category];
			if($sep_category->getDassWoocommerceCategoryId() != 0 || $sep_category->getDassWoocommerceCategoryId() != ''){
				$cat_arr = array(
					'id' => (int)$sep_category->getDassWoocommerceCategoryId(),
					'name' => (string)$sep_category->getDassWoocommerceCategoryName(),
					'slug' => (string)$sep_category->getDassWoocommerceCategorySlug()
				);
				array_push($categories_array, $cat_arr);
					
				if($sep_category->getDassWoocommerceCategoryParentId() != 0 || $sep_category->getDassWoocommerceCategoryParentId() != ''){
					$parent_category_data = $category_listing->setCondition("CategoryName LIKE '%".$sep_category->getParentCategory()."%'");
					if(!empty($parent_category_data)){
						foreach($parent_category_data as $parent_category){
							$cat_arr = array(
								'id' => (int)$parent_category->getDassWoocommerceCategoryId(),
								'name' => (string)$parent_category->getDassWoocommerceCategoryName(),
								'slug' => (string)$parent_category->getDassWoocommerceCategorySlug()
							);
							array_push($categories_array, $cat_arr);
						}
					}
				}
			}
		}
		}
		
		$image = [];
		if ($product_data->getProductImage() != null || $product_data->getProductImage() != '') {
			$alt = "";
			if($product_data->getProductImage()->getMetadata("alt") != null){
				$alt = $product_data->getProductImage()->getMetadata("alt");
			}else{
				$alt = "";
			}
			$image[] = array(
				'alt' => $alt,
				'src' => 'http://pimcore.altiussolution.com' . $product_data->getProductImage()
			);
			
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
				$image[] = array(
					'alt' => $alt,
					'src' => 'http://pimcore.altiussolution.com' . $img->getImage()
				);
				$i++;
			}
		}
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
		}

		if(!empty($product_data->getInstructionInstallationManualLink())){
			$document = $product_data->getInstructionInstallationManualLink();
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId);
				if ($pdfAsset) {
					$pdfUrl = $pdfAsset->getFullPath();
					$pdf_files[] = array(
						'key' => 'Instruction/Installation Manual',
						'value' => 'http://pimcore.altiussolution.com' . $pdfUrl
					);
				}
			}
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
		}

		$success_product = array();
		$success = 0;
		$product_id = $product_data->getDassWoocommerceProductId();
		if(!empty($product_id)) {
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
				'downloads' => $pdf_files,
				'meta_data' => $pdf_files

			);
			$endpoint = '/products/'.$product_id;
			$request_url = $api_base_url . $endpoint;
			$method = 'PUT';
			$create_products = $this->woocommerce_api_call($auth, $request_url, $product_array, $method, $request_headers = array());
			$success_product[] = $create_products;
			$product_data->setDassLastUpdated(\Carbon\Carbon::now());
			$product_data->setDassLastWoocommerceStatus('Success');
			$product_data->save();
			$success = 1;
			
		}else{
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
				'downloads' => $pdf_files,
				'meta_data' => $pdf_files
			);
			$endpoint = '/products';
			$request_url = $api_base_url . $endpoint;
			$method = 'POST';
			$create_products = $this->woocommerce_api_call($auth, $request_url, $product_array, $method, $request_headers = array());
			if(!empty($create_products->id)){
				$success_product[] = $create_products;
				$product_data->setDassWoocommerceProductId($create_products->id);
				$product_data->setDassLastUpdated(\Carbon\Carbon::now());
				$product_data->setDassLastWoocommerceStatus('Success');
				$product_data->save();
				$success = 1;
			}else{
				$success = 0;
				$success_product[] = $create_products;
			}
		}
		if($success == 1){
			return ['status' => 'success', 'message' => 'Product Data Export to Woocommerce Successful', 'product' => $success_product, 'query' => $product_array];
		}else{
			$product_data->setDassLastUpdated(\Carbon\Carbon::now());
			$product_data->setDassLastWoocommerceStatus('Failed');
			return ['status' => 'failure', 'message' => 'Product Data Export to Woocommerce Failed', 'product' => $success_product, 'query' => $product_array];
		}
		
	}

	public function woocommerce_api_call($auth, $request_url, $query = array(), $method, $request_headers = array())
	{
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $request_url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_POSTFIELDS => json_encode($query),
			CURLOPT_HTTPHEADER => array(
				'Authorization: Basic ' . $auth,
				'Content-Type: application/json'
			),
		));
		$response = curl_exec($curl);
		$error_number = curl_errno($curl);
		$error_message = curl_error($curl);
		curl_close($curl);
		if ($error_number) {
			return $error_message;
		} else {
			return json_decode($response);
		}
	}
	public function shortenSlug($slug)
	{
		$maxLength = 28;
		if (strlen($slug) > $maxLength) {
			return substr($slug, 0, $maxLength);
		}
		return $slug;
	}
}
