<?php

namespace PlasticStudio\SEOAI\Services;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Requirements;
use SilverStripe\View\Requirements_Backend;
use SilverStripe\View\SSViewer;
use TractorCow\Fluent\State\FluentState;

class SeoAIContentRenderer {

	use Injectable;
	
	public function render(string $link): string {
		$director = Director::singleton();
		$middlewares = $director->getMiddlewares();
		$requirementsBackend = Requirements::backend();
		$themes = SSViewer::get_themes();

		$director->setMiddlewares([]);
		Requirements::set_backend(Requirements_Backend::create());
		SSViewer::set_themes(Config::inst()->get(SSViewer::class, 'themes'));

		try {
			$response = Director::mockRequest(function (HTTPRequest $request) use ($director) {
				return Versioned::withVersionedMode(function () use ($director, $request) {
					Versioned::set_stage(Versioned::LIVE);

					return $this->withFrontendState(fn() => $director->handleRequest($request));
				});
			}, Director::makeRelative($link), [], null, 'GET');
		} catch (HTTPResponse_Exception $e) {
			$response = $e->getResponse();
		} finally {
			SSViewer::set_themes($themes);
			Requirements::set_backend($requirementsBackend);
			$director->setMiddlewares($middlewares);
		}

		if (!$response instanceof HTTPResponse || $response->getStatusCode() !== 200) {
			$status = $response instanceof HTTPResponse ? $response->getStatusCode() : '-';

			throw new ValidationException('Die Seite konnte nicht gerendert werden (HTTP ' . $status . '): ' . $link);
		}

		$html = (string) $response->getBody();

		if (trim($html) === '') {
			throw new ValidationException('Die Seite hat keinen Inhalt geliefert: ' . $link);
		}

		return $html;
	}

	/**
	 * Fluent normally switches to frontend mode in its middleware, which is skipped here
	 */
	protected function withFrontendState(callable $callback) {
		if (!class_exists(FluentState::class)) {
			return $callback();
		}

		return FluentState::singleton()->withState(function (FluentState $state) use ($callback) {
			$state->setIsFrontend(true);

			return $callback();
		});
	}
}
