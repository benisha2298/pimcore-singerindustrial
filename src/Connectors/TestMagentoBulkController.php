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

class TestMagentoBulkController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	*/
	public function defaultAction(Request $request, ApplicationLogger $logger): Response
	{
		$object = DataObject\AbstractObject::getById($request->get('id'));
		if ($object->getClassName() == 'CategoryOrTaxonomy') {
			$response = $this->CategoriesExportToMagento($request->get('id'));

			if ($response['status'] == 'success') {
				$logger->info('Categories Export to Magento ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Categories',
					'source'        => 'Categories Export to Magento - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			} else {
				$logger->error('Categories Export to Magento ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Categories',
					'source'        => 'Categories Export to Magento - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			}

			return $this->json($response);

		}else if($object->getClassName() == 'PlumbingAndSafety'){
			$response = $this->ProductsExportToMagento($request->get('id'));
			if ($response['status'] == 'success') {
				$logger->info('Product Export to Magento ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Export',
					'source'        => 'Product Export to Magento - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			} else {
				$logger->error('Product Export to Magento ', [
					'fileObject'    => new FileObject($this->json($response)),
					'relatedObject' => $object,
					'component'     => 'Product Export',
					'source'        => 'Product Export to Magento - ' . $request->get('id'), // optional, if empty, gets automatically filled with 
				]);
			}

			return $this->json($response);
		}else{
			return $this->json(['status' => 'failure', 'message' => 'Selected Item Not in Scope']);
		}
	}
	public function CategoriesExportToMagento($id)
	{
		$access_token = $this->getMagentoBearerToken();
		$category_data = DataObject\CategoryOrTaxonomy::getByID($id);
		$category_listing = new DataObject\CategoryOrTaxonomy\Listing();
		$category_name = $category_data->getCategoryName();
		$category_code = $category_data->getCategoryCode();
		$parent_category = $category_data->getParentCategory();

		$category_slug_initial = str_replace(array(',', '&', ' '), '-', $category_name);
		$category_slug_final = preg_replace('/-+/', '-', $category_slug_initial);

		$meg_cat_id = $category_data->getMagentoCategoryId();
		$meg_par_cat_id = $category_data->getMagentoCategoryParentId();
		$category_image_path = $category_data->getCategoryImage();
		$image_meg_path = '';
		if(!empty($category_image_path)){
			$image_url = 'http://pimcore.altiussolution.com' . $category_data->getCategoryImage();
			$imageName = basename($image_url);
			// Download the image
			$imageData = file_get_contents($image_url);

			// Encode the image to Base64
			$encoded_path = base64_encode($imageData);
			$img_upload = array(
				"base64Image" => $encoded_path,
				"imageName" => $imageName
			);
			$endpoint = '/media/MediaUpload';
			$method = 'POST';
			$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,$img_upload);
			if(!empty($api_call)){
				$image_meg_path = $api_call;
			}
			// return ['status' => 'success', 'message' => $api_call];
		}else{
			$image_meg_path = '';
		}
		// return ['status' => 'success', 'message' => $image_url];
		$success = 0;
		$category_data_final = array();
		if(!empty($meg_cat_id)){
			$category_array = array(
				"category" => array(
					  	"id" => $meg_cat_id,
					  	"name" => $category_name,
					  	"is_active" => true,
						// "position": 1,
						// "level": 1,  // Root category level
						// "include_in_menu": true,
					  	"custom_attributes" => array(
						array(
							"attribute_code" => "url_key",
							"value" => $category_slug_final
						),
						array(
							"attribute_code" => "meta_title",
							"value" => $category_name
						),
						array(
							"attribute_code" => "meta_keyword",
							"value" => $category_name
						),
						array(
							"attribute_code" => "image",
							"value" => $image_meg_path
						)
					)
				)
			);
			$category_data_final[] = $category_array;
			$endpoint = '/categories/'.$meg_cat_id;
			$method = 'PUT';
			$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,$category_array);
			if(!empty($api_call->id)){
				$success = 1;
				$category_data->setMagentoCategoryName($api_call->name);
				// $category_data->setMagentoCategoryId($api_call->id);
				$category_data->setMagentoCategoryParentId($api_call->parent_id);
				$category_data->setMagentoLastUpdated(\Carbon\Carbon::now());
				$category_data->setMagentoLastUpdateStatus('Success');
				$category_data->save();
			}
			// return ['status' => 'success', 'message' => $api_call];
		}else{
			$parent_category = $category_data->getParentCategory();
			$parent_data = $category_listing->setCondition("CategoryName = ?", [$parent_category]);
			if(!empty($parent_category) && isset($parent_data) && $parent_data!=''){
				foreach($parent_data as $pcat_key => $pcat_value){
					if($pcat_value->getMagentoCategoryId() !=0 && !empty($pcat_value->getMagentoCategoryId())){
						$category_array = array(
							"category" => array(
								  "id" => null,
								  "parent_id" => $pcat_value->getMagentoCategoryId(),
								  "name" => $category_name,
								  "is_active" => true,
								//   "level" => 2,
								  "custom_attributes" => array(
									array(
										"attribute_code" => "url_key",
										"value" => $category_slug_final
									),
									array(
										"attribute_code" => "meta_title",
										"value" => $category_name
									),
									array(
										"attribute_code" => "meta_keyword",
										"value" => $category_name
									),
									array(
										"attribute_code" => "image",
										"value" => $image_meg_path
									)
								)
							)
						);
						$category_data_final[] = $category_array;
						$endpoint = '/categories';
						$method = 'POST';
						$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,$category_array);
						if(!empty($api_call->id)){
							$success = 1;
							$category_data->setMagentoCategoryName($api_call->name);
							$category_data->setMagentoCategoryId($api_call->id);
							$category_data->setMagentoCategoryParentId($api_call->parent_id);
							$category_data->setMagentoLastUpdated(\Carbon\Carbon::now());
							$category_data->setMagentoLastUpdateStatus('Success');
							$category_data->save();
						}
						
					}else{
						$success = 3;
					}
				}
			}else{
				$category_array = array(
					"category" => array(
					  	"id" => null,
					  	"parent_id" => 0,
					  	"name" => $category_name,
					  	"is_active" => true,
					  	"position" => 1,
					  	"level" => 2,
						"include_in_menu" => true,
					  	"custom_attributes" => array(
							array(
								"attribute_code" => "url_key",
								"value" => $category_slug_final
							),
							array(
								"attribute_code" => "meta_title",
								"value" => $category_name
							),
							array(
								"attribute_code" => "meta_keyword",
								"value" => $category_name
							),
							array(
								"attribute_code" => "image",
								"value" => $image_meg_path
							)
						)
					)
				);
				$category_data_final[] = $category_array;
				$endpoint = '/categories';
				$method = 'POST';
				$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,$category_array);
				if(!empty($api_call->id)){
					$success = 1;
					$category_data->setMagentoCategoryName($api_call->name);
					$category_data->setMagentoCategoryId($api_call->id);
					$category_data->setMagentoCategoryParentId($api_call->parent_id);
					$category_data->setMagentoLastUpdated(\Carbon\Carbon::now());
					$category_data->setMagentoLastUpdateStatus('Success');
					$category_data->save();
				}
				// return ['status' => 'success', 'message' => $api_call];
			}
		}
		if($success == 1){
			return ['status' => 'success', 'message' => 'Category exported to AltiusNxt Magento', 'query' => $category_data_final];
		}elseif($success == 2) {
			$category_data->setMagentoLastUpdated(\Carbon\Carbon::now());
			$category_data->setMagentoLastUpdateStatus('Failed');
			$category_data->save();
			return ['status' => 'failure', 'message' => 'Category already exist in AltiusNxt Magento', 'query' => array()];
		}elseif($success == 3){
			$category_data->setMagentoLastUpdated(\Carbon\Carbon::now());
			$category_data->setMagentoLastUpdateStatus('Failed');
			$category_data->save();
			return ['status' => 'failure', 'message' => 'Parent Category not exist in AltiusNxt Magento', 'query' => array()];
		}else{
			$category_data->setMagentoLastUpdated(\Carbon\Carbon::now());
			$category_data->setMagentoLastUpdateStatus('Failed');
			$category_data->save();
			return ['status' => 'failure', 'message' => 'Category export failed', 'query' => array()];
		}
	}
	public function ProductsExportToMagento($id)
	{
		$access_token = $this->getMagentoBearerToken();
		$products_listing = new DataObject\PlumbingAndSafety\Listing();
		$product_data = DataObject\PlumbingAndSafety::getByID($id);
		$classDefinition = $product_data->getClass();
		$fieldDefinitions = $classDefinition->getFieldDefinitions();
		$description = $product_data->getFeatureCopy() . "\n" . $product_data->getFeatureBullets();
		$attributesArray = [
			[
				'attribute_name' => 'Manufacturer Part Number 1',
				'attribute_code' => 'manufacturer_part_number_1',
				'position' => 2,
				'visible' => !empty($product_data->getMFRPartNumber_1()) ? true : false,
				'variation' => false,
				'attribute_value' => (string) $product_data->getMFRPartNumber_1()
			],
			[
				'attribute_name' => 'Manufacturer Part Number 2',
				'attribute_code' => 'manufacturer_part_number_2',
				'position' => 3,
				'visible' => !empty($product_data->getMFRPartNumber_2()) ? true : false,
				'variation' => false,
				'attribute_value' => (string) $product_data->getMFRPartNumber_2()
			],
			[
				'attribute_name' => 'UPC',
				'attribute_code' => 'upc',
				'position' => 4,
				'visible' => false,
				'variation' => false,
				'attribute_value' =>  (string) $product_data->getUPC()
			],
			[
				'attribute_name' => 'UNSPSC',
				'attribute_code' => 'unspsc',
				'position' => 5,
				'visible' => !empty($product_data->getUNSPSC()) ? true : false,
				'variation' => false,
				'attribute_value' => (string) $product_data->getUNSPSC()
			],
			[
				'attribute_name' => 'Warranty',
				'attribute_code' => 'warranty',
				'position' => 6,
				'visible' => !empty($product_data->getWarranty()) ? true : false,
				'variation' => false,
				'attribute_value' => (string) $product_data->getWarranty()
			],
			[
				'attribute_name' => 'Manufacturer Name',
				'attribute_code' => 'manufacturer_name',
				'position' => 7,
				'visible' => !empty($product_data->getManufacturerName()) ? true : false,
				'variation' => false,
				'attribute_value' => (string) $product_data->getManufacturerName()
			],
			[
				'attribute_name' => 'Description',
				'attribute_code' => 'description',
				'position' => 8,
				'visible' => !empty($description) ? true : false,
				'variation' => false,
				'attribute_value' => $description
			],
			[
				'attribute_name' => 'Short Description',
				'attribute_code' => 'short_description',
				'position' => 9,
				'visible' => !empty($product_data->getShortDescription()) ? true : false,
				'variation' => false,
				'attribute_value' => (string) $product_data->getShortDescription()
			]

		];
		$count = 9;
	

		foreach ($product_data->getTaxonomySpecificAttributes()->getItems() as $groupId => $group) {
		
			foreach ($group as $keyId => $key) {
			
				if ($product_data->getTaxonomySpecificAttributes()->getLocalizedKeyValue($groupId, $keyId) instanceof \Pimcore\Model\DataObject\Data\QuantityValue) {
					$value = (string)$product_data->getTaxonomySpecificAttributes()->getLocalizedKeyValue($groupId, $keyId)->getValue();
				
				} else {
					$value = (string)$product_data->getTaxonomySpecificAttributes()->getLocalizedKeyValue($groupId, $keyId);
				}
	
				if (\Pimcore\Model\DataObject\Classificationstore\KeyConfig::getById($keyId)->getName() == 'Brand') {
					$attribute = [
						'attribute_name' => 'Brand',
						'attribute_code' => 'brand',
						'position' => 1,
						'visible' => !empty($value) ? true : false,
						'variation' => false,
						'attribute_value' => (string) $value
					];
				} else {
					$attribute_slug_initial = str_replace(array(',', '&', ' '), '_', \Pimcore\Model\DataObject\Classificationstore\KeyConfig::getById($keyId)->getName());
					$attribute_slug_final = preg_replace('/-+/', '_', $attribute_slug_initial);
					$attribute_code = strtolower($attribute_slug_final);
					$attribute = [
						'attribute_name' => \Pimcore\Model\DataObject\Classificationstore\KeyConfig::getById($keyId)->getName(),
						'attribute_code' => $attribute_code,
						'position' => $count,
						'visible' => !empty($value) ? true : false,
						'variation' => false,
						'attribute_value' => (string)$value
					];
				}
				
				$count++;
				
				$attributesArray[] = $attribute;
			}
		}
		$image_urls = array();
		$image = [];
		if ($product_data->getProductImage() != null || $product_data->getProductImage() != '') {
			$alt = "";
			if($product_data->getProductImage()->getMetadata("alt") != null){
				$alt = $product_data->getProductImage()->getMetadata("alt");
			}else{
				$alt = "";
			}
			$image_urls[] = 'http://pimcore.altiussolution.com' . $product_data->getProductImage();
			
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
				$image_urls[] = 'http://pimcore.altiussolution.com' . $img->getImage();
				$i++;
			}
		}
		foreach ($image_urls as $pim_images) {
			$imageName = basename($pim_images);
			
			// Download the image
			$imageData = file_get_contents($pim_images);
			
			// Convert WebP to JPEG if the image is WebP
			$ext = pathinfo($imageName, PATHINFO_EXTENSION);
			if (strtolower($ext) === 'webp') {
				// Create an image resource from WebP
				$webpImage = imagecreatefromwebp($pim_images);
				
				// Set the new JPEG image name
				$jpegName = pathinfo($imageName, PATHINFO_FILENAME) . '.jpg';
				$jpegPath = '/var/www/pimcore/public/var/assets/jpgconvert/' . $jpegName; // Update with your save path
		
				// Save as JPEG
				imagejpeg($webpImage, $jpegPath);
				imagedestroy($webpImage); // Free up memory
				
				// Load the JPEG data for base64 encoding
				$imageData = file_get_contents($jpegPath);
				$imageName = $jpegName; // Update the name to the JPEG file
			}
			
			// Encode the image to Base64
			$encoded_path = base64_encode($imageData);
			$img_upload = array(
				"base64Image" => $encoded_path,
				"imageName" => $imageName
			);
		
			$endpoint = '/media/ProductImage';
			$method = 'POST';
			$api_call = $this->MagentoApiCall($endpoint, $method, $access_token, $img_upload);
			
			if (!empty($api_call)) {
				$image_url = $api_call;
				$image_data = file_get_contents($image_url);
				$encoded_image = base64_encode($image_data);
				$path = parse_url($image_url, PHP_URL_PATH);
		
				// Get the filename without the extension
				$image_label = pathinfo($path, PATHINFO_FILENAME);
				$image_name = basename($path);
				$image[] = array(
					"media_type" => "image",
					"label" => $image_label,
					"position" => 1,
					"disabled" => false,
					"types" => ["image", "small_image", "thumbnail"],
					"content" => array(
						"base64_encoded_data" => $encoded_image,
						"type" => "image/jpeg",
						"name" => $image_name
					)
				);
			}
		}
		
		// return ['status' => 'success', 'message' => $image];
		$response = array();
		$attributes_results = array();
		$custom_attributes = array();
		foreach($attributesArray as $attribute_key => $attribute_value){
			$attribute_code = $attribute_value['attribute_code'];
			$endpoint = "/products/attributes/" . $attribute_code;
			$method = 'GET';
			$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,array());
			// return ['status' => 'success', 'message' => $api_call->attribute_code];
			
			if(empty($api_call->attribute_code)){
				$attribute_array = [
					"attribute" => [
						"attribute_code" => $attribute_code, 
						"frontend_input" => "text", 
						"default_frontend_label" => $attribute_value['attribute_name'], 
						"is_required" => false, 
						"is_user_defined" => true, 
						"is_unique" => false, 
						"is_visible" => $attribute_value['visible'], 
						"scope" => true, 
						"frontend_labels" => [
							[
								"store_id" => 0, 
								"label" => $attribute_value['attribute_name']
							] 
						],
						// "attributeSetId" => 17
					] 
				]; 
			
				$endpoint = '/products/attributes';
				$method = 'POST';
				$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,$attribute_array);
				if(!empty($api_call->attribute_code)){
					array_push($attributes_results,$api_call->attribute_code);
				}
			}else{
				array_push($attributes_results,$attribute_code);
			}
			$custom_attributes[] = array(
				'attribute_code' => $attribute_code,
				'value' => $attribute_value['attribute_value']
			);
		}
		$url_key = strtolower(str_replace([' ', '_', '-'], '-', $product_data->getShortDescription())) . '-' . $product_data->getKey();
		$url_key_array = array(
			'attribute_code' => 'url_key',
			'value' => $url_key
		);
		array_push($custom_attributes, $url_key_array);
		$categories_array = array();
		$pim_categories = $product_data->getCategories();
		//return ['status' => 'Success', 'message' => $custom_attributes];
		$category_listing = new DataObject\CategoryOrTaxonomy\Listing();
		$details = $category_listing->setCondition("CategoryName LIKE '%".$pim_categories."%'");
		
		if(!empty($details)){
			foreach($details as $sep_category){
				if($sep_category->getMagentoCategoryId() != 0 || $sep_category->getMagentoCategoryId() != ''){
					$cat_arr = array(
						'category_id' => $sep_category->getMagentoCategoryId(),
						'position' => 1
					);
					array_push($categories_array, $cat_arr);
						
					if($sep_category->getMagentoCategoryParentId() != 0 || $sep_category->getMagentoCategoryParentId() != ''){
						$parent_category_data = $category_listing->setCondition("CategoryName LIKE '%".$sep_category->getParentCategory()."%'");
						if(!empty($parent_category_data)){
							foreach($parent_category_data as $parent_category){
								$cat_arr = array(
									'category_id' => $parent_category->getMagentoCategoryId(),
									'position' => 0
								);
								array_push($categories_array, $cat_arr);
							}
						}
					}
				}
			}
		}

		$attributeSetName = trim($product_data->getCategories()); // Ensure no extra spaces
		$encodedAttributeSetName = str_replace(' ', '', $attributeSetName); // Use rawurlencode to encode spaces as %20
		$encodedAttributeSetName = str_replace([',', '_', '-', '&'], '', $encodedAttributeSetName);
		
		$searchCondition = "searchCriteria[filter_groups][0][filters][0][field]=attribute_set_name&";
		$searchCondition .= "searchCriteria[filter_groups][0][filters][0][value]=" . $encodedAttributeSetName . "&";
		$searchCondition .= "searchCriteria[filter_groups][0][filters][0][condition_type]=eq";
		
		$endpoint = "/eav/attribute-sets/list?" . $searchCondition;
		
		$method = 'GET';
		
		$api_call = $this->MagentoApiCall($endpoint, $method, $access_token, array());
		// return ['status' => 'success', 'message' => $api_call];
		$attribute_set_id = '';

		if(!empty($api_call->items)){
			$attributeSetId =	$api_call->items[0]->attribute_set_id;
			$searchCondition = "searchCriteria[filter_groups][0][filters][0][field]=attribute_set_id&";
			$searchCondition .= "searchCriteria[filter_groups][0][filters][0][value]=".$attributeSetId."&";
			$searchCondition .= "searchCriteria[filter_groups][0][filters][0][condition_type]=eq";
					
			$endpoint = "/products/attribute-sets/groups/list/?" . $searchCondition;
					
			$method = 'GET';
			// check the existence of attribute group
			$group_response = array();
			$group_api_call = $this->MagentoApiCall($endpoint, $method, $access_token, array());
			$group_id = '';
			if(!empty($group_api_call->items)){
				// return ['status' => 'success', 'message' => $group_api_call];
				foreach($group_api_call->items as $group_data){
					

					if($group_data->attribute_group_name != $encodedAttributeSetName){
						$group_create = [
							"group" => [
								"attribute_group_name" => $encodedAttributeSetName,  // Name of the group you want to create
								"attribute_set_id" => $attributeSetId           // ID of the attribute set where you want to create the group
							]
						];
						$endpoint = "/products/attribute-sets/groups";
						$method = 'POST';
						$api_call_groups_create = $this->MagentoApiCall($endpoint, $method, $access_token, $group_create);
						// return ['status' => 'success', 'message' => $api_call_groups_create];
						if(!empty($api_call_groups_create->attribute_group_id)){
							$group_id = $api_call_groups_create->attribute_group_id;
						}
						
					}else{
						$group_id = $group_data->attribute_group_id;
					}
					if(!empty($group_id)){
						foreach($attributes_results as $attribute_codes){
							$datagroup = [
								"attributeSetId" => $attributeSetId,  // Replace with your actual attribute set ID
								"attributeGroupId" => $group_id,  // Replace with your actual attribute group ID
								"attributeCode" => $attribute_codes,
								"sortOrder" => 10  // Adjust sorting order as necessary
							];
							$endpoint = "/products/attribute-sets/attributes";
							$method = 'POST';
							$api_call_attribute_grp_map = $this->MagentoApiCall($endpoint, $method, $access_token, $datagroup);
							$group_response[] = $api_call_attribute_grp_map;
						}
					}
					
					// return ['status' => 'success', 'message' => $group_data->attribute_group_name];
				}
				// return ['status' => 'success', 'message' => $group_api_call->items];
			}else{
				$group_create = [
					"group" => [
						"attribute_group_name" => $encodedAttributeSetName,
						"attribute_set_id" => $attributeSetId
					]
				];
				return ['status' => 'success', 'message' => $group_create];
				$endpoint = "/products/attribute-sets/groups";
				$method = 'POST';
				$api_call_groups_create = $this->MagentoApiCall($endpoint, $method, $access_token, $group_create);
				// error_log(print_r($api_call_groups_create, true));
				return ['status' => 'success', 'message' => $api_call_groups_create];
				if(!empty($api_call_groups_create->attribute_group_id)){
					$group_id = $api_call_groups_create->attribute_group_id;
				}
			}
			$attribute_set_id = $attributeSetId;
			// return ['status' => 'success', 'message' => $group_response];
		}else{
			$attribute_set_create = [
				"attributeSet" => [
					"attribute_set_name" => $encodedAttributeSetName,
					"entity_type_id" => 4,
					"sort_order" => 100
				],
				"skeletonId" => 4
			];
			$endpoint = "/products/attribute-sets";
			$method = 'POST';
			$api_call_attribute_set = $this->MagentoApiCall($endpoint, $method, $access_token, $attribute_set_create);
			if(!empty($api_call_attribute_set->attribute_set_id)){
				$attributeSetId = $api_call_attribute_set->attribute_set_id;
				$searchCondition = "searchCriteria[filter_groups][0][filters][0][field]=attribute_set_id&";
				$searchCondition .= "searchCriteria[filter_groups][0][filters][0][value]=".$attributeSetId."&";
				$searchCondition .= "searchCriteria[filter_groups][0][filters][0][condition_type]=eq";
						
				$endpoint = "/products/attribute-sets/groups/list/?" . $searchCondition;
						
				$method = 'GET';
				// check the existence of attribute group
				$group_response = array();
				$group_api_call = $this->MagentoApiCall($endpoint, $method, $access_token, array());
				if(!empty($group_api_call->items)){
					// return ['status' => 'success', 'message' => $group_api_call];
					foreach($group_api_call->items as $group_data){
						$group_id = '';

						if($group_data->attribute_group_name != $encodedAttributeSetName){
							$group_create = [
								"group" => [
									"attribute_group_name" => $encodedAttributeSetName,  // Name of the group you want to create
									"attribute_set_id" => $attributeSetId           // ID of the attribute set where you want to create the group
								]
							];
							$endpoint = "/products/attribute-sets/groups";
							$method = 'POST';
							$api_call_groups_create = $this->MagentoApiCall($endpoint, $method, $access_token, $group_create);
							// return ['status' => 'success', 'message' => $api_call_groups_create];
							if(!empty($api_call_groups_create->attribute_group_id)){
								$group_id = $api_call_groups_create->attribute_group_id;
							}
							
						}else{
							$group_id = $group_data->attribute_group_id;
						}
						if(!empty($group_id)){
							foreach($attributes_results as $attribute_codes){
								$datagroup = [
									"attributeSetId" => $attributeSetId,  // Replace with your actual attribute set ID
									"attributeGroupId" => $group_id,  // Replace with your actual attribute group ID
									"attributeCode" => $attribute_codes,
									"sortOrder" => 10  // Adjust sorting order as necessary
								];
								$endpoint = "/products/attribute-sets/attributes";
								$method = 'POST';
								$api_call_attribute_grp_map = $this->MagentoApiCall($endpoint, $method, $access_token, $datagroup);
								$group_response[] = $api_call_attribute_grp_map;
							}
						}
						
						// return ['status' => 'success', 'message' => $group_data->attribute_group_name];
					}
					// return ['status' => 'success', 'message' => $group_api_call->items];
					$attribute_set_id = $attributeSetId;
	
				}

			}
			// return ['status' => 'success', 'message' => $api_call];
		}

       // Define the PDF attributes to check
	   $pdf_attributes = [
		'brochure_catalog' => [
			'key' => 'Brochure Catalog',
			'method' => 'getBrochureCatalogLink'
		],
		'specification_sheet' => [
			'key' => 'Specification Sheet',
			'method' => 'getSpecificationSheet'
		],
		'instruction_installation_manua' => [
			'key' => 'Instruction/Installation Manual',
			'method' => 'getInstructionInstallationManualLink'
		],
		'owner_manual' => [
			'key' => 'Owner Manual',
			'method' => 'getOwnerManual'
		],
		'drawing_sheet_line_drawing_par' => [
			'key' => 'Drawing Sheet Line Drawing Parts List',
			'method' => 'getDrawingSheetLineDrawingPartsListLink'
		],
		'msd_ssds_sheet' => [
			'key' => 'MSD/SSDS Sheet',
			'method' => 'getMSDSSDSSheetLink'
		]
	];
	
	// Loop through each PDF attribute and check if the document exists
	foreach ($pdf_attributes as $attribute_code => $attribute) {
		$method = $attribute['method']; // Get the method name to call
		if (!empty($product_data->$method())) {
			$document = $product_data->$method(); // Get the document object
			$pdfAssetId = $document->getId();
			if ($pdfAssetId) {
				$pdfAsset = Document::getById($pdfAssetId); // or Asset::getById depending on type
				if ($pdfAsset) {
					$pdfUrl = 'http://pimcore.altiussolution.com' . $pdfAsset->getFullPath();
					// Add the dynamic attribute
					$custom_attributes[] = [
						'attribute_code' => $attribute_code,
						'value' => $pdfUrl
					];
				}
			}
		}
	}
	
	
		$magento_product_sku = $product_data->getKey();
		            $magento_product_id = $product_data->getMagentoProductId();

		$success_product = array();
		$success = 0;
		  // Check if product SKU exists
		  if (!empty($magento_product_sku)) {
            // Prepare product data array
            $product_array = [
                "product" => [
                    "sku" => $product_data->getKey(), // Product SKU
                    "name" => $product_data->getShortDescription(), // Product name
                    "attribute_set_id" => (int)$attribute_set_id, // Attribute Set ID
                    "price" => 21.99, // Product price
                    "status" => 1, // 1 for enabled, 0 for disabled
                    "visibility" => 4, // 4 = Catalog and Search visibility
                    "type_id" => "simple", // Product type
                    "weight" => 1, // Product weight
                    "extension_attributes" => [
                        "category_links" => $categories_array, // Category associations
                        "stock_item" => [ // Stock and inventory details
                            "qty" => 150,
                            "is_in_stock" => true,
                            "manage_stock" => true,
                            "use_config_min_qty" => true,
                            "min_qty" => 1,
                            "use_config_backorders" => false,
                            "backorders" => 0,
                            "use_config_notify_stock_qty" => true,
                            "notify_stock_qty" => 1,
                            "use_config_qty_increments" => false,
                            "qty_increments" => 1,
                            "use_config_enable_qty_inc" => false,
                            "enable_qty_increments" => false
                        ]
                    ],
                    "custom_attributes" => $custom_attributes, // Custom attributes
                    "media_gallery_entries" => $image // Media (image) entries
                ]
            ];
            
            // Define the Magento API endpoint and method
            $endpoint = '/products/' . $magento_product_sku; // Endpoint to update product
            $method = 'PUT'; // Use PUT method for updates
            
            // Make the API call
            $api_call = $this->MagentoApiCall($endpoint, $method, $access_token, $product_array);
            
            // Handle the response from the API call
            if ($api_call) {
                // Success: Update product status and last updated time
                $product_data->setMagentoLastUpdated(\Carbon\Carbon::now());
                $product_data->setMagentoUpdateStatus('Success');
                $product_data->save();
                $success = 1;
            } else {
                // Failure: Update product status
                $product_data->setMagentoUpdateStatus('Failed');
                $product_data->save();
            }	
        }
		
		
		else{
			$product_array = [
				"product" => [
					"sku" => $product_data->getKey(), 
					"name" => $product_data->getShortDescription(),
					"attribute_set_id" => (int)$attribute_set_id,
					"price" => 21.99,
					"status" => 1,
					"visibility" => 4,
					"type_id" => "simple",
					"weight" => 1,
					"extension_attributes" => [
						"category_links" => $categories_array,
						"stock_item" => [
							"qty" => 150,
							"is_in_stock" => true,
							"manage_stock" => true,
							"use_config_min_qty" => true,
							"min_qty" => 1,
							"use_config_backorders" => false,
							"backorders" => 0,
							"use_config_notify_stock_qty" => true,
							"notify_stock_qty" => 1,
							"use_config_qty_increments" => false,
							"qty_increments" => 1,
							"use_config_enable_qty_inc" => false,
							"enable_qty_increments" => false
						],
						"bundle_product_options" => [],
						"configurable_product_options" => [],
						"downloadable_product_links" => []
					],
					"custom_attributes" => $custom_attributes,
					"media_gallery_entries" => $image
				]
			];
			$endpoint = '/products';
			$method = 'POST';
			$api_call = $this->MagentoApiCall($endpoint,$method,$access_token,$product_array);
			if(!empty($api_call->id)){
				$success_product[] = $api_call;
				$product_data->setMagentoProductId($api_call->id);
				$product_data->setMagentoLastUpdated(\Carbon\Carbon::now());
				$product_data->setMagentoUpdateStatus('Success');
				$product_data->save();
				$success = 1;
			}else{
				$success = 0;
				$success_product[] = $api_call;
			}
		}
		
		if($success == 1){
			return ['status' => 'success', 'message' => 'Product Data Export to Magento Successfull', 'product' => $success_product, 'query' => $product_array];
		}else{
			$product_data->setMagentoLastUpdated(\Carbon\Carbon::now());
			$product_data->setMagentoUpdateStatus('Failed');
			return ['status' => 'failure', 'message' => 'Product Data Export to Magento Failed', 'product' => $success_product, 'query' => $product_array];
		}
	}

	/**
     * Function to get the Magento Bearer Token
     *
     * @return string|null
     */
    private function getMagentoBearerToken(): ?string
    {
        
		$config = DataObject\MagentoConfigurations::getByID(176987);
		$username = '';
		$password = '';
		$api_base_url = '';
		if ($config->getCurrentlyActiveStore() == 'Live') {
			$username = $config->getLiveAdminUsername();
			$password = $config->getLiveAdminPassword();
			$api_base_url = $config->getLiveApiBaseUrl();
		} else {
			$username = $config->getDevAdminUsername();
			$password = $config->getDevAdminPassword();
			$api_base_url = $config->getDevApiBaseUrl();
		}
		
		$tokenUrl = $api_base_url.'/integration/admin/token';

        $tokenData = [
            'username' => $username,
            'password' => $password,
        ];

        $tokenOptions = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($tokenData),
            ],
        ];

        $tokenContext = stream_context_create($tokenOptions);
        $adminToken = json_decode(file_get_contents($tokenUrl, false, $tokenContext));

        return $adminToken ?: null;
    }
	public function MagentoApiCall($endpoint,$method,$access_token,$category_array){
		$config = DataObject\MagentoConfigurations::getByID(176987);
		$api_base_url = '';
		if ($config->getCurrentlyActiveStore() == 'Live') {
			$api_base_url = $config->getLiveApiBaseUrl();
		} else {
			$api_base_url = $config->getDevApiBaseUrl();
		}
		$url = $api_base_url.$endpoint;
		$data = json_encode($category_array);
		$curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token
        ));
        $response = curl_exec($curl);

        curl_close($curl);
		return json_decode($response);
	}
}

