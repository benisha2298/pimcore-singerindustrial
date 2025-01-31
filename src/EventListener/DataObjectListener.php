<?php

namespace App\EventListener;

use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\Asset;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DataObjectListener
{
    public function onPreGetData(DataObjectEvent $event)
    {
        $object = $event->getObject();
        if ($object->getClassName() === 'ExportTemplate') {
            $fields = $object->getFieldcollections("FieldCollectionName"); // Replace with your FieldCollection name

            foreach ($fields as $field) {
                if ($field->getInputFile()) { // Assuming InputFile is a Many-to-One Relation
                    $asset = Asset::getById($field->getInputFile()->getId());
                    if ($asset && $asset->getMimetype() === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                        $filePath = $asset->getFileSystemPath();
                        $spreadsheet = IOFactory::load($filePath);
                        $worksheet = $spreadsheet->getActiveSheet();

                        $headers = [];
                        foreach ($worksheet->getColumnIterator() as $column) {
                            $cell = $worksheet->getCell($column->getColumnIndex() . '1');
                            $headers[] = $cell->getValue();
                        }

                        $field->setExternalChannelFieldNameOptions($headers); // Assuming the dropdown has a setOptions method
                    }
                }
            }
        }
    }
}
