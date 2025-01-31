<?php
namespace App;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;

class AppBundle extends AbstractPimcoreBundle
{
    /**
     * Register custom JavaScript files for the admin panel.
     */
    public function getJsPaths(): array
    {
        return [
            '/js/export-template-dropdown.js', // Path to your custom JS file
        ];
    }
}
