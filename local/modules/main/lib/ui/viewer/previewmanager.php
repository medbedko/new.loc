<?php

namespace Bitrix\Main\UI\Viewer;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Main\UI\Viewer\Transformation\TransformerManager;
use Bitrix\Main\Web\Uri;
use Bitrix\Main\Security;
use Bitrix\Main\Engine\Response;

final class PreviewManager
{
	const GET_KEY_TO_SEND_PREVIEW_CONTENT    = 'ibxPreview';
	const GET_KEY_TO_SHOW_IMAGE              = 'ibxShowImage';
	const HEADER_TO_SEND_PREVIEW             = 'BX-Viewer';
	const HEADER_TO_RESIZE_IMAGE             = 'BX-Viewer-image';
	const HEADER_TO_GET_SOURCE               = 'BX-Viewer-src';
	const HEADER_TO_RUN_FORCE_TRANSFORMATION = 'BX-Viewer-force-transformation';

	/** @var TransformerManager  */
	private $transformer;
	private $rendererList = [];
	/**
	 * @var HttpRequest
	 */
	private $httpRequest;

	public function __construct(HttpRequest $httpRequest = null)
	{
		$this->httpRequest = $httpRequest? : Context::getCurrent()->getRequest();
		$this->transformer = $this->buildTransformer();
		$this->buildViewRendererList();
	}

	private function buildTransformer()
	{
		if (!Loader::includeModule('transformer'))
		{
			return null;
		}

		return new TransformerManager();
	}

	private function buildViewRendererList()
	{
		$default = [
			Renderer\Pdf::class,
			Renderer\Video::class,
			Renderer\Image::class,
		];

		$event = new Event('main', 'onPreviewRendererBuildList');
		$event->send();

		$additionalList = [];
		foreach ($event->getResults() as $result)
		{
			if ($result->getType() != EventResult::SUCCESS)
			{
				continue;
			}
			$result = $result->getParameters();
			if (!is_array($result))
			{
				throw new SystemException('Wrong event result. Must be array.');
			}

			foreach ($result as $class)
			{
				if (!is_string($class) || !class_exists($class))
				{
					throw new SystemException('Wrong event result. There is not a class.');
				}

				if (!is_subclass_of($class, Renderer\Renderer::class, true))
				{
					throw new SystemException("Wrong event result. {$class} is not a subclass of " . Renderer\Renderer::class);
				}

				$additionalList[] = $class;
			}
		}

		$this->rendererList = array_merge($default, $additionalList);
	}

	public function isInternalRequest($file, $options)
	{
		if (!is_array($file) || empty($file['ID']))
		{
			return false;
		}

		if (!empty($options['prevent_work_with_preview']))
		{
			return false;
		}

		if ($this->httpRequest->get(self::GET_KEY_TO_SEND_PREVIEW_CONTENT))
		{
			return true;
		}

		if ($this->httpRequest->get(self::GET_KEY_TO_SHOW_IMAGE))
		{
			return true;
		}

		if ($this->httpRequest->getHeader(self::HEADER_TO_SEND_PREVIEW))
		{
			return true;
		}

		if ($this->httpRequest->getHeader(self::HEADER_TO_RUN_FORCE_TRANSFORMATION))
		{
			return true;
		}

		if ($this->httpRequest->getHeader(self::HEADER_TO_RESIZE_IMAGE))
		{
			return true;
		}

		return false;
	}

	public function processViewByUserRequest($file, $options)
	{
		if ($this->httpRequest->get(self::GET_KEY_TO_SEND_PREVIEW_CONTENT))
		{
			$this->sendPreviewContent($file, $options);
		}
		elseif ($this->httpRequest->get(self::GET_KEY_TO_SHOW_IMAGE))
		{
			$this->showImage($file, $options);
		}
		elseif ($this->httpRequest->getHeader(self::HEADER_TO_RUN_FORCE_TRANSFORMATION))
		{
			$this->sendPreview($file, true);
		}
		elseif ($this->httpRequest->getHeader(self::HEADER_TO_SEND_PREVIEW))
		{
			$this->sendPreview($file);
		}
		elseif ($this->httpRequest->getHeader(self::HEADER_TO_RESIZE_IMAGE))
		{
			$this->sendResizedImage($file);
		}
	}

	protected function sendPreviewContent($file, $options)
	{
		$possiblePreviewFileId = $this->unsignFileId($this->httpRequest->get(self::GET_KEY_TO_SEND_PREVIEW_CONTENT));
		$filePreview = $this->getFilePreviewEntryByFileId($file);
		if (!$filePreview || empty($filePreview['PREVIEW_ID']) || $filePreview['PREVIEW_ID'] != $possiblePreviewFileId)
		{
			return;
		}
		//get name for preview file
		\CFile::viewByUser($filePreview['PREVIEW_ID'], [
			'prevent_work_with_preview' => true,
			'attachment_name' => $file['ORIGINAL_NAME'],
		]);
	}

	protected function showImage($file, $options)
	{
		if (!is_array($options))
		{
			$options = [];
		}
		$options['force_download'] = false;
		$options['prevent_work_with_preview'] = true;
		\CFile::viewByUser($file, $options);
	}

	protected function sendResizedImage($file)
	{
		$fileView = $this->getByImage($file['ID'], $this->getSourceUri());
		if ($fileView)
		{
			$fileView->render();

			Application::getInstance()->terminate();
		}
	}

	protected function prepareRenderParameters($file)
	{
		$getContentType = function() use ($file){
			$filePreviewData = $this->getViewFileData($file);
			if (!$filePreviewData)
			{
				return null;
			}

			return $filePreviewData['CONTENT_TYPE'];
		};

		$getSourceUri = function() use ($file){
			$filePreviewData = $this->getViewFileData($file);
			if (!$filePreviewData)
			{
				return null;
			}

			return $this->getSourceUri()->addParams([
				self::GET_KEY_TO_SEND_PREVIEW_CONTENT => $this->signFileId($filePreviewData['ID']),
			]);
		};

		return [
			'alt' => [
				'contentType' => $getContentType->bindTo($this),
				'sourceUri' => $getSourceUri->bindTo($this),
			],
		];
	}

	protected function sendPreview($file, $forceTransformation = false)
	{
		$response = Response\AjaxJson::createError();

		$render = $this->buildRenderByFile(
			$file['ORIGINAL_NAME'],
			$file['CONTENT_TYPE'],
			$this->getSourceUri(),
			$this->prepareRenderParameters($file)
		);
		if ($render instanceof Renderer\Stub || $forceTransformation)
		{
			$filePreviewData = $this->getViewFileData($file);
			if ($filePreviewData)
			{
				$sourceUri = $this->getSourceUri()->addParams([
					self::GET_KEY_TO_SEND_PREVIEW_CONTENT => $this->signFileId($filePreviewData['ID']),
				]);

				$render = $this->buildRenderByFile(
					$filePreviewData['ORIGINAL_NAME'],
					$filePreviewData['CONTENT_TYPE'],
					$sourceUri
				);
			}
			else
			{
				$generatePreview = $this->generatePreview($file['ID']);
				if ($generatePreview->isSuccess())
				{
					$render = null;
					$response = Response\AjaxJson::createSuccess($generatePreview->getData());
				}
				else
				{
					$response = Response\AjaxJson::createError($generatePreview->getErrorCollection());
				}
			}
		}

		if ($render)
		{
			$response = Response\AjaxJson::createSuccess([
				'html' => $render->render(),
				'data' => $render->getData(),
			]);
		}

		Application::getInstance()->end(0, $response);
	}

	public function generatePreview($fileId)
	{
		if (!$this->transformer)
		{
			return null;
		}

		//todo return status (OK, WAIT, ERROR) or some result.

		return $this->transformer->transform($fileId);
	}

	protected function getSourceUri()
	{
		$sourceSrc = $this->httpRequest->getHeader(self::HEADER_TO_GET_SOURCE);
		if (!$sourceSrc)
		{
			$sourceSrc = $this->httpRequest->getRequestUri();
		}
		$sourceUri = new Uri($sourceSrc);

		return $sourceUri;
	}

	protected function signFileId($fileId)
	{
		$signer = new Security\Sign\Signer;

		return $signer->sign(
			base64_encode(serialize($fileId)),
			'previewfile'
		);
	}

	protected function unsignFileId($signedString)
	{
		$signer = new Security\Sign\Signer;

		$unsignedParameters = $signer->unsign(
			$signedString,
			'previewfile'
		);

		return unserialize(base64_decode($unsignedParameters));
	}

	public function getByImage($fileId, Uri $sourceUri)
	{
		$fileData = $this->getFileData($fileId);
		if (!$fileData)
		{
			return null;
		}

		$renderer = $this->buildRenderByFile(
			$fileData['ORIGINAL_NAME'],
			$fileData['CONTENT_TYPE'],
			$sourceUri,
			[
				'originalImage' => $fileData,
			]
		);
		if (!($renderer instanceof Renderer\Image))
		{
			return null;
		}

		return $renderer;
	}

	protected function getViewFileData(array $fileData)
	{
		static $cache = [];

		if (empty($fileData['ID']))
		{
			return null;
		}

		if (isset($cache[$fileData['ID']]) || array_key_exists($fileData['ID'], $cache))
		{
			return $cache[$fileData['ID']];
		}

		$filePreview = $this->getFilePreviewEntryByFileId($fileData['ID']);
		if (!$filePreview)
		{
			$cache[$fileData['ID']] = null;

			return null;
		}

		$cache[$fileData['ID']] = $this->getFileData($filePreview['PREVIEW_ID']);

		return $cache[$fileData['ID']];
	}

	protected function getFilePreviewEntryByFileId($fileId)
	{
		$row = FilePreviewTable::getList([
			'filter' => [
				'=FILE_ID' => $fileId,
			],
			'limit' => 1,
		])->fetch();

		return $row;
	}

	protected function buildRenderByFile($originalName, $contentType, Uri $sourceUri, array $options = [])
	{
		$options['contentType'] = $contentType;
		$rendererClass = $this->getRenderClassByContentType($contentType);

		$reflectionClass = new \ReflectionClass($rendererClass);
		/** @see \Bitrix\Main\UI\Viewer\Renderer\Renderer::__construct */
		return $reflectionClass->newInstance($originalName, $sourceUri, $options);
	}

	public function getRenderClassByContentType($contentType)
	{
		foreach ($this->rendererList as $rendererClass)
		{
			/** @var Renderer\Renderer $rendererClass */
			if (in_array($contentType, $rendererClass::getAllowedContentTypes(), true))
			{
				return $rendererClass;
			}
		}

		return Renderer\Stub::class;
	}

	/**
	 * Temporary dirty hack. It is here while we do not solve the problem of race conditions in b_file managed cache.
	 * Get file data from b_file on $fileId.
	 *
	 * @param int $fileId
	 * @param bool $cacheCleaned
	 * @return array|false
	 */
	protected function getFileData($fileId, $cacheCleaned = false)
	{
		$fileData = \CFile::getByID($fileId)->fetch();
		if ($fileData === false && !$cacheCleaned)
		{
			global $DB;
			$strSql = "SELECT ID FROM b_file WHERE ID=" . $fileId;
			$dbResult = $DB->Query($strSql, false, "FILE: " . __FILE__ . "<br>LINE: " . __LINE__);
			if ($dbData = $dbResult->Fetch())
			{
				\CFile::CleanCache($fileId);

				return $this->getFileData($fileId, true);
			}
		}

		return $fileData;
	}
}