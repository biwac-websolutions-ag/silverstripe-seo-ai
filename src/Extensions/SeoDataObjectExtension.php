<?php

namespace PlasticStudio\SEOAI\Extensions;

use Page;
use PlasticStudio\SEO\Admin\SEOAdmin;
use PlasticStudio\SEO\Forms\MetaPreviewField;
use PlasticStudio\SEO\Model\Extension\SeoPageExtension;
use PlasticStudio\SEO\Schema\Builder\SchemaBuilder;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\Requirements;
use voku\helper\HtmlDomParser;
use PlasticStudio\SEOAI\Services\SeoAIContentRenderer;

class SeoDataObjectExtension extends SeoPageExtension {

	public $openaiKey = '';

	public $model = 'gpt-4o-mini';

	public $temperature = 0;

	public $included_dom_selectors = ['h1', 'h2', 'h3', 'h4', 'h5', 'strong', 'p', 'li', 'h6'];

	public $excluded_dom_selectors = ['header', 'footer', 'nav'];

	public function updateCMSFields(FieldList $fields) {
		$fields->removeByName([
			'MetaKeywords',
			'MetaTitle',
			'MetaDescription',
			'SocialImage',
			'MetaTitleLastEdited',
			'MetaDescriptionLastEdited',
			'Canonical',
			'Robots',
			'ManualSchema',
			'Priority',
			'ChangeFrequency',
			'SitemapHide',
			'XMLSitemapHide',
			'HideSocial',
			'OGtype',
			'OGlocale',
			'TwitterCard',
			'SitemapImages',
			'HeadTags',
		]);

		if ($urlSegmentField = $fields->dataFieldByName('URLSegment')) {
			$fields->removeByName('URLSegment');
			$fields->insertAfter('Title', $urlSegmentField);
		}

		if (!$this->owner->MetaTitle || !$this->owner->MetaDescription) {
			$fields->addFieldToTab(
				'Root.Main',
				LiteralField::create(
					'SEONotice',
					'<p class="message warning">' . _t(__CLASS__ . '.SEO_NOTICE', 'Attention required: Please complete the SEO fields below.') . '</p>'
				)
			);
		}

		$title = TextField::create('MetaTitle', _t(__CLASS__ . '.META_TITLE', 'Meta title'));

		if (!$this->owner->MetaTitle) {
			$fallbackTitle = $this->owner->hasField('Title') ? $this->owner->Title : '';

			$title->setDescription(
				_t(__CLASS__ . '.META_TITLE_DESCRIPTION', 'Enter a unique and include the target keyword for page ranking. Max 60 characters.')
				. ($fallbackTitle ? '<br /><p class="message warning">' .
					sprintf(
						_t(__CLASS__ . '.META_TITLE_EMPTY_WARNING', 'The meta title is empty. The title (%s) will be used if not set.'),
						Convert::raw2xml($fallbackTitle)
					) . '</p>' : '')
			);
		} else {
			$title->setDescription(
				'<i>' .
				sprintf(
					_t(__CLASS__ . '.LAST_EDITED', 'Last edited: %s'),
					$this->owner->dbObject('MetaTitleLastEdited')->Nice()
				) . '</i><br/><br/>'
				. _t(__CLASS__ . '.META_TITLE_DESCRIPTION', 'Enter a unique and include the target keyword for page ranking. Max 60 characters.')
			);
		}

		$description = TextareaField::create('MetaDescription', _t(__CLASS__ . '.META_DESCRIPTION', 'Meta description'));

		if (!$this->owner->MetaDescription) {
			$description->setDescription(
				_t(__CLASS__ . '.META_DESCRIPTION_DESCRIPTION', 'Enter a concise summary of the page content. Max 160 characters.')
				. '<br /><p class="message warning">' . _t(__CLASS__ . '.META_DESCRIPTION_EMPTY_WARNING', 'The meta description should not be left empty.') . '</p>'
			);
		} else {
			$description->setDescription(
				'<i>' .
				sprintf(
					_t(__CLASS__ . '.LAST_EDITED', 'Last edited: %s'),
					$this->owner->dbObject('MetaDescriptionLastEdited')->Nice()
				) . '</i><br/><br/>'
				. _t(__CLASS__ . '.META_DESCRIPTION_DESCRIPTION', 'Enter a concise summary of the page content. Max 160 characters.')
			);
		}

		$seoFields = [];

		if (method_exists($this->owner, 'Link') || method_exists($this->owner, 'AbsoluteLink')) {
			$seoFields[] = MetaPreviewField::create($this->owner);
		}

		$seoFields[] = $title;
		$seoFields[] = $description;

		$seoToggle = ToggleCompositeField::create(
			'SEO',
			_t(__CLASS__ . '.SEO_SETTINGS', 'SEO Settings'),
			$seoFields
		);

		if ($fields->dataFieldByName('URLSegment')) {
			$fields->insertAfter('URLSegment', $seoToggle);
		} else {
			$fields->addFieldToTab('Root.Main', $seoToggle);
		}

		$mainTab = $fields->fieldByName('Root.Main');

		if ($mainTab && $mainTab->Fields()) {
			foreach ($mainTab->Fields() as $field) {
				if ($field instanceof ToggleCompositeField && in_array($field->Title(), ['Metadata', 'Metadaten'], true)) {
					$mainTab->Fields()->removeByName($field->getName());
					break;
				}
			}
		}
	}

	public function updateSettingsFields(FieldList $fields) {
		$fields->removeByName([
			'HeadTags',
			'SitemapImages',
			'Canonical',
			'Robots',
			'ManualSchema',
			'Priority',
			'ChangeFrequency',
			'SitemapHide',
			'XMLSitemapHide',
			'HideSocial',
			'OGtype',
			'OGlocale',
			'TwitterCard',
		]);

		return $fields;
	}

	public function getPageURL() {
		if (method_exists($this->owner, 'AbsoluteLink')) {
			return $this->owner->AbsoluteLink();
		}

		if (method_exists($this->owner, 'Link')) {
			return Director::absoluteURL($this->owner->Link());
		}

		if ($this->owner->hasField('URLSegment')) {
			return rtrim(Director::absoluteBaseURL(), '/') . '/' . ltrim($this->owner->URLSegment, '/');
		}

		return Director::absoluteBaseURL();
	}

	public function getPageCanonical($query = null) {
		if ($this->owner->Canonical) {
			return $this->owner->Canonical . $query;
		}

		return $this->getPageURL() . $query;
	}

	public function getPublishedIcon() {
		if ($this->owner instanceof Page) {
			$status = $this->owner->isPublished() ? 'accept' : 'delete';
		} elseif (method_exists($this->owner, 'isPublished')) {
			$status = $this->owner->isPublished() ? 'accept' : 'delete';
		} else {
			$status = 'accept';
		}

		return DBField::create_field(
			'HTMLText',
			sprintf('<span class="ui-button-icon-primary ui-icon btn-icon-%s"></span>', $status)
		);
	}

	public function getCMSPageEditLink() {
		$controller = Controller::curr();

		if ($controller && method_exists($controller, 'CMSEditLink')) {
			return $controller->CMSEditLink();
		}

		return null;
	}

	public function ApplySchema() {
		if ($this->owner->ManualSchema) {
			Requirements::insertHeadTags(sprintf(
				"<script type='application/ld+json'>%s</script>",
				json_encode($this->owner->ManualSchema)
			));
		}

		$schemas = array_filter((array)$this->owner->config()->get('active_schema'));

		foreach ($schemas as $schema) {
			if (class_exists($schema) && new $schema() instanceof SchemaBuilder) {
				$schemaObject = new $schema();

				if ($data = $schemaObject->getSchema($this->owner)) {
					Requirements::insertHeadTags(sprintf(
						"<script type='application/ld+json'>%s</script>",
						json_encode($data)
					), get_class($schemaObject));
				}
			}
		}
	}

	public function updateSummaryFields(&$fields) {
		if (Controller::curr() instanceof SEOAdmin) {
			Config::modify()->set($this->owner->ClassName, 'summary_fields', $this->getSummaryFields());
			$fields = Config::inst()->get($this->owner->ClassName, 'summary_fields');
		}
	}

	public function getSeoAIAbsoluteLink(): ?string {
		if (method_exists($this->owner, 'AbsoluteLink')) {
			return $this->owner->AbsoluteLink();
		}

		if (method_exists($this->owner, 'Link')) {
			return Director::absoluteURL($this->owner->Link());
		}

		return null;
	}

	public function updateSeoAIFields(array $metaTags): void {
		if (!$this->owner->hasField('MetaTitle') || !$this->owner->hasField('MetaDescription')) {
			throw new ValidationException('MetaTitle oder MetaDescription fehlen auf dem Objekt');
		}

		$this->owner->MetaTitle = str_replace(['ß', '–'], ['ss', '-'], $metaTags['metaTitle'] ?? '');

		$this->owner->MetaDescription = rtrim(mb_substr(
			str_replace(['ß', '–'], ['ss', '-'], $metaTags['metaDescription'] ?? ''),
			0,
			160
		));

		$this->owner->write();
	}

	public function generateSeoAIPromptFromLink(string $link): string {
		$brandContext = SiteConfig::current_site_config()->ContextPrompt ?? '';

		$html = SeoAIContentRenderer::create()->render($link);

		$domParser = HtmlDomParser::str_get_html($html);

		if (!$domParser) {
			throw new ValidationException('HTML konnte nicht geparst werden');
		}

		foreach ($this->excluded_dom_selectors as $element) {
			foreach ($domParser->find($element) as $node) {
				if ($node) {
					$node->outertext = '';
				}
			}
		}

		$domParser = HtmlDomParser::str_get_html((string) $domParser);

		if (!$domParser) {
			throw new ValidationException('HTML konnte nach dem Bereinigen nicht geparst werden');
		}

		$mainNodes = $domParser->find('main');
		$scope = count($mainNodes) ? $mainNodes[0] : $domParser;

		$domContent = [];

		foreach ($this->included_dom_selectors as $element) {
			foreach ($scope->find($element) as $node) {
				if ($node) {
					$domContent[] = strip_tags(html_entity_decode($node->innertext()));
				}
			}
		}

		$content = implode(' ', array_filter(array_map('trim', $domContent)));

		return <<<EOT
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
		
		Here is the content for you to generate meta-tags for, delimited by ~:

		~
		$content
		~
		EOT;
	}

	public function promptSeoAI(string $prompt): string {
		if (empty($this->openaiKey)) {
			throw new ValidationException('OpenAI API key fehlt');
		}

		$payload = [
			'model' => $this->model,
			'temperature' => $this->temperature,
			'messages' => [
				[
					'role' => 'user',
					'content' => $prompt,
				],
			],
			'response_format' => [
				'type' => 'json_schema',
				'json_schema' => [
					'name' => 'metadata',
					'schema' => [
						'type' => 'object',
						'properties' => [
							'metaTitle' => ['type' => 'string'],
							'metaDescription' => ['type' => 'string'],
						],
						'required' => ['metaTitle', 'metaDescription'],
						'additionalProperties' => false,
					],
					'strict' => true,
				],
			],
		];

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->openaiKey,
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

		$response = curl_exec($ch);

		if ($response === false) {
			throw new ValidationException('OpenAI Request fehlgeschlagen: ' . curl_error($ch));
		}

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$data = json_decode($response, true);

		if (!is_array($data)) {
			throw new ValidationException('Ungültige API-Antwort');
		}

		if ($httpCode >= 400) {
			throw new ValidationException('OpenAI API error: ' . ($data['error']['message'] ?? 'Unbekannter API-Fehler'));
		}

		if (!isset($data['choices'][0]['message']['content'])) {
			throw new ValidationException('OpenAI-Antwort enthält kein choices/message/content');
		}

		return $data['choices'][0]['message']['content'];
	}

}