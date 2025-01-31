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

class BulkProductCategoriesExportController extends FrontendController
{

	/**
	 * @param Request $request
	 * @return Response
	*/
	public function defaultAction(Request $request, ApplicationLogger $logger): Response
	{
        $id = $request->get('id');
			$success = 0;
			$config = DataObject\WoocommerceConfigurations::getByID(25101);
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
	
			$woo_cat_id = $category_data->getWoocommerceCategoryId();
			$woo_par_cat_id = $category_data->getWoocommerceCategoryParentId();
			$woo_cat_slug = $category_data->getWoocommerceCategorySlug();
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
				$woo_id = (int)$category_data->getWoocommerceCategoryId();
				$get_parent = $category_data->getWoocommerceCategoryParentId();
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
					$category_data->setLastUpdated(\Carbon\Carbon::now());
					$category_data->setLastWoocommerceStatus('Success');
					$category_data->save();
				}
			}else{
				$parent_category = $category_data->getParentCategory();
				$parent_data = $category_listing->setCondition("CategoryName = ?", [$parent_category]);
				if(!empty($parent_category) && isset($parent_data) && $parent_data!=''){
					foreach($parent_data as $pcat_key => $pcat_value){
						
						if($pcat_value->getWoocommerceCategoryId() !=0 && !empty($pcat_value->getWoocommerceCategoryId())){
							$category_array = array(
								"name" => $category_name,
								"slug" => $category_slug_final,
								"parent" => (int)$pcat_value->getWoocommerceCategoryId(),
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
							$parent_parent_cat = $pcat_value->getParentCategory();
							$parent_parent_data = $category_listing->setCondition("CategoryName = ?", [$parent_parent_cat]);
							if(!empty($parent_parent_data) && isset($parent_parent_data) && $parent_parent_data!=''){
								foreach($parent_parent_data as $ppcat_key => $ppcat_value){
									if($ppcat_value->getWoocommerceCategoryId() !=0 && !empty($ppcat_value->getWoocommerceCategoryId())){
										$category_slug_initial = str_replace(array(',', '&', ' '), '-', $ppcat_value->getCategoryName());
										$category_slug_final = preg_replace('/-+/', '-', $category_slug_initial);
										if(!empty($category_image_path)){
											$img = array(
												"src"=> 'http://pimcore.altiussolution.com' . $ppcat_value->getCategoryImage(),
												"name"=> "",
												"alt"=> ""
											);
										}
										$category_array = array(
											"name" => $ppcat_value->getCategoryName(),
											"slug" => $category_slug_final,
											"parent" => (int)$ppcat_value->getWoocommerceCategoryId(),
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
									}
								}
							}
							$success = 3;
						}
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
						$category_data->setWoocommerceCategoryName($woocommerce_data->name);
						$category_data->setWoocommerceCategoryId($woocommerce_data->id);
						$category_data->setWoocommerceCategorySlug($woocommerce_data->slug);
						$category_data->setWoocommerceCategoryParentId(0);
						$category_data->setLastUpdated(\Carbon\Carbon::now());
						$category_data->setLastWoocommerceStatus('Success');
						$category_data->save();
					}
	
				}
				
			}
			
			if($success == 1){
				return $this->json(['status' => 'success', 'message' => 'Category exported to AltiusNxt woocommerce', 'query' => $category_data_final]);
			}elseif($success == 2) {
				$category_data->setLastUpdated(\Carbon\Carbon::now());
				$category_data->setLastWoocommerceStatus('Failed');
				$category_data->save();
				return $this->json(['status' => 'failure', 'message' => 'Category already exist in AltiusNxt woocommerce', 'query' => array()]);
			}elseif($success == 3){
				$category_data->setLastUpdated(\Carbon\Carbon::now());
				$category_data->setLastWoocommerceStatus('Failed');
				$category_data->save();
				return $this->json(['status' => 'failure', 'message' => 'Parent Category not exist in AltiusNxt woocommerce', 'query' => array()]);
			}else{
				$category_data->setLastUpdated(\Carbon\Carbon::now());
				$category_data->setLastWoocommerceStatus('Failed');
				$category_data->save();
				return $this->json(['status' => 'failure', 'message' => 'Category export failed', 'query' => array()]);
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
