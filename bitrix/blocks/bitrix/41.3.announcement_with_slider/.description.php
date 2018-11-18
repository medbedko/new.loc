<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use \Bitrix\Main\Localization\Loc;

return array(
	'block' => array(
		'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NAME'),
		'section' => array('cover'),
	),
	'cards' => array(
		'.landing-block-node-card' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_CARDS_LANDINGBLOCKNODECARD'),
			'label' => array('.landing-block-node-card-img'),
		),
	),
	'nodes' => array(
		'.landing-block-node-bgimg' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBGIMG'),
			'type' => 'img',
			'dimensions' => array('width' => 1920, 'height' => 1281),
			'allowInlineEdit' => false,
		),
		'.landing-block-node-title' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODETITLE'),
			'type' => 'text',
		),
		'.landing-block-node-date-icon' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEDATEICON2'),
			'type' => 'icon',
		),
		'.landing-block-node-date-title' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEDATETITLE2'),
			'type' => 'text',
		),
		'.landing-block-node-date-text' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEDATETEXT2'),
			'type' => 'text',
		),
		'.landing-block-node-place-icon' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEPLACEICON2'),
			'type' => 'icon',
		),
		'.landing-block-node-place-title' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEPLACETITLE2'),
			'type' => 'text',
		),
		'.landing-block-node-place-text' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEPLACETEXT2'),
			'type' => 'text',
		),
		'.landing-block-node-button' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBUTTON'),
			'type' => 'link',
		),
		'.landing-block-node-card-img' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODECARDIMG'),
			'type' => 'img',
			'dimensions' => array('width' => 1000, 'height' => 667),
			'allowInlineEdit' => false,
		),
		'.landing-block-node-block-title' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBLOCKTITLE'),
			'type' => 'text',
		),
		'.landing-block-node-block-subtitle' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBLOCKSUBTITLE'),
			'type' => 'text',
		),
		'.landing-block-node-block-text' => array(
			'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBLOCKTEXT'),
			'type' => 'text',
		),
	),
	'style' => array(
		'block' => array(
			'type' => array('block-default-wo-background'),
		),
		'nodes' => array(
			'.landing-block-node-title' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODETITLE'),
					'type' => 'typo',
				),
			'.landing-block-node-date-title' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEDATETITLE2'),
					'type' => 'typo',
				),
			'.landing-block-node-date-text' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEDATETEXT2'),
					'type' => 'typo',
				),
			'.landing-block-node-place-title' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEPLACETITLE2'),
					'type' => 'typo',
				),
			'.landing-block-node-place-text' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEPLACETEXT2'),
					'type' => 'typo',
				),
			'.landing-block-node-button' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBUTTON'),
					'type' => 'button',
				),
			'.landing-block-node-block-title' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBLOCKTITLE'),
					'type' => 'typo',
				),
			'.landing-block-node-block-subtitle' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBLOCKSUBTITLE'),
					'type' => 'typo',
				),
			'.landing-block-node-block-text' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBLOCKTEXT'),
					'type' => 'typo',
				),
			'.landing-block-node-bgimg' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEBGIMG'),
					'type' => 'background-overlay',
				),
			'.landing-block-node-date-icon-container' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEDATEICON2'),
					'type' => 'color',
				),
			'.landing-block-node-place-icon-container' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODEPLACEICON2'),
					'type' => 'color',
				),
			'.landing-block-node-container' =>
				array(
					'name' => Loc::getMessage('LANDING_BLOCK_41.3.ANNOUNCEMENT_WITH_SLIDER_NODES_LANDINGBLOCKNODE_CONTAINER'),
					'type' => 'animation',
				),
		),
	),
	'assets' => array(
		'ext' => array('landing_carousel'),
	),
);