<?php

namespace PlasticStudio\SEOAI\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

class SeoAISiteConfigExtension extends Extension
{
    private static $db = [
        'ContextPrompt' => 'Varchar(255)',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldToTab(
            'Root.SEO',
            TextareaField::create('ContextPrompt', _t(__CLASS__ . '.CONTEXTPROMPT', 'Brand Context Prompt'))
				->setDescription(_t(__CLASS__ . '.CONTEXTPROMPTDESCRIPTION', 'Additional information to give AI about your brand / content for more accurate metadata generation')),
            'UseTitleAsMetaTitle'
        );
    }
}
