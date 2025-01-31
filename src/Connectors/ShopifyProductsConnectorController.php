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

class ShopifyProductsConnectorController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	*/
	public function defaultAction(Request $request, ApplicationLogger $logger): Response
	{
		$object = DataObject\AbstractObject::getById($request->get('id'));
			
		if ($object->getClassName() == 'CategoryOrTaxonomy') {
			$response = $this->ShopifyCollectionsExport($request->get('id'));

			if ($response['status'] == 'success') {
				$logger->info('Categories Export to Shopify ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Categories',
					'source'        => 'Categories Export to Shopify - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			} else {
				$logger->error('Categories Export to Woocommerce ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Categories',
					'source'        => 'Categories Export to Shopify - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			}

			return $this->json($response);

		}else if($object->getClassName() == 'PlumbingAndSafety'){
			$response = $this->ProductsExportToShopify($request->get('id'));
			if ($response['status'] == 'success') {
				$logger->info('Product Export to Shopify ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Export',
					'source'        => 'Product Export to Shopify - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			} else {
				$logger->error('Product Export to Shopify ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Export',
					'source'        => 'Product Export to Shopify - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			}

			return $this->json($response);
		}else{
			return $this->json(['status' => 'failure', 'message' => 'Selected Item Not in Scope']);
		}
	}
	public function ShopifyCollectionsExport($id)
		{
			$success = 0;
			$category_listing = new DataObject\CategoryOrTaxonomy\Listing();
			$category_data = DataObject\CategoryOrTaxonomy::getByID($id);
			$category_name = $category_data->getCategoryName();
			$shopify_id = $category_data->getShopifyCollectionId();
			$image = '';
			if(!empty($category_data->getCategoryImage())){
				$image = 'http://pimcore.altiussolution.com' . $category_data->getCategoryImage();
			}
			if($shopify_id != '' || $shopify_id != null){
				$api_endpoint = '/admin/api/2023-01/custom_collections/' . $shopify_id . '.json';
				$query = array(
					'custom_collection' => array(
						"title" => $category_name,
						"published"=> true,
						"image" => array(
							"src"=> $image,
							"alt"=> $category_name
						)
					)
				);
				$request_headers = [];
				$method = 'PUT';
				$response = $this->shopify_call($api_endpoint, $query, $method, $request_headers);
				if (isset($response->custom_collection->id)) {
					$category_data->setShopifyLastUpdated(\Carbon\Carbon::now());
					$category_data->setShopifyLastUpdateStatus('Success');
					$category_data->save();
					return ['status' => 'success', 'message' => 'custom Collections Updated Successfully', 'response' => $response, 'query' => $query];
					
				} else {
					// $success = 0;
					$category_data->setShopifyLastUpdated(\Carbon\Carbon::now());
					$category_data->setShopifyLastUpdateStatus('Failed');
					$category_data->save();
					return ['status' => 'failure', 'message' => 'custom Collections Updated Failed', 'response' => $response, 'query' => $query];
				}
			}else{
				$method = 'POST';
				$request_headers = [];
				$api_endpoint = '/admin/api/2025-01/custom_collections.json';
			
				$query = array(
					'custom_collection' => array(
						"title" => $category_name,
						"published"=> true,
						"image" => array(
							"src"=> $image,
							"alt"=> $category_name
						)
					)
				);
				
				$method = 'POST';
				$response = $this->shopify_call($api_endpoint, $query, $method, $request_headers);
				if(!empty($response->custom_collection->id)){
					$success = 1;
					$category_data->setShopifyCollectionHandle($response->custom_collection->handle);
					$category_data->setShopifyCollectionId($response->custom_collection->id);
					$category_data->setShopifyLastUpdated(\Carbon\Carbon::now());
					$category_data->setShopifyLastUpdateStatus('Success');
					$category_data->save();
					return ['status' => 'success', 'message' => 'custom Collections Updated Successfully', 'response' => $response, 'query' => $query];
				}else{
					$success = 0;
					$category_data->setShopifyLastUpdated(\Carbon\Carbon::now());
					$category_data->setShopifyLastUpdateStatus('Failed');
					$category_data->save();
					return ['status' => 'failure', 'message' => 'custom Collections Updated Failed', 'response' => $response, 'query' => $query];
				}
				 //return ['status' => 'success', 'message' => 'Category exported to Shopify', 'response' => $response];
			}
			// return ['status' => 'success', 'message' => 'Category exported to Shopify'];
		}


	public function ProductsExportToShopify($id)
	{


		return "test";
		exit;
		$config = DataObject\ShopifyConfigurations::getByID(206684);
		$access_token = '';
		$api_base_url = '';
		if ($config->getCurrentlyActiveStore() == 'Live') {
			$access_token = $config->getLiveShopifyToken();
			$api_base_url = $config->getLiveShopifyApiBaseUrl();
		} else {
			$access_token = $config->getDevConsumerId();
			$api_base_url = $config->getDevShopifyApiBaseUrl();
		}
		// $auth = base64_encode($consumer_id . ':' . $consumer_secret);
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
		 $attributesArray = array();
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
			if($sep_category->getWoocommerceCategoryId() != 0 || $sep_category->getWoocommerceCategoryId() != ''){
				$cat_arr = array(
					'id' => (int)$sep_category->getWoocommerceCategoryId(),
					'name' => (string)$sep_category->getWoocommerceCategoryName(),
					'slug' => (string)$sep_category->getWoocommerceCategorySlug()
				);
				array_push($categories_array, $cat_arr);
					
				if($sep_category->getWoocommerceCategoryParentId() != 0 || $sep_category->getWoocommerceCategoryParentId() != ''){
					$parent_category_data = $category_listing->setCondition("CategoryName LIKE '%".$sep_category->getParentCategory()."%'");
					if(!empty($parent_category_data)){
						foreach($parent_category_data as $parent_category){
							$cat_arr = array(
								'id' => (int)$parent_category->getWoocommerceCategoryId(),
								'name' => (string)$parent_category->getWoocommerceCategoryName(),
								'slug' => (string)$parent_category->getWoocommerceCategorySlug()
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
		return ['status' => 'success', 'message' => 'products exported to Shopify'];
		
	}

	public function shopify_call($api_endpoint, $query = array(), $method = 'GET', $request_headers = array())
	{
		$config = DataObject\ShopifyConfigurations::getByID(206684);
		$access_token = '';
		$api_base_url = '';
		if ($config->getCurrentlyActiveStore() == 'Live') {
			$access_token = $config->getLiveShopifyToken();
			$api_base_url = $config->getLiveShopifyApiBaseUrl();
		} else {
			$access_token = $config->getDevShopifyToken();
			$api_base_url = $config->getDevShopifyApiBaseUrl();
		}
		// Build URL
		$url = $api_base_url  . $api_endpoint;
		//print_r($url);
		if (!is_null($query) && in_array($method, array('GET', 	'DELETE'))) $url = $url . "?" . http_build_query($query);
		// Configure cURL
		$curl = curl_init($url);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		// curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 3);
		// curl_setopt($curl, CURLOPT_SSLVERSION, 3);
		curl_setopt($curl, CURLOPT_USERAGENT, 'My New Shopify App v.1');
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		$request_headers = array();
		$request_headers[] = "X-Shopify-Access-Token:" . $access_token;
		$request_headers[] = "Content-Type:application/json";
		//print_r($request_headers);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $request_headers);
		curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($query));
		$response = curl_exec($curl);
		$error_number = curl_errno($curl);
		$error_message = curl_error($curl);
		// Close cURL to be nice
		curl_close($curl);
		// Return an error is cURL has a problem
		if ($error_number) {
			return $error_message;
		} else {
			//$response = curl_exec($ch);
			$info = curl_getinfo($curl);
			curl_close($curl);
			$response = [
			  	'headers' => substr($response, 0, $info["header_size"]),
			  	'body' => substr($response, $info["header_size"]),
			];
			return json_decode($response['body']);
		}
	}
}
