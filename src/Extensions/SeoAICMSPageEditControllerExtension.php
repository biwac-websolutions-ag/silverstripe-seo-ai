<?php

namespace PlasticStudio\SEOAI\Extensions;

use voku\helper\HtmlDomParser;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\View\Parsers\URLSegmentFilter;
use SilverStripe\View\Requirements;
use SilverStripe\View\Requirements_Backend;
use SilverStripe\View\SSViewer;

class SeoAICMSPageEditControllerExtension extends Extension
{

    public $openaiKey = '';

    public $model = 'gpt-4o-mini';

    public $temperature = 0;

    public $included_dom_selectors = ['h1', 'h2', 'h3', 'h4', 'h5', 'strong', 'p', 'li', 'h6'];

    public $excluded_dom_selectors = ['header', 'footer', 'nav'];

    private static $allowed_actions = [
        'generateTags',
    ];

	public function generateTags()
	{
		$request = $this->owner->getRequest();

		$pageID = (int)(
		$request->requestVar('ID')
			?: $request->getVar('ID')
			?: $request->param('ID')
		);

		if (!$pageID) {
			$referer = $request->getHeader('Referer');

			if ($referer && preg_match('#/show/([0-9]+)#', $referer, $matches)) {
				$pageID = (int)$matches[1];
			}
		}

		$page = $pageID ? SiteTree::get()->byID($pageID) : null;

		if (!$page || !$page->exists()) {
			throw new ValidationException('No page available');
		}

		if ($page->stagesDiffer('Stage', 'Live')) {
			throw new ValidationException('Publish the page first for accurate tag generation');
		}

		$prompt = $this->generatePrompt($page);
		$response = $this->promptAPICall($prompt);
		$this->populateMetaTagsFromAPI($response, $page);

		$backURL = $request->getHeader('Referer');

		if ($backURL) {
			$backURL = preg_replace('/([?&])openSeo=1(&)?/', '$1', $backURL);
			$backURL = rtrim($backURL, '?&');

			$separator = strpos($backURL, '?') !== false ? '&' : '?';

			return $this->owner->redirect($backURL . $separator . 'openSeo=1');
		}

		return $this->owner->redirectBack();
	}

    /**
     * Build an LLM prompt based on brand context and content field
     *
     * @return String
     */
	public function generatePrompt($page)
	{
		// Get brand context
		$brandContext = SiteConfig::current_site_config()->ContextPrompt;

		$originalRequirementsBackend = Requirements::backend();
		$originalThemes = SSViewer::get_themes();

		Requirements::set_backend(Requirements_Backend::create());
		SSViewer::set_themes(Config::inst()->get(SSViewer::class, 'themes'));

		try {
			$response = Director::test(Director::makeRelative($page->Link()), [], null, 'GET');
			$html = $response->getStatusCode() === 200 ? (string) $response->getBody() : '';
		} finally {
			SSViewer::set_themes($originalThemes);
			Requirements::set_backend($originalRequirementsBackend);
		}

		if (trim($html) === '') {
			throw new ValidationException('Der Seiteninhalt konnte nicht gerendert werden: ' . $page->Title);
		}

		$domParser = HtmlDomParser::str_get_html($html);

		if (!$domParser) {
			throw new ValidationException('Der HTML-Inhalt konnte nicht verarbeitet werden: ' . $page->Title);
		}

        $excludedDomElements = $this->excluded_dom_selectors;
        foreach ($excludedDomElements as $element) {
            foreach ($domParser->find($element) as $node) {
                if ($node) {
                    $node->outertext = '';
                }
            }
        }

        $domParser = HtmlDomParser::str_get_html((string) $domParser);

        if (!$domParser) {
            throw new ValidationException('Der HTML-Inhalt konnte nach dem Bereinigen nicht verarbeitet werden: ' . $page->Title);
        }

        $mainNodes = $domParser->find('main');
        $scope = count($mainNodes) ? $mainNodes[0] : $domParser;

        // Find all elements with content tags
        $domContent = [];

        $includedDomElements = $this->included_dom_selectors;
        foreach ($includedDomElements as $element) {
            foreach ($scope->find($element) as $node) {
                if ($node) {
                    $domContent[] = strip_tags(html_entity_decode($node->innertext()));
                }
            }
        }

        // Remove empty items
        $parsedContent = array_filter(array_map('trim', $domContent));

        // Assemble parsed content
        $content = implode(' ', $parsedContent);

        // Create a prompt including the page content
        $prompt = <<<EOT
        Your task is to scan the following content gathered from a web page, and generate the following meta-tags which obey the character limits specified:
        - MetaTitle (60 character limit)
        - MetaDescription (160 character limit)
        
        Language and formatting rules:
        - Use Swiss German grammar and spelling.
        - Never use the German character "ß". Always replace it with "ss".
        - Never use the character "–". Use a normal hyphen "-" or rewrite the sentence without a dash.
        - Return only clean text for the meta tags. Do not use HTML.

        Here is some background information on the brand which the web page belongs to, delimited by ---:

        ---
        $brandContext
        ---

        Here is the content for you to generate meta-tags for, deliniated by ~:

        ~
        $content
        ~

        EOT;

        return $prompt;
    }

    /**
     * Call an LLM API with generated prompt
     *
     * @param String
     *
     * @return String
     */
    public function promptAPICall($prompt)
    {
        $key = $this->openaiKey;
        $url = 'https://api.openai.com/v1/chat/completions';
        $data = [
            "model" => $this->model,
            "temperature" => $this->temperature,
            "messages" => [
                [
                    "role" => "user",
                    "content" => $prompt
                ]
            ],
            "response_format" => [
                "type" => "json_schema",
                "json_schema" => [
                    "name" => "metadata",
                    "schema" => [
                        "type" => "object",
                        "properties" => [
                            "metaTitle" => ["type" => "string"],
                            "metaDescription" => ["type" => "string"]
                        ],
                        "required" => ["metaTitle", "metaDescription"],
                        "additionalProperties" => false
                    ],
                    "strict" => true
                ]
            ]
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $key,
            "Content-length: " . strlen(json_encode($data))
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);

        $data = json_decode($response, true);

        return $data["choices"][0]["message"]["content"];
    }

    /**
     * Populate the page's meta tags with AI generated content
     * @param Array
     *
     * @return Boolean
     */
    public function populateMetaTagsFromAPI($response, $page)
    {
        $metaTags = json_decode($response, true);

        if ($metaTags) {

            $page->MetaTitle = $metaTags["metaTitle"] ?? '';
			$page->MetaTitle = str_replace(['ß', '–'], ['ss', '-'], $page->MetaTitle);

			if ($page->Title) {
				$filter = URLSegmentFilter::create();
				$page->URLSegment = $filter->filter($page->Title);
			}

            // check if the meta description exceeds 160 characters and truncate if necessary
            $metaDescription = $metaTags['metaDescription'] ?? '';
			$metaDescription = str_replace(['ß', '–'], ['ss', '-'], $metaDescription);
            $metaDescription = mb_substr($metaDescription, 0, 160); // Limit to 160 characters
            $metaDescription = rtrim($metaDescription);
            $page->MetaDescription = $metaDescription;

            $page->GenerateTags = false;
            $page->write();

            return true;
        }

        return false;
    }
}
