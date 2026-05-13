<?php

namespace PlasticStudio\SEOAI\Extensions;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\View\Requirements;

class SeoAIExtension extends Extension {
	public function init() {
		Requirements::css('plasticstudio/silverstripe-seo-ai:client/css/generate-button.css');
		Requirements::javascript('plasticstudio/silverstripe-seo-ai:client/js/seo-ai.js');
	}

	public function updateCMSActions(FieldList $actions) {
		$action = FormAction::create('generateTags', _t(__CLASS__ . '.GENERATE_SEO_TAGS', 'Generate SEO Tags'))
			->addExtraClass('generate-seo-button');

		$actions->push($action);
	}
}