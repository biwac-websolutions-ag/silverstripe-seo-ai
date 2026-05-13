<?php

namespace PlasticStudio\SEOAI\Extensions;

use SilverStripe\Control\PjaxResponseNegotiator;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\View\Requirements;

class SeoAIGridFieldDetailFormExtension extends Extension {
	private static $allowed_actions = [
		'generateObjectSeoTags',
	];

	protected function getToplevelController() {
		$controller = $this->owner->popupController;

		while ($controller instanceof GridFieldDetailForm_ItemRequest) {
			$controller = $controller->getController();
		}

		return $controller;
	}

	public function updateFormActions($actions) {
		$record = $this->owner->record;

		if (!$record || !$record->ID || !$record->hasExtension(SeoDataObjectExtension::class)) {
			return;
		}

		Requirements::css('plasticstudio/silverstripe-seo-ai:client/css/generate-button.css');
		Requirements::javascript('plasticstudio/silverstripe-seo-ai:client/js/seo-ai.js');

		$actions->push(
			FormAction::create('generateObjectSeoTags', _t(__CLASS__ . '.GENERATE_SEO_TAGS', 'Generate SEO Tags'))
				->setUseButtonTag(true)
				->addExtraClass('generate-seo-button btn-primary')
		);
	}

	public function generateObjectSeoTags($data, $form) {
		$record = $this->owner->record;
		$controller = $this->getToplevelController();

		if (!$record || !$record->ID) {
			$form->sessionMessage('Kein gültiger Datensatz gefunden.', 'bad');
			return $this->respondWithForm($form, $controller);
		}

		if (!$record->hasExtension(SeoDataObjectExtension::class)) {
			$form->sessionMessage('SEO AI ist für dieses Objekt nicht aktiviert.', 'bad');
			return $this->respondWithForm($form, $controller);
		}

		try {
			$link = $record->getSeoAIAbsoluteLink();

			if (!$link) {
				throw new ValidationException('Objekt hat keinen gültigen Link');
			}

			$response = $record->promptSeoAI($record->generateSeoAIPromptFromLink($link));
			$metaTags = json_decode($response, true);

			if (!is_array($metaTags)) {
				throw new ValidationException('Ungültige JSON-Antwort von OpenAI');
			}

			$record->updateSeoAIFields($metaTags);
			$form->sessionMessage('SEO-Tags wurden erfolgreich generiert.', 'good');
		} catch (\Exception $e) {
			$form->sessionMessage($e->getMessage(), 'bad');
			return $this->respondWithForm($form, $controller);
		}

		$request = $controller->getRequest();
		$backURL = $request->getHeader('Referer');

		if ($backURL) {
			$backURL = preg_replace('/([?&])openSeo=1(&)?/', '$1', $backURL);
			$backURL = rtrim($backURL, '?&');
			$separator = strpos($backURL, '?') !== false ? '&' : '?';

			return $controller->redirect($backURL . $separator . 'openSeo=1');
		}

		return $controller->redirectBack();
	}

	protected function respondWithForm($form, $controller) {
		$responseNegotiator = new PjaxResponseNegotiator([
			'CurrentForm' => fn() => $form->forTemplate(),
			'default' => fn() => $controller->redirectBack(),
		]);

		if ($controller && $controller->getRequest() && $controller->getRequest()->isAjax()) {
			$controller->getRequest()->addHeader('X-Pjax', 'CurrentForm');
		}

		return $responseNegotiator->respond($controller->getRequest());
	}
}