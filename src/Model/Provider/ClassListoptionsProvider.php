<?php

namespace App\Model\Provider;


use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;
use \Pimcore\Model\DataObject\ClassDefinition;
use \Pimcore\Model\DataObject\ClassDefinitionListing;
use Pimcore\Model\Listing\AbstractListing;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ShopifyMetafields;

// class ClassDefinitionListing extends AbstractListing
// {
//     public function getObjectClass()
//     {
//         return ClassDefinition::class;
//     }
// }

class ClassListoptionsProvider implements SelectOptionsProviderInterface
{
     /**
     * @param array $context
     * @param ClassDefinition\Data $fieldDefinition
     *
     * @return array
     */
    public function getOptions($context, $fieldDefinition):array {
    //     $methods=[];
       
    //    	$listing = new ClassDefinitionListing();
	
	// $classDefinitions = $listing;

    //     foreach ($classDefinitions as $item) {
           
            
       
    //       	 $methods[] = array("value" =>  'hi', "key" => 'hi' . ' (' . 'hi' . ')',);
    //      }
         
    //     return $methods;
   	$object = null;
        if (isset($context['object'])) {
            $object = $context['object'];
        }

        if (empty($object) || !($object instanceof ShopifyMetafields)) {
            return [];
        }
        $result              = [];
        $classDefinitionList = (new ClassDefinition\Listing())->getClasses();

        foreach ($classDefinitionList as $item) {

            $result[] = [
                'key'   => $item->getName() . ' (' . $item->getId() . ')',
                'value' => $item->getId()
            ];
        }

        return $result;
    }

   
    /**
     * Returns the value which is defined in the 'Default value' field  
     * @param array $context 
     * @param Data $fieldDefinition 
     * @return mixed
     */
    public function getDefaultValue($context, $fieldDefinition) {
        return $fieldDefinition->getDefaultValue();
    }

    /**
     * @param array $context 
     * @param Data $fieldDefinition 
     * @return bool
     */
    public function hasStaticOptions($context, $fieldDefinition) {
        return true;
    }

}
