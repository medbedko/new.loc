<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use \Bitrix\Landing\Site;
use \Bitrix\Landing\Landing;
use \Bitrix\Landing\Domain;
use \Bitrix\Landing\Manager;
use \Bitrix\Landing\Syspage;
use \Bitrix\Landing\Demos;
use \Bitrix\Landing\Template;
use \Bitrix\Landing\TemplateRef;
use \Bitrix\Landing\Hook\Page\Settings;
use \Bitrix\Highloadblock;
use \Bitrix\Main\Localization\Loc;
use \Bitrix\Main\Web\HttpClient;
use \Bitrix\Main\Web\Json;
use \Bitrix\Main\Loader;
use \Bitrix\Main\Config\Option;
use Bitrix\Iblock;
use Bitrix\Main;

\CBitrixComponent::includeComponentClass('bitrix:landing.base');

class LandingSiteDemoComponent extends LandingBaseComponent
{
	/**
	 * Tag for managed cache.
	 */
	const DEMO_TAG = 'landing_demo';

	/**
	 * Path with demo templates data for site.
	 */
	const DEMO_DIR_SITE = 'site';

	/**
	 * Path with demo templates data for page.
	 */
	const DEMO_DIR_PAGE = 'page';

	const STEP_STATUS_ERROR = 'ERROR';
	const STEP_STATUS_CONTINUE = 'CONTINUE';
	const STEP_STATUS_COMPLETE = 'COMPLETE';

	const STEP_ID_HIGHLOADBLOCK = 'CREATE_HIGHLOADBLOCK';
	const STEP_ID_PREPARE_CRM_CATALOG = 'CRM_CATALOG';
	const STEP_ID_XML_IMPORT = 'XML_IMPORT';
	const STEP_ID_ADDITIONAL_UPDATE = 'ADDITIONAL';
	const STEP_ID_REINDEX = 'REINDEX';
	const STEP_ID_CATALOG_REINDEX = 'CATALOG_REINDEX';
	const STEP_ID_FINAL = 'FINAL';

	private $catalogStepList = [
		self::STEP_ID_HIGHLOADBLOCK,
		self::STEP_ID_PREPARE_CRM_CATALOG,
		self::STEP_ID_XML_IMPORT,
		self::STEP_ID_ADDITIONAL_UPDATE,
		self::STEP_ID_CATALOG_REINDEX,
		self::STEP_ID_FINAL
	];

	/**
	 * Relative url for new site.
	 * @var string
	 */
	protected $urlTpl = '/#rand#/';

	/**
	 * Landing old ids for version 3.
	 * @var array
	 */
	protected $oldIds = [];

	/**
	 * Redirect to the landing.
	 * @param int $landingId Landing id.
	 * @return boolean If error.
	 */
	protected function redirectToLanding($landingId)
	{
		$landing = Landing::createInstance($landingId);
		if ($landing->exist())
		{
			$siteId = $landing->getSiteId();
			$redirect = str_replace(
				array('#site_show#', '#landing_edit#'),
				array($siteId, $landingId),
				$this->arParams['PAGE_URL_LANDING_VIEW']
			);
			$redirect .= (strpos($redirect, '?') === false ? '?' : '&')
						. 'IFRAME=N';
			\localRedirect($redirect, true);
		}
		else
		{
			$this->setErrors($landing->getError()->getErrors());
			return false;
		}
		return true;
	}

	/**
	 * Prepare array of additional data.
	 * @param array $data Data item array.
	 * @return array
	 */
	protected function prepareAdditionalFields($data)
	{
		if (
			!isset($data['ADDITIONAL_FIELDS']) ||
			!is_array($data['ADDITIONAL_FIELDS'])
		)
		{
			$data['ADDITIONAL_FIELDS'] = array();
		}
//		Rewrite only if theme set. If have not theme - it means it page with "get theme from site" option
		if (
			$this->request('theme') &&
			isset($data['ADDITIONAL_FIELDS']['THEME_CODE'])
		)
		{
			$data['ADDITIONAL_FIELDS']['THEME_CODE'] = $this->request('theme');
			$data['ADDITIONAL_FIELDS']['THEME_CODE_TYPO'] = $this->request('theme');
		}
		return $data;
	}

	/**
	 * Get template manifest.
	 * @param int $id Template id.
	 * @return array
	 */
	protected function getTemplateManifest($id)
	{
		$res = Demos::getList(array(
			'select' => array(
				'MANIFEST'
			),
			'filter' => array(
				'ID' => $id
			)
			));
		if ($row = $res->fetch())
		{
			return unserialize($row['MANIFEST']);
		}

		return array();
	}

	/**
	 * Create one page in site.
	 * @param int $siteId Site id.
	 * @param string $code Page code.
	 * @return boolean|int Id of new landing.
	 */
	protected function createPage($siteId, $code)
	{
		$demo = $this->getDemoPage($code);

		if (isset($demo[$code]))
		{
			// get from rest
			if ($demo[$code]['REST'] > 0)
			{
				$demo[$code]['DATA'] = $this->getTemplateManifest(
					$demo[$code]['REST']
				);
			}
			$data = $demo[$code]['DATA'];
			$pageData = $data['fields'];
			$pageData = $this->prepareAdditionalFields($pageData);
			$pageData['SITE_ID'] = $siteId;
			$pageData['ACTIVE'] = 'N';
			$pageData['PUBLIC'] = 'N';
			$pageData['TPL_CODE'] = $code;
			$pageData['XML_ID'] = $data['name'] . '|' . $code;
			//$pageData['DATE_PUBLIC'] = new \Bitrix\Main\Type\DateTime;
			if ($this->request($this->arParams['ACTION_FOLDER']))
			{
				$pageData['FOLDER_ID'] = $this->request(
					$this->arParams['ACTION_FOLDER']
				);
			}
			Landing::setEditMode();
			$res = Landing::add($pageData);
			// and fill each page with blocks
			if ($res->isSuccess())
			{
				$landingId = $res->getId();
				$this->oldIds[$data['old_id']] = $landingId;
				$landing = Landing::createInstance($landingId);
				if ($landing->exist())
				{
					$sort = 0;
					$blocks = array();
					$blocksIds = array();
					$blocksCodes = array();
					foreach ($data['items'] as $k => $block)
					{
						if (is_array($block))
						{
							if ($data['version'] >= 2)
							{
								// support rest blocks
								if (
									isset($block['repo_block']['app_code']) &&
									isset($block['repo_block']['xml_id'])
								)
								{
									$repoBlock = \Bitrix\Landing\Repo::getList(array(
										'select' => array(
											'ID'
										),
										'filter' => array(
											'=APP_CODE' => $block['repo_block']['app_code'],
											'=XML_ID' => $block['repo_block']['xml_id']
										)
									))->fetch();
									if ($repoBlock)
									{
										$block['code'] = 'repo_' . $repoBlock['ID'];
									}
								}
								if (!isset($block['code']))
								{
									continue;
								}
								$blocksCodes[$k] = $block['code'];
								$blockId = $landing->addBlock(
									$block['code'],
									array(
										'PUBLIC' => 'N',
										'SORT' => $sort,
										'ANCHOR' => isset($block['anchor'])
													? $block['anchor']
													: ''
									)
								);
								$blocksIds[$block['old_id']] = $blockId;
								$sort += 500;
								$blocks[$blockId] = $k;
							}
							else
							{
								$block['PUBLIC'] = 'N';
								$blockId = $landing->addBlock(
									isset($block['CODE']) ? $block['CODE'] : $k,
									$block
								);
								$blocks[$k] = $blockId;
							}
						}
						else
						{
							$blockId = $landing->addBlock($block, array(
								'PUBLIC' => 'N',
							));
							$blocks[$block] = $blockId;
						}
					}
					$blockReplace = [];
					foreach ($blocksIds as $oldId => $newId)
					{
						$blockReplace['\'#block' . $oldId . '\''] = '\'#block' . $newId . '\'';
						$blockReplace['"#block' . $oldId . '"'] = '"#block' . $newId . '"';
					}
					// redefine content of blocks
					foreach ($landing->getBlocks() as $k => $block)
					{
						if (!$block->getManifest())
						{
							continue;
						}
						$updated = false;
						if ($data['version'] == 3)
						{
							if (isset($data['items'][$blocks[$k]]))
							{
								$newData = $data['items'][$blocks[$k]];
								// update cards
								if (isset($newData['cards']) && is_array($newData['cards']))
								{
									$updated = true;
									$block->updateCards(
										$newData['cards']
									);
								}
								// update style
								if (isset($newData['style']) && is_array($newData['style']))
								{
									$updatedStyles = [];
									foreach ($newData['style'] as $selector => $classes)
									{
										if ($selector == '#wrapper')
										{
											$selector = '#' . $block->getAnchor($block->getId());
										}
										$updated = true;
										foreach ((array)$classes as $clPos => $clVal)
										{
											$selectorUpd = $selector . '@' . $clPos;
											if (!in_array($selectorUpd, $updatedStyles))
											{
												$updatedStyles[] = $selectorUpd;
												$block->setClasses(array(
													$selectorUpd => array(
														'classList' => (array)$clVal
													)
												));
											}
										}
									}
								}
								// update nodes
								if (isset($newData['nodes']) && !empty($newData['nodes']))
								{
									$updated = true;
									$block->updateNodes($newData['nodes']);
								}
								// update attrs
								if (isset($newData['attrs']) && !empty($newData['attrs']))
								{
									$updated = true;
									if (isset($newData['attrs']['#wrapper']))
									{
										$newData['attrs']['#' . $block->getAnchor($block->getId())] = $newData['attrs']['#wrapper'];
										unset($newData['attrs']['#wrapper']);
									}
									$block->setAttributes($newData['attrs']);
								}
							}
						}
						else if ($data['version'] == 2)
						{
							if (isset($data['items'][$blocks[$k]]))
							{
								$newData = $data['items'][$blocks[$k]];
								// adjust cards
								if (isset($newData['cards']) && is_array($newData['cards']))
								{
									foreach ($newData['cards'] as $selector => $count)
									{
										$changed = false;
										$block->adjustCards($selector, $count, $changed);
										if ($changed)
										{
											$updated = true;
										}
									}
								}
								// update style
								if (isset($newData['style']) && is_array($newData['style']) && !empty($newData['style']))
								{
									foreach ($newData['style'] as $selector => $classes)
									{
										if ($selector == '#wrapper')
										{
											$selector = '#' . $block->getAnchor($block->getId());
										}
										$updated = true;
										$block->setClasses(array(
									   		$selector => array(
							   					'classList' => $classes
									   		)
									 	));
									}
								}
								// update nodes
								if (isset($newData['nodes']) && !empty($newData['nodes']))
								{
									$updated = true;
									$block->updateNodes($newData['nodes']);
								}
								// update attrs
								if (isset($newData['attrs']) && !empty($newData['attrs']))
								{
									$updated = true;
									$block->setAttributes($newData['attrs']);
								}
							}
						}
						// replace links and some content
						$content = $block->getContent();
						foreach ($blocks as $blockCode => $blockId)
						{
							if ($data['version'] == 3)
							{
								$count = 0;
								$content = str_replace(
									array_keys($blockReplace),
									array_values($blockReplace),
									$content,
									$count
								);
								if ($count)
								{
									$updated = true;
								}
							}
							else if ($data['version'] == 2)
							{
								$count = 0;
								$content = str_replace(
									'@block[' . $blocksCodes[$blockId] . ']',
									$blockCode,
									$content,
									$count
								);
								if ($count)
								{
									$updated = true;
								}
							}
							else
							{
								$count = 0;
								$content = str_replace(
									'@block[' . $blockCode . ']',
									$blockId,
									$content,
									$count
								);
								if ($count)
								{
									$updated = true;
								}
							}
							if (isset($data['replace']) && is_array($data['replace']))
							{
								foreach ($data['replace'] as $find => $replace)
								{
									$count = 0;
									$content = str_replace(
										$find,
										$replace,
										$content,
										$count
									);
									if ($count)
									{
										$updated = true;
									}
								}
							}
						}
						if ($updated)
						{
							$block->saveContent($content);
							$block->save();
						}
					}
					return $landing->getId();
				}
				else
				{
					$this->setErrors($landing->getError()->getErrors());
					return false;
				}
			}
			else
			{
				$this->setErrors($res->getErrors());
				return false;
			}
		}

		return false;
	}

	/**
	 * Get domain id for new site.
	 * @return mixed
	 */
	protected function getDomainId()
	{
		return !Manager::isB24()
			? Domain::getCurrentId()
			: ' ';
	}

	/**
	 * Create demo page for preview.
	 * @param string $code Code of page.
	 * @return string
	 */
	public function getUrlPreview($code)
	{
		// first detect from rest
		if (($codePos = strrpos($code, '.')) !== false)
		{
			$appCode = substr($code, 0, $codePos);
			$xmlId = substr($code, $codePos + 1);
			if ($appCode == 'local')
			{
				$appCode = '';
			}
			//list($appCode, $xmlId) = explode('.', $code, 2);
			$res = Demos::getList(array(
				'select' => array(
					'PREVIEW_URL'
				),
				'filter' => array(
					'=ACTIVE' => 'Y',
					'=SHOW_IN_LIST' => 'Y',
					'=TYPE' => $this->arParams['TYPE'],
					'=APP_CODE' => $appCode,
					'=XML_ID' => $xmlId
				)
			));
			if ($row = $res->fetch())
			{
				return $row['PREVIEW_URL'];
			}
		}

		// preview now gets always from repo
		if (!defined('LANDING_IS_REPO') || LANDING_IS_REPO !== true)
		{
			if ($restSrc = $this->getRestPath())
			{
				$http = new HttpClient;
				try
				{
					if (Option::get('landing', 'b24partner', 'N') == 'Y')
					{
						$partnerId = 0;
					}
					$res = Json::decode($http->get(
						$restSrc . 'landing_cloud.cloud.getUrlPreview?user_lang=' . LANGUAGE_ID .
						'&code=' . $code . '&type=' . $this->arParams['TYPE'] .
						(isset($partnerId) ? '&pv=2&partner_id=' . $partnerId : '')//tmp
					));
				}
				catch (\Exception $e) {}
				if (isset($res['result']))
				{
					return $res['result'];
				}
				else
				{
					return null;
				}
			}
		}

		$demo = $this->getDemoPage();
		if (isset($demo[$code]))
		{
			$smnSiteId = defined('SMN_SITE_ID')
						? SMN_SITE_ID
						: SITE_ID;
			$funcGetSites = function()
			{
				return $this->getSites(array(
					'select' => array(
						'ID', 'SMN_SITE_ID'
					),
					'filter' => array(
						'=TYPE' => 'PREVIEW'
					),
					'limit' => 1
				));
			};
			$site = $funcGetSites();
			if (!$site)
			{
				$res = Site::add(array(
					'TITLE' => 'Preview',
					'CODE' => str_replace(
						'#rand#',
						strtolower(\randString(5)),
						$this->urlTpl
					),
					'DOMAIN_ID' => $this->getDomainId(),
					'TYPE' => 'PREVIEW',
					'SMN_SITE_ID' => $smnSiteId
				));
				if ($res->isSuccess())
				{
					$site = $funcGetSites();
				}
				else
				{
					$this->setErrors($res->getErrors());
					return null;
				}
			}
			if ($site)
			{
				$site = array_shift($site);
				if (
					!$site['SMN_SITE_ID'] ||
					($smnSiteId != !$site['SMN_SITE_ID'])
				)
				{
					Site::update($site['ID'], array(
						'SMN_SITE_ID' => $smnSiteId
					));
				}
				$page = $this->getLandings(array(
					'filter' => array(
						'SITE_ID' => $site['ID'],
						'XML_ID' => '%|' . $code
					)
		 		));
				if ($page)
				{
					$page = array_shift($page);
					$pageId = $page['ID'];
				}
				else
				{
					$pageId = $this->createPage($site['ID'], $code);
					$landing = Landing::createInstance($pageId);
					$landing->publication();
				}
				if ($pageId)
				{
					$landing = Landing::createInstance($pageId);
					$uri = new \Bitrix\Main\Web\Uri(
						$landing->getPublicUrl(false, true, true)
					);
					$uri->addParams(array(
						'preview' => 'Y',
						'user_lang' => $this->request('user_lang')
										? $this->request('user_lang')
										: LANGUAGE_ID
					));
					return $uri->getUri();
				}
			}
		}

		return null;
	}

	/**
	 * Create site or page by template.
	 * @param string $code Demo site code.
	 * @param mixed $additional Data from form.
	 * @return boolean
	 */
	protected function actionSelect($code, $additional = null)
	{
		// create page in the site
		if (
			$this->arParams['SITE_WORK_MODE'] != 'Y' &&
			$this->arParams['SITE_ID'] > 0
		)
		{
			$landingId = $this->createPage(
				$this->arParams['SITE_ID'],
				$code
			);
			if ($landingId)
			{
				if (!$this->redirectToLanding($landingId))
				{
					return false;
				}
			}
			else
			{
				return false;
			}
		}
		// else create site and pages into
		$demo = $this->getDemoSite();
		if (isset($demo[$code]))
		{
			// get from rest
			if ($demo[$code]['REST'] > 0)
			{
				$demo[$code]['DATA'] = $this->getTemplateManifest(
					$demo[$code]['REST']
				);
			}
			$data = $demo[$code]['DATA'];
			$version = $data['version'];
			$siteData = $data['fields'];
			$siteData = $this->prepareAdditionalFields($siteData);
			$siteData['DOMAIN_ID'] = $this->getDomainId();
			$siteData['ACTIVE'] = 'N';
			$siteData['CODE'] = str_replace(
				'#rand#',
				strtolower(\randString(5)),
				$this->urlTpl
			);
			$siteData['XML_ID'] = $data['name'] . '|' . $code;
			$siteData['TYPE'] = $demo[$code]['TYPE'];
			$pageIndex = $siteData['LANDING_ID_INDEX']
						? $siteData['LANDING_ID_INDEX']
						: '';
			$page404 = $siteData['LANDING_ID_404']
						? $siteData['LANDING_ID_404']
						: '';
			// first create site
			if ($this->arParams['SITE_WORK_MODE'] == 'Y')
			{
				$siteId = $this->arParams['SITE_ID'];
			}
			else
			{
				// callback (@todo must be changed in future)
				if (
					$siteData['TYPE'] == 'STORE' &&
					Manager::isB24()
				)
				{
					$settings = Settings::getDataForSite();
					// if shop section exist, save for site, else make import
					$res = \Bitrix\Iblock\SectionTable::getList(array(
						'select' => array(
							'ID'
						),
						'filter' => array(
							'IBLOCK_ID' => $settings['IBLOCK_ID'],
							'=CODE' => 'clothes',
							'=XML_ID' => '666',
						)
					));
					if ($row = $res->fetch())
					{
						$siteData['ADDITIONAL_FIELDS']['SETTINGS_SECTION_ID'] = $row['ID'];
					}
					else
					{
/*						$callbackRes = $this->createCatalog($code);
						if ($callbackRes !== true)
						{
							$this->addError('CALLBACK_ERROR', $callbackRes);
							return false;
						}
						// gets new added section and save it for site
						$res = \Bitrix\Iblock\SectionTable::getList(array(
							'select' => array(
								'ID'
							),
							'filter' => array(
								'IBLOCK_ID' => $settings['IBLOCK_ID'],
								'=CODE' => 'clothes',
								'=XML_ID' => '666',
							)
						));
						if ($row = $res->fetch())
						{
							$siteData['ADDITIONAL_FIELDS']['SETTINGS_SECTION_ID'] = $row['ID'];
						} */
					}

				}
				$buttons = \Bitrix\Landing\Hook\Page\B24button::getButtons();
				$buttons = array_keys($buttons);
				if (isset($buttons[0]))
				{
					$siteData['ADDITIONAL_FIELDS']['B24BUTTON_CODE'] = $buttons[0];
				}
				$res = Site::add($siteData);
				$siteId = $res->getId();
			}
			if ($siteId)
			{
				$siteData['ID'] = $siteId;
				$forSiteUpdate = array();
				$firstLandingId = false;
				if (
					!isset($data['syspages']) ||
					!is_array($data['syspages'])
				)
				{
					$data['syspages'] = array();
				}
				// check system pages for unique
				foreach (Syspage::get($siteId) as $sysCode => $sysItem)
				{
					if (isset($data['syspages'] [$sysCode]))
					{
						unset($data['syspages'] [$sysCode]);
					}
				}
				// then create pages
				$landings = array();
				if (empty($data['items']))
				{
					$data['items'][] = $code;
				}
				Landing::disableUpdate();
				foreach ($data['items'] as $page)
				{
					$landingId = $this->createPage(
						$siteData['ID'],
						$demo[$code]['APP_CODE']
						? $demo[$code]['APP_CODE'] . '.' . $page
						: $page
					);
					if (!$landingId)
					{
						continue;
						//return false;
					}
					elseif (!$firstLandingId)
					{
						$firstLandingId = $landingId;
					}
					$landings[$page] = $landingId;
				}
				Landing::enableUpdate();
				$landingReplace = [];
				foreach ($this->oldIds as $oldId => $newId)
				{
					$landingReplace['\'#landing' . $oldId . '\''] = '\'#landing' . $newId . '\'';
					$landingReplace['"#landing' . $oldId . '"'] = '"#landing' . $newId . '"';
				}
				// update site for some fields
				if (isset($landings[$pageIndex]))
				{
					$forSiteUpdate['LANDING_ID_INDEX'] = $landings[$pageIndex];
				}
				if (isset($landings[$page404]))
				{
					$forSiteUpdate['LANDING_ID_404'] = $landings[$page404];
				}
				// redefine content of pages
				foreach ($landings as $landCode => $landId)
				{
					$landing = Landing::createInstance($landId);
					if ($landing->exist())
					{
						foreach ($landing->getBlocks() as $block)
						{
							$updated = false;
							$content = $block->getContent();
							foreach ($landings as $landCode => $landId)
							{
								$count = 0;
								if ($version == 3)
								{
									$content = str_replace(
										array_keys($landingReplace),
										array_values($landingReplace),
										$content,
										$count
									);
								}
								else
								{
									$content = str_replace(
										'@landing[' . $landCode . ']',
										$landId,
										$content,
										$count
									);
								}
								if ($count)
								{
									$updated = true;
								}
							}
							if ($updated)
							{
								$block->saveContent($content);
								$block->save();
							}
						}
					}
				}
				// set layout
				$tplsXml = array();
				$pages = $this->getDemoPage($code);
				$res = Template::getList(array(
					'select' => array(
						'ID', 'XML_ID'
					)
				));
				while ($row = $res->fetch())
				{
					$tplsXml[$row['XML_ID']] = $row['ID'];
				}
				// for site
				if (
					isset($data['layout']['code']) &&
					isset($tplsXml[$data['layout']['code']])
				)
				{
					$ref = array();
					if (isset($data['layout']['ref']))
					{
						foreach ((array)$data['layout']['ref'] as $ac => $aLidCode)
						{
							if (isset($landings[$aLidCode]))
							{
								$ref[$ac] = $landings[$aLidCode];
							}
						}
					}
					$forSiteUpdate['TPL_ID'] = $tplsXml[$data['layout']['code']];
					TemplateRef::setForSite(
						$siteData['ID'],
						$ref
					);
				}
				// and for pages
				foreach ($pages as $pageCode => $page)
				{
					if ($page['REST'] > 0)
					{
						$pageCode = $page['XML_ID'];
						$page['DATA'] = $this->getTemplateManifest(
							$page['REST']
						);
					}
					$page = $page['DATA'];
					if (
						isset($landings[$pageCode]) &&
						isset($page['layout']['code']) &&
						isset($tplsXml[$page['layout']['code']])
					)
					{
						$ref = array();
						if (isset($page['layout']['ref']))
						{
							foreach ((array)$page['layout']['ref'] as $ac => $aLidCode)
							{
								if (isset($landings[$aLidCode]))
								{
									$ref[$ac] = $landings[$aLidCode];
								}
							}
						}
						if ($tplsXml[$page['layout']['code']] > 0)
						{
							Landing::update($landings[$pageCode], array(
								'TPL_ID' => $tplsXml[$page['layout']['code']]
							));
							TemplateRef::setForLanding(
								$landings[$pageCode],
								$ref
							);
						}
					}
				}
				// set pages to folders
				$alreadyFolders = array();
				if (isset($data['folders']) && is_array($data['folders']))
				{
					foreach ($data['folders'] as $folder => $items)
					{
						if (isset($landings[$folder]) && is_array($items))
						{
							foreach ($items as $item)
							{
								if (isset($landings[$item]))
								{
									if (!in_array($landings[$folder], $alreadyFolders))
									{
										$alreadyFolders[] = $landings[$folder];
										Landing::update($landings[$folder], array(
											'FOLDER' => 'Y'
										));
									}
									Landing::update($landings[$item], array(
										'FOLDER_ID' => $landings[$folder]
									));
								}
							}
						}
					}
				}
				// create system refs
				foreach ($data['syspages'] as $sysCode => $pageCode)
				{
					if (isset($landings[$pageCode]))
					{
						Syspage::set(
							$siteData['ID'],
							$sysCode,
							$landings[$pageCode]
						);
					}
				}
				// update site if need
				if ($forSiteUpdate)
				{
					Site::update($siteData['ID'], $forSiteUpdate);
				}
				$this->redirectToLanding($firstLandingId);
			}
			else
			{
				$this->setErrors($res->getErrors());
				return false;
			}
			return true;
		}

		return false;
	}

	/**
	 * @param string $code Demo site code.
	 * @param mixed $additional Data from form.
	 * @return array
	 */
	private function stepperStore($code, $additional = null)
	{
		$result = [
			'STATUS' => self::STEP_STATUS_COMPLETE,
			'MESSAGE' => '',
			'FINAL' => true
		];

		$demo = $this->getDemoSite();
		if (!isset($demo[$code]))
			return $result;

		if ($demo[$code]['TYPE'] == 'STORE' && Manager::isB24())
		{
			Loader::includeModule('iblock');

			$internalResult = $this->createCatalogStep($code);
			if ($internalResult['FINAL'])
			{
				if ($internalResult['STATUS'] == self::STEP_STATUS_ERROR)
				{
					$result = $internalResult;
				}
				else
				{
					$result['MESSAGE'] = $internalResult['MESSAGE'];
				}
			}
			else
			{
				$result['STATUS'] = self::STEP_STATUS_CONTINUE;
				$result['MESSAGE'] = $internalResult['MESSAGE'];
				$result['FINAL'] = false;
			}
		}

		return $result;
	}

	private function stepperStoreFinalUrl($code, $additional = null)
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
		$uriSelect = new \Bitrix\Main\Web\Uri($request->getRequestUri());
		$uriSelect->deleteParams(array('sessid', 'stepper', 'action', 'param', 'additional', 'code'));
		$uriSelect->addParams(array(
			'action' => 'select',
			'param' => $code,
			'sessid' => bitrix_sessid()
		));
		return $uriSelect->getUri();
	}

	/**
	 * Get demo templates.
	 * @param string $subDir Subdir for data dir.
	 * @param string $code Item code.
	 * @return array
	 */
	protected function getDemo($subDir, $code = null)
	{
		static $data = array();

		$eventFunc = function($data) use($subDir, $code)
		{
			// fill from rest
			if (!empty($data))
			{
				//$last = array_pop(array_values($data));
				$sort = 0;
				if ($code)
				{
					$xmlId = substr($code, strrpos($code, '.') + 1);
					$appCode = substr($code, 0, strrpos($code, '.'));
				}
				$res = Demos::getList(array(
					'select' => array(
						'ID', 'APP_CODE', 'XML_ID', 'TYPE',
						'TITLE', 'ACTIVE', 'DESCRIPTION',
						'PREVIEW', 'PREVIEW2X', 'PREVIEW3X'
					),
					'filter' => array(
						'=ACTIVE' => 'Y',
						'=TYPE' => $this->arParams['TYPE'],
						'=TPL_TYPE' => ($subDir == $this::DEMO_DIR_SITE)
										? Demos::TPL_TYPE_SITE
										: Demos::TPL_TYPE_PAGE,
						$code
							? array(
								'=XML_ID' => $xmlId,
								'=APP_CODE' => $appCode
							)
							: array(
								'=SHOW_IN_LIST' => 'Y'
							)
					),
					'order' => array(
						'ID' => 'asc'
					)
				));
				while ($row = $res->fetch())
				{
					if (!$row['APP_CODE'])
					{
						$row['APP_CODE'] = 'local';
					}
					$key = $row['APP_CODE'] . '.' . $row['XML_ID'];
					$data = array(
						$key => array(
							'ID' => $key,
							'XML_ID' => $row['XML_ID'],
							'TYPE' => strtoupper($row['TYPE']),
							'TITLE' => $row['TITLE'],
							'ACTIVE' => $row['ACTIVE'],
							'AVAILABLE' => true,
							'DESCRIPTION' => $row['DESCRIPTION'],
							'SORT' => --$sort,
							'PREVIEW' => $row['PREVIEW'],
							'PREVIEW2X' => $row['PREVIEW2X'],
							'PREVIEW3X' => $row['PREVIEW3X'],
							'APP_CODE' => $row['APP_CODE'],
							'REST' => $row['ID'],
							'DATA' => []
						)
					) + $data;
				}
			}
			// send events
			$event = new \Bitrix\Main\Event('landing', 'onDemosGetRepository', array(
				'data' => $data
			));
			$event->send();
			foreach ($event->getResults() as $result)
			{
				if ($result->getType() != \Bitrix\Main\EventResult::ERROR)
				{
					if (($modified = $result->getModified()))
					{
						if (isset($modified['data']))
						{
							$data = $modified['data'];
						}
					}
				}
			}
			return $data;
		};

		/**
		 * Attention! method also used into
		 * \Bitrix\Landing\PublicAction\Demos::getList.
		 */

		if (!isset($data[$subDir]))
		{
			// system cache begin
			$cache = new \CPHPCache();
			$cacheTime = 86400;
			$cacheStarted = false;
			$cacheId = 'demo_manifest';
			$cacheId .= $subDir . LANGUAGE_ID . $this->arParams['TYPE'];
			$cacheId .= 'b24partner' . Option::get('landing', 'b24partner', 'N');
			$cachePath = 'landing';
			if ($cache->initCache($cacheTime, $cacheId, $cachePath))
			{
				$data[$subDir] = $cache->getVars();
				return $eventFunc($data[$subDir]);
			}
			if ($cache->startDataCache($cacheTime, $cacheId, $cachePath))
			{
				$cacheStarted = true;
				if (defined('BX_COMP_MANAGED_CACHE'))
				{
					Manager::getCacheManager()->startTagCache($cachePath);
					Manager::getCacheManager()->registerTag(self::DEMO_TAG);
				}
			}
			
			// get from cloud only if it not repo
			$restSrc = Manager::getOption('block_vendor_bitrix');
			if (
				(!defined('LANDING_IS_REPO') || LANDING_IS_REPO !== true) &&
				$restSrc
			)
			{
				$data[$subDir] = array();
				
				$http = new HttpClient;
				if ($subDir == self::DEMO_DIR_PAGE)
				{
					$command = 'getDemoPageList';
				}
				else
				{
					$command = 'getDemoSiteList';
				}
				try
				{
					if (Option::get('landing', 'b24partner', 'N') == 'Y')
					{
						$partnerId = Option::get(
							'bitrix24',
							'partner_id',
							0
						);
					}
					$newShopEnabled = Option::get(
						'crm',
						'crm_shop_enabled',
						'N'
					) == 'Y';
					$res = Json::decode($http->get(
						$restSrc . 'landing_cloud.cloud.' . $command .
						'?user_lang=' . LANGUAGE_ID .
						'&type=' . $this->arParams['TYPE'] .
						($newShopEnabled ? '&newshop=1' : '') .// tmp
						(isset($partnerId) ? '&pv=2&partner_id=' . $partnerId : '')//tmp
					));
				}
				catch (\Exception $e) {}
				if (
					isset($res['result']) &&
					is_array($res['result'])
				)
				{
					$data[$subDir] = $res['result'];
				}

				// system cache end
				if ($cacheStarted)
				{
					if (empty($data[$subDir]))
					{
						$cache->abortDataCache();
					}
					else
					{
						$cache->endDataCache($data[$subDir]);
						if (defined('BX_COMP_MANAGED_CACHE'))
						{
							Manager::getCacheManager()->EndTagCache();
						}
					}
				}

				return $eventFunc($data[$subDir]);
			}

			$items = array();
			$data[$subDir] = array();
			$pathLocal = '/bitrix/components/bitrix/landing.demo/data/' . $subDir;//@todo make better
			$path = Manager::getDocRoot() . $pathLocal;
			$siteTypeDef = Site::getDefaultType();
			$siteTypeCurr = $this->arParams['TYPE'];
			$dir = array();

			// read demo from dir
			if (($handle = opendir($path)))
			{
				while ((($entry = readdir($handle)) !== false))
				{
					if ($entry != '.' && $entry != '..')
					{
						$descPath = $path . '/' . $entry . '/.description.php';
						if (file_exists($descPath))
						{
							$dir[] = $entry;
						}
						else if (($handleSubdir = opendir($path . '/' . $entry)))
						{
							while ((($entrySubdir = readdir($handleSubdir)) !== false))
							{
								if ($entrySubdir != '.' && $entrySubdir != '..')
								{
									$descPath = $path . '/' . $entry . '/' . $entrySubdir . '/.description.php';
									if (file_exists($descPath))
									{
										$dir[] = $entry . '/' . $entrySubdir;
									}
								}
							}
						}
					}
				}
			}

			// and work with this
			foreach ($dir as $entry)
			{
				$itemData = include $path . '/' . $entry . '/.description.php';
				if (!isset($itemData['type']))
				{
					$itemData['type'] = $siteTypeDef;
				}
				else
				{
					$itemData['type'] = strtoupper($itemData['type']);
				}
				if ($siteTypeCurr == $itemData['type'] && isset($itemData['name']))
				{
					if (!isset($itemData['fields']) || !is_array($itemData['fields']))
					{
						$itemData['fields'] = array();
					}
					if (!isset($itemData['items']) || !is_array($itemData['items']))
					{
						$itemData['items'] = array();
					}
					if (!isset($itemData['version']))
					{
						$itemData['version'] = 1;
					}
					// predefined
					$itemData['fields']['ACTIVE'] = 'Y';
					if (!isset($itemData['fields']['TITLE']))
					{
						$itemData['fields']['TITLE'] = $itemData['name'];
					}
					$items[$entry] = array(
						'ID' => $entry,
						'XML_ID' => $entry,
						'TYPE' => $itemData['type'],
						'TITLE' => $itemData['name'],
						'ACTIVE' => isset($itemData['active']) ? $itemData['active'] : true,
						'AVAILABLE' => isset($itemData['available']) ? $itemData['available'] : true,
						'DESCRIPTION' => isset($itemData['description'])
										? $itemData['description']
										: '',
						'SORT' => isset($itemData['sort']) ? $itemData['sort'] : 0,
						'PREVIEW' => file_exists($path . '/' . $entry . '/preview.jpg')
										? Manager::getUrlFromFile($pathLocal . '/' . $entry . '/preview.jpg')
										: '',
						'PREVIEW2X' => file_exists($path . '/' . $entry . '/preview@2x.jpg')
										? Manager::getUrlFromFile($pathLocal . '/' . $entry . '/preview@2x.jpg')
										: '',
						'PREVIEW3X' => file_exists($path . '/' . $entry . '/preview@3x.jpg')
										? Manager::getUrlFromFile($pathLocal . '/' . $entry . '/preview@3x.jpg')
										: '',
						'APP_CODE' => '',
						'REST' => 0,
						'DATA' => $itemData
					);
				}
			}

			// sort like a
			// 1 2 10 0 0 0 -1 -2 -10
			uasort($items, function($a, $b)
			{
				if ($a['SORT'] != $b['SORT'])
				{
					// one sort is zero
					if($a['SORT'] == 0)
					{
						return $b['SORT'];
					}
					if($b['SORT'] == 0)
					{
						return $a['SORT'] * -1;
					}

					// both sort - not zero
					$res = $a['SORT'] < $b['SORT'] ? -1 : 1;
					if($a['SORT'] < 0 || $b['SORT'] < 0)
					{
						$res = $res * -1;
					}

					return $res;
				}
				else
				{
					if ($a['TITLE'] == $b['TITLE'])
					{
						return 0;
					}
					return ($a['TITLE'] < $b['TITLE']) ? -1 : 1;
				}
			});

			// available - first
			foreach ($items as $key => $item)
			{
				if(!$item['ACTIVE'])
				{
					unset($items[$key]);
					continue;
				}
				elseif ($item['AVAILABLE'])
				{
					$data[$subDir][$key] = $item;
					unset($items[$key]);
				}
			}
			$data[$subDir] += $items;

			// system cache end
			if ($cacheStarted)
			{
				$cache->endDataCache($data[$subDir]);
				if (defined('BX_COMP_MANAGED_CACHE'))
				{
					Manager::getCacheManager()->EndTagCache();
				}
			}
		}

		return $eventFunc($data[$subDir]);
	}

	/**
	 * Get demo site templates.
	 * @return array
	 */
	public function getDemoSite()
	{
		return $this->getDemo($this::DEMO_DIR_SITE);
	}

	/**
	 * Get demo page templates.
	 * @param string $code Item code.
	 * @return array
	 */
	public function getDemoPage($code = null)
	{
		return $this->getDemo($this::DEMO_DIR_PAGE, $code);
	}
	
	/**
	 * Checking site or page activity depending on portal zone
	 * Format:
	 * $zones['ONLY_IN'] - show site only in these zones
	 * $zones['EXCEPT'] - not show site, if zone in this list
	 * @param array $zones Zones array.
	 * @return bool
	 */
	public static function checkActive($zones = array())
	{
		if (!empty($zones))
		{
			$currentZone = Manager::getZone();
			if (
				isset($zones['ONLY_IN']) &&
				is_array($zones['ONLY_IN']) && !empty($zones['ONLY_IN']) &&
				!in_array($currentZone, $zones['ONLY_IN'])
			)
			{
				return false;
			}
			if (
				isset($zones['EXCEPT']) &&
				is_array($zones['EXCEPT']) && !empty($zones['EXCEPT']) &&
				in_array($currentZone, $zones['EXCEPT'])
			)
			{
				return false;
			}
			return true;
		}
		return true;
	}

	/**
	 * Create some highloadblocks.
	 * @return void
	 */
	public static function createHLblocks()
	{
		if (!Loader::includeModule('highloadblock'))
		{
			return;
		}

		$xmlPath = '/bitrix/components/bitrix/landing.demo/data/xml';

		// demo data
		$sort = 0;
		$colorValues = array();
		$colors = array(
			'PURPLE' => 'colors_files/iblock/0d3/0d3ef035d0cf3b821449b0174980a712.jpg',
			'BROWN' => 'colors_files/iblock/f5a/f5a37106cb59ba069cc511647988eb89.jpg',
			'SEE' => 'colors_files/iblock/f01/f01f801e9da96ae5a7f26aae01255f38.jpg',
			'BLUE' => 'colors_files/iblock/c1b/c1ba082577379bdc75246974a9f08c8b.jpg',
			'ORANGERED' => 'colors_files/iblock/0ba/0ba3b7ecdef03a44b145e43aed0cca57.jpg',
			'REDBLUE' => 'colors_files/iblock/1ac/1ac0a26c5f47bd865a73da765484a2fa.jpg',
			'RED' => 'colors_files/iblock/0a7/0a7513671518b0f2ce5f7cf44a239a83.jpg',
			'GREEN' => 'colors_files/iblock/b1c/b1ced825c9803084eb4ea0a742b2342c.jpg',
			'WHITE' => 'colors_files/iblock/b0e/b0eeeaa3e7519e272b7b382e700cbbc3.jpg',
			'BLACK' => 'colors_files/iblock/d7b/d7bdba8aca8422e808fb3ad571a74c09.jpg',
			'PINK' => 'colors_files/iblock/1b6/1b61761da0adce93518a3d613292043a.jpg',
			'AZURE' => 'colors_files/iblock/c2b/c2b274ad2820451d780ee7cf08d74bb3.jpg',
			'JEANS' => 'colors_files/iblock/24b/24b082dc5e647a3a945bc9a5c0a200f0.jpg',
			'FLOWERS' => 'colors_files/iblock/64f/64f32941a654a1cbe2105febe7e77f33.jpg'
		);
		foreach ($colors as $colorName => $colorFile)
		{
			$sort += 100;
			$colorValues[] = array(
				'UF_NAME' => Loc::getMessage('LANDING_CMP_COLOR_' . $colorName),
				'UF_FILE' =>
					array (
						'name' => strtolower($colorName) . '.jpg',
						'type' => 'image/jpeg',
						'tmp_name' => Manager::getDocRoot() . $xmlPath . '/hl/' . $colorFile
					),
				'UF_SORT' => $sort,
				'UF_DEF' => ($sort > 100) ? '0' : '1',
				'UF_XML_ID' => strtolower($colorName)
			);
		}
		$sort = 0;
		$brandValues = array();
		$brands = array(
			'Company1' => 'brands_files/cm-01.png',
			'Company2' => 'brands_files/cm-02.png',
			'Company3' => 'brands_files/cm-03.png',
			'Company4' => 'brands_files/cm-04.png',
			'Brand1' => 'brands_files/bn-01.png',
			'Brand2' => 'brands_files/bn-02.png',
			'Brand3' => 'brands_files/bn-03.png',
		);
		foreach ($brands as $brandName => $brandFile)
		{
			$sort += 100;
			$brandValues[] = array(
				'UF_NAME' => $brandName,
				'UF_FILE' =>
					array (
						'name' => strtolower($brandName) . '.jpg',
						'type' => 'image/jpeg',
						'tmp_name' => Manager::getDocRoot() . $xmlPath . '/hl/' . $brandFile
					),
				'UF_SORT' => $sort,
				'UF_XML_ID' => strtolower($brandName)
			);
		}

		// some tables
		$tables = array(
			'eshop_color_reference' => array(
				'name' => 'ColorReference',
				'fields' => array(
					array(
						'FIELD_NAME' => 'UF_NAME',
						'USER_TYPE_ID' => 'string',
						'XML_ID' => 'UF_COLOR_NAME'
					),
					array(
						'FIELD_NAME' => 'UF_FILE',
						'USER_TYPE_ID' => 'file',
						'XML_ID' => 'UF_COLOR_FILE'
					),
					array(
						'FIELD_NAME' => 'UF_SORT',
						'USER_TYPE_ID' => 'double',
						'XML_ID' => 'UF_COLOR_SORT'
					),
					array(
						'FIELD_NAME' => 'UF_DEF',
						'USER_TYPE_ID' => 'boolean',
						'XML_ID' => 'UF_COLOR_DEF'
					),
					array(
						'FIELD_NAME' => 'UF_XML_ID',
						'USER_TYPE_ID' => 'string',
						'XML_ID' => 'UF_XML_ID'
					)
				),
				'values' => $colorValues
			),
			'eshop_brand_reference' => array(
				'name' => 'BrandReference',
				'fields' => array(
					array(
						'FIELD_NAME' => 'UF_NAME',
						'USER_TYPE_ID' => 'string',
						'XML_ID' => 'UF_BRAND_NAME'
					),
					array(
						'FIELD_NAME' => 'UF_FILE',
						'USER_TYPE_ID' => 'file',
						'XML_ID' => 'UF_BRAND_FILE'
					),
					array(
						'FIELD_NAME' => 'UF_SORT',
						'USER_TYPE_ID' => 'double',
						'XML_ID' => 'UF_BRAND_SORT'
					),
					array(
						'FIELD_NAME' => 'UF_XML_ID',
						'USER_TYPE_ID' => 'string',
						'XML_ID' => 'UF_XML_ID'
					)
				),
				'values' => $brandValues
			)
		);

		// create tables and fill with demo-data
		foreach ($tables as $tableName => &$table)
		{
			// if this hl isn't exist
			$res = Highloadblock\HighloadBlockTable::getList(
				array(
					'filter' => array(
						'NAME' => $table['name'],
						'TABLE_NAME' => $tableName
					)
				)
			);
			if (!$res->fetch())
			{
				// add new hl block
				$result = Highloadblock\HighloadBlockTable::add(array(
					'NAME' => $table['name'],
					'TABLE_NAME' => $tableName
				));
				if ($result->isSuccess())
				{
					$sort = 100;
					$tableId = $result->getId();
					// add fields
					$userField  = new \CUserTypeEntity;
					foreach ($table['fields'] as $field)
					{
						$field['ENTITY_ID'] = 'HLBLOCK_' . $tableId;
						$res = \CUserTypeEntity::getList(
							array(),
							array(
								'ENTITY_ID' => $field['ENTITY_ID'],
								'FIELD_NAME' => $field['FIELD_NAME']
							)
						);
						if (!$res->Fetch())
						{
							$field['SORT'] = $sort;
							$userField->Add($field);
							$sort += 100;
						}
					}
					// add data
					$hldata = Highloadblock\HighloadBlockTable::getById($tableId)->fetch();
					$hlentity = Highloadblock\HighloadBlockTable::compileEntity($hldata);
					$entityClass = $hlentity->getDataClass();
					foreach ($table['values'] as $item)
					{
						$entityClass::add($item);
					}
				}
			}
		}
	}

	/**
	 * Create products in CRM catalog.
	 *
	 * @param string $xmlcode
	 * @return bool|string True or error message.
	 */
	private function createCatalog($xmlcode)
	{
		$result = true;
		do
		{
			$internalResult = $this->createCatalogStep($xmlcode);
			$final = $internalResult['FINAL'];
			if ($internalResult['STATUS'] == self::STEP_STATUS_ERROR)
				$result = $internalResult['MESSAGE'];
		}
		while ($final === false);
		return $result;
	}

	/**
	 * @param string $xmlcode
	 * @return array
	 * @throws Main\LoaderException
	 */
	private function createCatalogStep($xmlcode)
	{
		$result = [
			'STATUS' => self::STEP_STATUS_ERROR,
			'MESSAGE' => '',
			'FINAL' => true
		];

		if (
			!Loader::includeModule('iblock')
			|| !Loader::includeModule('catalog')
		)
		{
			$result['MESSAGE'] = Loc::getMessage('LANDING_CMP_ERROR_MASTER_NO_SERVICE');
			return $result;
		}

		// ru-zones
		/*if (!in_array(LANGUAGE_ID, ['ru']))
		{
			$result['MESSAGE'] = Loc::getMessage('LANDING_CMP_ERROR_MASTER_NO_DATA');
			return $result;
		}*/

		$this->initStepStorage();

		$result['STATUS'] = self::STEP_STATUS_CONTINUE;
		$result['FINAL'] = false;
		switch ($this->getCurrentStep())
		{
			case self::STEP_ID_HIGHLOADBLOCK:
				$this->createHLblocks();
				$this->nextStep();
				$result['MESSAGE'] = ''; // technical step
				break;
			case self::STEP_ID_PREPARE_CRM_CATALOG:
				$this->transferCrmCatalogXmlId();
				$this->nextStep();
				$result['MESSAGE'] = ''; // technical step
				break;
			case self::STEP_ID_XML_IMPORT:
				$importResult = $this->importXmlFile();
				$result['MESSAGE'] = $importResult['MESSAGE'];
				if ($importResult['FINAL'])
				{
					if ($importResult['STATUS'] == self::STEP_STATUS_ERROR)
					{
						$result['STATUS'] = self::STEP_STATUS_ERROR;
						$result['FINAL'] = true;
					}
					else
					{
						$this->nextStep();
					}
				}
				unset($importResult);
				break;
			case self::STEP_ID_ADDITIONAL_UPDATE:
				$this->updateImportedIblocks(
					$this->getXmlIblockId('catalog'),
					$this->getXmlIblockId('catalog_sku')
				);
				$this->nextStep();
				$result['MESSAGE'] = ''; // technical step
				break;
			case self::STEP_ID_CATALOG_REINDEX:
				$this->reindexCatalog(
					$this->getXmlIblockId('catalog'),
					$this->getXmlIblockId('catalog_sku')
				);
				$this->nextStep();
				$result['MESSAGE'] = ''; // technical step
				break;
			case self::STEP_ID_FINAL:
				$result = [
					'STATUS' => self::STEP_STATUS_COMPLETE,
					'MESSAGE' => Loc::getMessage('LANDING_CMP_LD_MESS_IMPORT_COMPLETE'),
					'FINAL' => true
				];
				break;
			default:
				$result['MESSAGE'] = Loc::getMessage('LANDING_CMP_LD_ERROR_UNKNOWN_STEP_ID');
				$result['STATUS'] = self::STEP_STATUS_ERROR;
				$result['FINAL'] = true;
				break;
		}

		if ($result['FINAL'])
			$this->destroyStepStorage();

		return $result;
	}

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent()
	{
		global $APPLICATION;

		$init = $this->init();

		if ($init)
		{
			$this->checkParam('SITE_ID', 0);
			$this->checkParam('TYPE', '');
			$this->checkParam('ACTION_FOLDER', 'folderId');
			$this->checkParam('PAGE_URL_SITES', '');
			$this->checkParam('PAGE_URL_LANDING_VIEW', '');
			$this->checkParam('SITE_WORK_MODE', 'N');

			if (
				$this->arParams['SITE_ID'] > 0 &&
				$this->arParams['SITE_WORK_MODE'] != 'Y'
			)
			{
				$this->arResult['DEMO'] = $this->getDemoPage();
				$this->arResult['LIMIT_REACHED'] = !Manager::checkFeature(
					Manager::FEATURE_CREATE_PAGE,
					[
						'type' => $this->arParams['TYPE']
					]
				);
			}
			else
			{
				$this->arResult['DEMO'] = $this->getDemoSite();
				$this->arResult['LIMIT_REACHED'] = !Manager::checkFeature(
					Manager::FEATURE_CREATE_SITE,
					[
						'type' => $this->arParams['TYPE']
					]
				);
				if (!$this->arResult['LIMIT_REACHED'])
				{
					$this->arResult['LIMIT_REACHED'] = !Manager::checkFeature(
						Manager::FEATURE_CREATE_PAGE,
						[
							'type' => $this->arParams['TYPE']
						]
					);

				}
			}
		}

		$action = $this->request('stepper');
		$param = $this->request('param');
		$additional = $this->request('additional');

		$stepper = 'stepper'.$action;
		$stepperFinalUrl = 'stepper'.$action.'FinalUrl';
		if ($action && is_callable([$this, $stepper]) && is_callable([$this, $stepperFinalUrl]))
		{
			if (!check_bitrix_sessid())
			{
				$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
				$curUri = new \Bitrix\Main\Web\Uri($request->getRequestUri());
				$curUri->deleteParams(array('sessid', 'stepper', 'action', 'param', 'additional', 'code'));
				\localRedirect($curUri->getUri());
			}
			else
			{
				/** @var array $result */
				$stepperResult = $this->{$stepper}($param, $additional);
				switch ($stepperResult['STATUS'])
				{
					case self::STEP_STATUS_CONTINUE:
						$result = [
							'status' => 'continue',
							'message' => $stepperResult['MESSAGE']
						];
						$APPLICATION->RestartBuffer();
						echo Main\Web\Json::encode($result);
						die();
						break;
					case self::STEP_STATUS_ERROR:
						$this->addError('CALLBACK_ERROR', $stepperResult['MESSAGE']);
						break;
					case self::STEP_STATUS_COMPLETE:
						$result = [
							'status' => 'final',
							'message' => $stepperResult['MESSAGE'],
							'url' => $this->{$stepperFinalUrl}($param, $additional)
						];
						$APPLICATION->RestartBuffer();
						echo Main\Web\Json::encode($result);
						die();
						break;
					default:
						$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
						$curUri = new \Bitrix\Main\Web\Uri($request->getRequestUri());
						$curUri->deleteParams(array('sessid', 'stepper', 'action', 'param', 'additional', 'code'));
						\localRedirect($curUri->getUri());
						break;
				}
			}
		}

		parent::executeComponent();
	}

	/**
	 * @param int $iblockId
	 * @param string $xmlId
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function checkIblockXmlId($iblockId, $xmlId)
	{
		$iterator = Iblock\IblockTable::getList([
			'select' => ['ID', 'XML_ID'],
			'filter' => ['=ID' => $iblockId]
		]);
		$row = $iterator->fetch();
		unset($iterator);
		if (!empty($row) && $row['XML_ID'] != $xmlId)
		{
			$iblock = new \CIBlock();
			$iblock->Update($iblockId, ['XML_ID' => $xmlId]);
			unset($iblock);
		}
		unset($row);
	}

	/**
	 * Prepare crm iblocks XML_ID for xml import.
	 *
	 * @return void
	 */
	private function transferCrmCatalogXmlId()
	{
		if (!Loader::includeModule('crm'))
			return;
		$iblockId = (int)\CCrmCatalog::EnsureDefaultExists();
		if ($iblockId > 0)
			self::checkIblockXmlId($iblockId, 'FUTURE-1C-CATALOG');
		$catalog = \CCatalogSku::GetInfoByProductIBlock($iblockId);
		if (!empty($catalog))
			self::checkIblockXmlId($catalog['IBLOCK_ID'], 'FUTURE-1C-CATALOG-OFFERS');
		unset($catalog, $iblockId);
	}

	/**
	 * Set base currency for xml.
	 * @return void
	 */
	private function setXmlBaseCurrency()
	{
		$callback = function(\Bitrix\Main\Event $event)
		{
			static $baseCurrency = null;

			if ($baseCurrency === null)
			{
				$baseCurrency = false;
				if (\Bitrix\Main\Loader::includeModule('currency'))
				{
					$baseCurrency = \Bitrix\Currency\CurrencyManager::getBaseCurrency();
				}
			}

			$result = new \Bitrix\Main\Entity\EventResult;
			$result->modifyFields(array(
				'fields' => array(
					'CURRENCY' => $baseCurrency
				)
			));
			return $result;
		};
		$eventManager = \Bitrix\Main\EventManager::getInstance();
		$eventManager->addEventHandler(
			'catalog',
			'Bitrix\Catalog\Model\Price::OnBeforeAdd',
			$callback
		);
		$eventManager->addEventHandler(
			'catalog',
			'Bitrix\Catalog\Model\Price::OnBeforeUpdate',
			$callback
		);
	}

	/**
	 * Xml import with internal steps.
	 *
	 * @return array
	 */
	private function importXmlFile()
	{
		$result = [
			'STATUS' => self::STEP_STATUS_CONTINUE,
			'MESSAGE' => '',
			'FINAL' => true
		];

		$importer = new \CIBlockXmlImport();
		$xmlProductCatalog = 'CRM_PRODUCT_CATALOG';
		$xmlPath = '/bitrix/components/bitrix/landing.demo/data/xml';
		$xml = $this->getCurrentXml();
		$currentZone = Manager::getZone();
		$subDirLng = in_array($currentZone, ['ru', 'kz', 'by']) ? 'ru' : 'en';
		$this->setXmlBaseCurrency();

		$parameters = [
			'FILE' => Manager::getDocRoot() .$xmlPath .'/clothes_' . $subDirLng . '/' . $xml . '.xml',
			'IBLOCK_TYPE' => $xmlProductCatalog,
			'SITE_LIST' => [SITE_ID],
			'MISSING_SECTION_ACTION' => \CIBlockXmlImport::ACTION_NOTHING,
			'MISSING_ELEMENT_ACTION' => \CIBlockXmlImport::ACTION_NOTHING,
			'INTERVAL' => 15,
		];
		$config = [
			'USE_CRC' => false,
			'PREVIEW_PICTURE_SETTINGS' => false,
			'DETAIL_PICTURE_SETTINGS' => false,
		];

		$importer->init($parameters, $config);
		if (!$importer->isSuccess())
		{
			$result['STATUS'] = self::STEP_STATUS_ERROR;
			$result['MESSAGE'] = implode("\n", $importer->getErrors());
			return $result;
		}

		$importer->run();
		$importResult = $importer->getStepResult();
		if ($importResult['TYPE'] == \CIBlockXmlImport::RESULT_TYPE_SUCCESS)
		{
			if ($importResult['IS_FINAL'] == 'Y')
			{
				$this->setXmlIblockId($xml, $importer->getIblockId());
				$nextXml = $this->getNextXml();
				if ($nextXml === null)
				{
					$result = [
						'STATUS' => self::STEP_STATUS_COMPLETE,
						'MESSAGE' => Loc::getMessage('LANDING_CMP_LD_MESS_XML_IMPORT_COMPLETE'),
						'FINAL' => true
					];
					$this->resetStepParameters();
				}
				else
				{
					$this->setCurrentXml($nextXml);
					$result['MESSAGE'] = $this->importXmlFileResultMessage($xml);
					$result['FINAL'] = false;
				}
			}
			else
			{
				$result['MESSAGE'] = $importResult['MESSAGE'];
				$result['FINAL'] = false;
			}
		}
		else
		{
			$result['STATUS'] = self::STEP_STATUS_ERROR;
			$result['MESSAGE'] = $importResult['ERROR'];
		}
		unset($importResult);
		unset($importer);

		return $result;
	}

	/**
	 * Internal updates.
	 *
	 * @param int $parentIblock
	 * @param int $offerIblock
	 * @return void
	 */
	private function updateImportedIblocks($parentIblock, $offerIblock)
	{
		// link iblocks
		$propertyId = \CIBlockPropertyTools::createProperty(
			$offerIblock,
			\CIBlockPropertyTools::CODE_SKU_LINK,
			array('LINK_IBLOCK_ID' => $parentIblock)
		);

		$res = \CCatalog::getList([], ['IBLOCK_ID' => $offerIblock], false, false, ['IBLOCK_ID']);
		if ($res->fetch())
		{
			\CCatalog::update(
				$offerIblock,
				array(
					'PRODUCT_IBLOCK_ID' => $parentIblock,
					'SKU_PROPERTY_ID' => $propertyId
				)
			);
		}
		else
		{
			\CCatalog::add(array(
				'IBLOCK_ID' => $offerIblock,
				'PRODUCT_IBLOCK_ID' => $parentIblock,
				'SKU_PROPERTY_ID' => $propertyId
			));
		}
		unset($res);

		// additional updates -- common
		foreach (array($parentIblock, $offerIblock) as $iblockId)
		{
			// uniq code
			$defValueCode = array (
				'UNIQUE' => 'N',
				'TRANSLITERATION' => 'Y',
				'TRANS_LEN' => 100,
				'TRANS_CASE' => 'L',
				'TRANS_SPACE' => '_',
				'TRANS_OTHER' => '_',
				'TRANS_EAT' => 'Y',
				'USE_GOOGLE' => 'Y'
			);
			$iblock = new \CIBlock;
			$iblock->update($iblockId, array(
				'FIELDS' => array(
					'CODE' => array (
						'IS_REQUIRED' => 'N',
						'DEFAULT_VALUE' => $defValueCode
					),
					'SECTION_CODE' => array (
						'IS_REQUIRED' => 'N',
						'DEFAULT_VALUE' => $defValueCode
					)
				),
				'LIST_MODE' => 'S'
			));
			// delete all props
			$toDelete = array(
				/*'CML2_TAXES', 'CML2_BASE_UNIT', 'CML2_TRAITS', 'CML2_ATTRIBUTES', 'CML2_ARTICLE',
				'CML2_BAR_CODE', 'CML2_MANUFACTURER', */'CML2_PICTURES', 'CML2_FILES'
			);
			foreach ($toDelete as $code)
			{
				$res = \CIBlockProperty::getList(
					array(),
					array(
						'IBLOCK_ID' => $iblockId,
						'XML_ID' => $code
					)
				);
				if($row = $res->Fetch())
				{
					\CIBlockProperty::delete($row['ID']);
				}
			}
		}
	}

	private function reindexCatalog($parentIblock, $offerIblock)
	{
		$iterator = Iblock\SectionTable::getList([
			'select' => ['ID'],
			'filter' => ['=IBLOCK_ID' => $parentIblock, '=XML_ID' => '666']
		]);
		$parentSection = $iterator->fetch();
		unset($iterator);
		if (empty($parentSection))
			return;
		$iterator = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'IBLOCK_ID' => $parentIblock,
				'SECTION_ID' => $parentSection['ID'], 'INCLUDE_SUBSECTIONS' => 'Y',
				'CHECK_PERMISSIONS' => 'N'
			],
			false,
			['nTopCount' => 1],
			['ID', 'IBLOCK_ID']
		);
		$firstElement = $iterator->Fetch();
		unset($iterator);
		if (empty($firstElement))
			return;
		$borderId = (int)$firstElement['ID'] - 1;

		$iblockId = $offerIblock;
		$count = \Bitrix\Iblock\ElementTable::getCount(array(
			'=IBLOCK_ID' => $iblockId,
			'=WF_PARENT_ELEMENT_ID' => null
		));
		if ($count > 0)
		{
			$catalogReindex = new \CCatalogProductAvailable('', 0, 0);
			$catalogReindex->initStep($count, 0, 0);
			$catalogReindex->setParams(array(
				'IBLOCK_ID' => $iblockId
			));
			$catalogReindex->run();
		}

		// update only for catalog - some magic
		$iblockId = $parentIblock;
		$count = \Bitrix\Iblock\ElementTable::getCount(array(
			'=IBLOCK_ID' => $iblockId,
			'=WF_PARENT_ELEMENT_ID' => null,
			'>ID' => $borderId
		));
		if ($count > 0)
		{
			$catalogReindex = new \CCatalogProductAvailable('', 0, 0);
			$catalogReindex->initStep($count, 0, $borderId);
			$catalogReindex->setParams(array(
				'IBLOCK_ID' => $iblockId
			));
			$catalogReindex->run();
		}
	}

	private function initStepStorage()
	{
		if (!isset($_SESSION['LANDING_DEMO_STORAGE']) || !is_array($_SESSION['LANDING_DEMO_STORAGE']))
		{
			$_SESSION['LANDING_DEMO_STORAGE'] = [
				'STEP_ID' => self::STEP_ID_HIGHLOADBLOCK,
				'XML_LIST' => [
					'catalog',
					'catalog_prices',
					'catalog_sku',
					'catalog_prices_sku'
				],
				'IBLOCK_ID' => [
					'catalog' => 0,
					'catalog_prices' => 0,
					'catalog_sku' => 0,
					'catalog_prices_sku' => 0
				],
				'STEP_PARAMETERS' => []
			];
		}
	}

	private function getCurrentStep()
	{
		return $_SESSION['LANDING_DEMO_STORAGE']['STEP_ID'];
	}

	private function setCurrentStep($step)
	{
		$_SESSION['LANDING_DEMO_STORAGE']['STEP_ID'] = $step;
	}

	private function nextStep()
	{
		$index = array_search($this->getCurrentStep(), $this->catalogStepList);
		if (isset($this->catalogStepList[$index+1]))
			$this->setCurrentStep($this->catalogStepList[$index+1]);
	}

	private function resetStepParameters()
	{
		$_SESSION['LANDING_DEMO_STORAGE']['STEP_PARAMETERS'] = [];
	}

	private function getCurrentXml()
	{
		if (!isset($_SESSION['LANDING_DEMO_STORAGE']['STEP_PARAMETERS']['CURRENT_XML']))
			$_SESSION['LANDING_DEMO_STORAGE']['STEP_PARAMETERS']['CURRENT_XML'] = 'catalog';
		return $_SESSION['LANDING_DEMO_STORAGE']['STEP_PARAMETERS']['CURRENT_XML'];
	}

	private function getNextXml()
	{
		$index = array_search($this->getCurrentXml(), $_SESSION['LANDING_DEMO_STORAGE']['XML_LIST']);
		if (isset($_SESSION['LANDING_DEMO_STORAGE']['XML_LIST'][$index+1]))
			return $_SESSION['LANDING_DEMO_STORAGE']['XML_LIST'][$index+1];
		else
			return null;
	}

	private function setCurrentXml($xmlId)
	{
		$_SESSION['LANDING_DEMO_STORAGE']['STEP_PARAMETERS']['CURRENT_XML'] = $xmlId;
	}

	private function getXmlIblockId($xmlId)
	{
		return $_SESSION['LANDING_DEMO_STORAGE']['IBLOCK_ID'][$xmlId];
	}

	private function setXmlIblockId($xmlId, $iblockId)
	{
		$_SESSION['LANDING_DEMO_STORAGE']['IBLOCK_ID'][$xmlId] = $iblockId;
	}

	private function importXmlFileResultMessage($xmlId)
	{
		$result = '';
		switch ($xmlId)
		{
			case 'catalog':
				$result = Loc::getMessage('LANDING_CMP_LD_MESS_XML_IMPORT_CATALOG_COMPLETE');
				break;
			case 'catalog_prices':
				$result = Loc::getMessage('LANDING_CMP_LD_MESS_XML_IMPORT_CATALOG_PRICES_COMPLETE');
				break;
			case 'catalog_sku':
				$result = Loc::getMessage('LANDING_CMP_LD_MESS_XML_IMPORT_CATALOG_OFFERS_COMPLETE');
				break;
			case 'catalog_prices_sku':
				$result = Loc::getMessage('LANDING_CMP_LD_MESS_XML_IMPORT_CATALOG_OFFER_PRICES_COMPLETE');
				break;
		}
		return $result;
	}

	private function destroyStepStorage()
	{
		if (array_key_exists('LANDING_DEMO_STORAGE', $_SESSION))
			unset($_SESSION['LANDING_DEMO_STORAGE']);
	}
}