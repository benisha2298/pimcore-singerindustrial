<?php

namespace App\Model\Provider;


use Pimcore\Model\DataObject\ClassDefinition\DynamicOptionsProvider\SelectOptionsProviderInterface;
use \Pimcore\Model\DataObject\ClassDefinition;
use \Pimcore\Model\DataObject\ClassDefinitionListing;
use Pimcore\Model\Listing\AbstractListing;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ShopifyMetafields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Localizedfields;
use Pimcore\Model\DataObject\ClassDefinition\Data\Select;
use Pimcore\Tool;
use Symfony\Contracts\Translation\TranslatorInterface;
// class ClassDefinitionListing extends AbstractListing
// {
//     public function getObjectClass()
//     {
//         return ClassDefinition::class;
//     }
// }

   

class FieldsListoptionsProvider implements SelectOptionsProviderInterface
{
     /**
     * @param array $context
     * @param ClassDefinition\Data $fieldDefinition
     *
     * @return array
     */
     
     
      private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }
    
    
    public function getOptions($context, $fieldDefinition):array {
    //     $methods=[];
       
    //    	$listing = new ClassDefinitionListing();
	
	// $classDefinitions = $listing;

    //     foreach ($classDefinitions as $item) {
           
            
       
    //       	 $methods[] = array("value" =>  'hi', "key" => 'hi' . ' (' . 'hi' . ')',);
    //      }
         
    //     return $methods;
    
    //var_dump($context);
   	$object = null;
        if (isset($context['object'])) {
            $object = $context['object'];
        }

        if (empty($object) || !($object instanceof ShopifyMetafields)) {
        	return [];
        }
        
        
       /** @var Select $fieldDefinition */
        $data           = $fieldDefinition->getOptionsProviderData();
        $onlyFieldNames = false;
        if (!empty($data) && $data === 'onlyFieldNames') {
            $onlyFieldNames = true;
        }

        $result           = [];
        $localizedField   = [];
        
        $dataQualityClassId = $object->getClassFieldsMapping();
        if(!empty($dataQualityClassId)){
        foreach($dataQualityClassId as $clID){
        $class = ClassDefinition::getById($clID->getMapClassname());
        if (!empty($class)) {
           
        
        $fieldDefinitions = $class->getFieldDefinitions();
        foreach ($fieldDefinitions as $name => $field) {
            // bastodo: object Bricks
            // bastodo: field Collections
            // bastodo: blocks
            if ($name === 'localizedfields') {
                $languages = Tool::getValidLanguages();

                /** @var Localizedfields $field */
                $children = $field->getFieldDefinitions();

                foreach ($children as $child) {
                    $title = $this->translator->trans($child->getTitle(), [], 'admin');
                    $value = $child->getName();
                    if (!$onlyFieldNames) {
                        $value .= '@@@' . $title;
                    }
                    $localizedField[] = [
                        'key'   => $title . ' (' . $child->getName() . ') #All',
                        'value' => $value,
                    ];

                    foreach ($languages as $language) {
                        $localizedField[] = [
                            'key'   => $title . ' (' . $child->getName() . ') #' . $language,
                            'value' => $value . '###' . $language,
                        ];
                    }
                }
            } else {
                $title = $this->translator->trans($field->getTitle(), [], 'admin');
                $value = $name;
                if (!$onlyFieldNames) {
                    $value .= '@@@' . $title;
                }
                $result[] = [
                    'key'   => $title . ' (' . $name . ')',
                    'value' => $value,
                ];
            }
        }
        }
        }
        }
	$result = array_map("unserialize", array_unique(array_map("serialize", $result)));
	$localizedField = array_map("unserialize", array_unique(array_map("serialize", $localizedField)));
	return array_merge($result, $localizedField);
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
