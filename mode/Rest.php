<?php
/**
 * Rest
 *
 * @package ko\Mode
 * @author zhangchu
 */

class Ko_Mode_Rest
{
	const ERROR_UNKNOWN                    = -10000000;
	const ERROR_MODULE_INVALID             = -10000001;
	const ERROR_RESOURCE_INVALID           = -10000002;
	const ERROR_RESOURCE_NOT_IMPLEMENTED   = -10000003;
	const ERROR_UNIQUE_NOT_DEFINED         = -10000004;
	const ERROR_METHOD_INVALID             = -10000005;
	const ERROR_METHOD_NOT_SUPPORTED       = -10000006;
	const ERROR_POST_INVALID               = -10000007;
	const ERROR_DELETE_INVALID             = -10000008;
	const ERROR_STYLE_INVALID              = -10000009;
	const ERROR_EXSTYLE_INVALID            = -10000010;

	public static $s_aPageInput = array('hash', array(
		'mode' => 'string',
		'num' => 'int',
		'no' => 'int',
		'boundary' => 'any',
	));

	public static $s_aPageOutput = array('hash', array(
		'mode' => 'string',
		'num' => 'int',
		'no' => 'int',
		'data_total' => 'int',
		'next' => 'bool',
		'next_boundary' => 'any',
	));

	/**
	 * array('list', array('hash', array(
	 *   'urilist' => array('list', array('hash', array(
	 *     'unique' => 'datatype',
	 *     'stylelist' => array('list', 'datatype'),
	 *     'exstylelist' => array('list', 'datatype'),
	 *   ))),
	 * )));
	 */
	protected $_aConf = array();

	private $_sModule = '';
	private $_sResource = '';
	private $_sId = '';
	private $_vKey;

	protected function _sGetClassname($sModule, $sResource)
	{
		return '';
	}

	public function aGet($sUri, $aInput)
	{
		return $this->aCall('GET', $sUri, $aInput);
	}

	public function aPost($sUri, $aInput)
	{
		return $this->aCall('POST', $sUri, $aInput);
	}

	public function aPut($sUri, $aInput)
	{
		return $this->aCall('PUT', $sUri, $aInput);
	}

	public function aDelete($sUri, $aInput)
	{
		return $this->aCall('DELETE', $sUri, $aInput);
	}

	public function aCall($sMethod, $sUri, $aInput)
	{
		try
		{
			$data = $this->_aCall($sMethod, $sUri, $aInput);
		}
		catch (Exception $e)
		{
			return array('errno' => $e->getCode(), 'error' => $e->getMessage());
		}
		return array('errno' => 0, 'error' => '', 'data' => $data);
	}

	private function _aCall($sMethod, $sUri, $aInput)
	{
		$items = explode('/', $sUri);
		$this->_vKey = $this->_sId = array_pop($items);
		$this->_sResource = array_pop($items);
		$this->_sModule = implode('/', $items);

		if (!isset($this->_aConf[$this->_sModule]))
		{
			throw new Exception('模块不存在', self::ERROR_MODULE_INVALID);
		}
		if (!isset($this->_aConf[$this->_sModule]['urilist'][$this->_sResource]))
		{
			throw new Exception('资源不存在', self::ERROR_RESOURCE_INVALID);
		}
		$resConf = &$this->_aConf[$this->_sModule]['urilist'][$this->_sResource];
		if ('' !== $this->_sId && !isset($resConf['unique']))
		{
			throw new Exception('唯一键类型未定义', self::ERROR_UNIQUE_NOT_DEFINED);
		}

		$classname = $this->_sGetClassname($this->_sModule, $this->_sResource);
		if (!class_exists($classname))
		{
			throw new Exception('资源还没有实现', self::ERROR_RESOURCE_NOT_IMPLEMENTED);
		}
		$api = Ko_Tool_Singleton::OInstance($classname);
		if ('' !== $this->_sId && method_exists($api, 'str2key'))
		{
			$this->_vKey = $api->str2key($this->_sId);
		}

		$funcname = $this->_sGetFuncInfo($sMethod, $resConf, $aInput, $para);

		$data = $this->_vGetData($api, $funcname, $aInput, $para);

		return $this->_vAdapterData($data, $funcname, $resConf, $aInput);
	}

	private function _vAdapterData($data, $funcname, $resConf, $aInput)
	{
		switch ($funcname)
		{
			case 'get':
				$data = Ko_Tool_Adapter::VConv($data, $resConf['stylelist'][$aInput['data_style']]);
				break;
			case 'getMulti':
				$hashrule = array(
					'list' => array('list', $resConf['stylelist'][$aInput['data_style']]),
					'page' => self::$s_aPageOutput,
				);
				if (isset($aInput['ex_style']))
				{
					$hashrule['ex'] = $resConf['exstylelist'][$aInput['ex_style']];
				}
				$data = Ko_Tool_Adapter::VConv($data, array('hash', $hashrule));
				break;
			case 'post':
				$hashrule = array(
					'key' => $resConf['unique'],
				);
				if (isset($aInput['after_style']))
				{
					$hashrule['after'] = $resConf['stylelist'][$aInput['after_style']];
				}
				$data = Ko_Tool_Adapter::VConv($data, array('hash', $hashrule));
				break;
			case 'put':
				$hashrule = array(
					'key' => $resConf['unique'],
				);
				if (isset($aInput['before_style']))
				{
					$hashrule['before'] = $resConf['stylelist'][$aInput['before_style']];
				}
				if (isset($aInput['after_style']))
				{
					$hashrule['after'] = $resConf['stylelist'][$aInput['after_style']];
				}
				$data = Ko_Tool_Adapter::VConv($data, array('hash', $hashrule));
				break;
			case 'delete':
				$hashrule = array(
					'key' => $resConf['unique'],
				);
				if (isset($aInput['before_style']))
				{
					$hashrule['before'] = $resConf['stylelist'][$aInput['before_style']];
				}
				$data = Ko_Tool_Adapter::VConv($data, array('hash', $hashrule));
				break;
			case 'postMulti':
			case 'putMulti':
			case 'deleteMulti':
				$data = null;
				break;
		}
		return $data;
	}

	private function _vGetData($api, $funcname, $aInput, $para)
	{
		if (!method_exists($api, $funcname))
		{
			switch($funcname)
			{
				case 'postMulti':
					$this->_vPostMulti2Post($api, $aInput);
					break;
				case 'putMulti':
					$this->_vPutMulti2Put($api, $aInput);
					break;
				case 'deleteMulti':
					$this->_vDeleteMulti2Delete($api, $aInput);
					break;
				default:
					throw new Exception('资源不支持该方法', self::ERROR_METHOD_NOT_SUPPORTED);
			}
			return null;
		}
		return $this->_vCallApiFunc($api, $funcname, $para);
	}

	private function _vPostMulti2Post($api, $aInput)
	{
		$this->_vMulti2Single($api, $aInput, 'post', array('update'));
	}

	private function _vPutMulti2Put($api, $aInput)
	{
		$this->_vMulti2Single($api, $aInput, 'put', array('key', 'update'));
	}

	private function _vDeleteMulti2Delete($api, $aInput)
	{
		$this->_vMulti2Single($api, $aInput, 'delete', array('key'));
	}

	private function _vMulti2Single($api, $aInput, $funcname, $aVar)
	{
		if (!method_exists($api, $funcname))
		{
			throw new Exception('资源不支持该方法', self::ERROR_METHOD_NOT_SUPPORTED);
		}
		else
		{
			foreach ($aInput['list'] as $v)
			{
				$para = array();
				foreach ($aVar as $var)
				{
					$para[] = $v[$var];
				}
				$this->_vCallApiFunc($api, $funcname, $para);
			}
		}
	}

	private function _vCallApiFunc($api, $funcname, $para)
	{
		try
		{
			$data = call_user_func_array(array($api, $funcname), $para);
		}
		catch (Exception $e)
		{
			if ($e->getCode() <= self::ERROR_UNKNOWN)
			{
				throw new Exception($e->getCode().': '.$e->getMessage(), self::ERROR_UNKNOWN);
			}
			else
			{
				throw $e;
			}
		}
		return $data;
	}

	private function _sGetFuncInfo($sMethod, $resConf, &$aInput, &$para)
	{
		switch ($sMethod)
		{
			case 'GET':
				return $this->_sGetGetFuncInfo($resConf, $aInput, $para);
			case 'POST':
				return $this->_sGetPostFuncInfo($resConf, $aInput, $para);
			case 'PUT':
				return $this->_sGetPutFuncInfo($resConf, $aInput, $para);
			case 'DELETE':
				return $this->_sGetDeleteFuncInfo($resConf, $aInput, $para);
		}
		throw new Exception('方法不允许', self::ERROR_METHOD_INVALID);
	}

	private function _sGetGetFuncInfo($resConf, &$aInput, &$para)
	{
		$this->_vNormalizeStyle($resConf, $aInput, 'data_style', false);
		if ('' !== $this->_sId || !isset($resConf['unique']))
		{
			$funcname = 'get';
			$para = array(
				$this->_vKey,
				$this->_aGetStylePara($aInput, 'data_style', 'data_decorate'),
			);
		}
		else
		{
			$aInput['page'] = Ko_Tool_Adapter::VConv($aInput['page'], self::$s_aPageInput);
			$this->_vNormalizeExStyle($resConf, $aInput, 'ex_style');
			$funcname = 'getMulti';
			$para = array(
				$this->_aGetStylePara($aInput, 'data_style', 'data_decorate'),
				$aInput['page'],
				$aInput['filter'],
				$this->_aGetStylePara($aInput, 'ex_style', 'ex_decorate'),
			);
		}
		return $funcname;
	}

	private function _sGetPostFuncInfo($resConf, &$aInput, &$para)
	{
		if ('' !== $this->_sId || !isset($resConf['unique']))
		{
			throw new Exception('指定资源不能进行POST操作', self::ERROR_POST_INVALID);
		}
		if (!isset($aInput['list']))
		{
			$this->_vNormalizeStyle($resConf, $aInput, 'after_style', true);
			$funcname = 'post';
			$para = array(
				$aInput['update'],
				$this->_aGetStylePara($aInput, 'after_style', 'after_decorate'),
			);
		}
		else
		{
			$funcname = 'postMulti';
			$para = array(
				$aInput['list'],
			);
		}
		return $funcname;
	}

	private function _sGetPutFuncInfo($resConf, &$aInput, &$para)
	{
		if ('' !== $this->_sId || !isset($resConf['unique']))
		{
			$this->_vNormalizeStyle($resConf, $aInput, 'before_style', true);
			$this->_vNormalizeStyle($resConf, $aInput, 'after_style', true);
			$funcname = 'put';
			$para = array(
				$this->_vKey,
				$aInput['update'],
				$this->_aGetStylePara($aInput, 'before_style', 'before_decorate'),
				$this->_aGetStylePara($aInput, 'after_style', 'after_decorate'),
			);
		}
		else
		{
			$funcname = 'putMulti';
			$para = array(
				$aInput['list'],
			);
		}
		return $funcname;
	}

	private function _sGetDeleteFuncInfo($resConf, &$aInput, &$para)
	{
		if (!isset($resConf['unique']))
		{
			throw new Exception('指定资源不能进行DELETE操作', self::ERROR_DELETE_INVALID);
		}
		if (!isset($aInput['list']))
		{
			$this->_vNormalizeStyle($resConf, $aInput, 'before_style', true);
			$funcname = 'delete';
			$para = array(
				$this->_vKey,
				$this->_aGetStylePara($aInput, 'before_style', 'before_decorate'),
			);
		}
		else
		{
			$funcname = 'deleteMulti';
			$para = array(
				$aInput['list'],
			);
		}
		return $funcname;
	}

	private function _vNormalizeStyle($resConf, &$aInput, $key, $bIsOption)
	{
		if (!$bIsOption || isset($aInput[$key]))
		{
			if (!isset($aInput[$key]))
			{
				$aInput[$key] = 'default';
			}
			if (!isset($resConf['stylelist'][$aInput[$key]]))
			{
				throw new Exception('数据样式不存在', self::ERROR_STYLE_INVALID);
			}
		}
	}

	private function _vNormalizeExStyle($resConf, &$aInput, $key)
	{
		if (isset($aInput[$key]))
		{
			if (!isset($resConf['stylelist'][$aInput[$key]]))
			{
				throw new Exception('扩展数据样式不存在', self::ERROR_EXSTYLE_INVALID);
			}
		}
	}

	private function _aGetStylePara($aInput, $styleKey, $decorateKey)
	{
		return  isset($aInput[$styleKey]) ? array(
			'style' => $aInput[$styleKey],
			'decorate' => $aInput[$decorateKey],
		) : null;
	}
}