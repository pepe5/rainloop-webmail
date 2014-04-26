<?php

namespace RainLoop\Providers\AddressBook\Classes;

use
	RainLoop\Providers\AddressBook\Enumerations\PropertyType,
	RainLoop\Providers\AddressBook\Classes\Property
;

class Contact
{
	/**
	 * @var string
	 */
	public $IdContact;

	/**
	 * @var string
	 */
	public $IdContactStr;

	/**
	 * @var string
	 */
	public $Display;

	/**
	 * @var int
	 */
	public $Changed;

	/**
	 * @var array
	 */
	public $Properties;

	/**
	 * @var array
	 */
	public $ReadOnly;

	/**
	 * @var int
	 */
	public $IdPropertyFromSearch;

	/**
	 * @var string
	 */
	public $Etag;

	public function __construct()
	{
		$this->Clear();
	}

	public function Clear()
	{
		$this->IdContact = '';
		$this->IdContactStr = '';
		$this->IdUser = 0;
		$this->Display = '';
		$this->Changed = \time();
		$this->Properties = array();
		$this->ReadOnly = false;
		$this->IdPropertyFromSearch = 0;
		$this->Etag = '';
	}
	
	public function UpdateDependentValues()
	{
		$sLastName = '';
		$sFirstName = '';
		$sEmail = '';
		$sOther = '';

		$oFullNameProperty = null;
		
		foreach ($this->Properties as /* @var $oProperty \RainLoop\Providers\AddressBook\Classes\Property */ &$oProperty)
		{
			if ($oProperty)
			{
				$oProperty->UpdateDependentValues();

				if (!$oFullNameProperty && PropertyType::FULLNAME === $oProperty->Type)
				{
					$oFullNameProperty =& $oProperty;
				}

				if (0 < \strlen($oProperty->Value))
				{
					if ('' === $sEmail && $oProperty->IsEmail())
					{
						$sEmail = $oProperty->Value;
					}
					else if ('' === $sLastName && PropertyType::LAST_NAME === $oProperty->Type)
					{
						$sLastName = $oProperty->Value;
					}
					else if ('' === $sFirstName && PropertyType::FIRST_NAME === $oProperty->Type)
					{
						$sFirstName = $oProperty->Value;
					}
					else if (\in_array($oProperty->Type, array(
						PropertyType::FULLNAME, PropertyType::PHONE
					)))
					{
						$sOther = $oProperty->Value;
					}
				}
			}
		}

		if (empty($this->IdContactStr))
		{
			$this->RegenerateContactStr();
		}

		$sDisplay = '';
		if (0 < \strlen($sLastName) || 0 < \strlen($sFirstName))
		{
			$sDisplay = \trim($sFirstName.' '.$sLastName);
		}

		if ('' === $sDisplay && 0 < \strlen($sEmail))
		{
			$sDisplay = \trim($sEmail);
		}

		if ('' === $sDisplay)
		{
			$sDisplay = $sOther;
		}

		$this->Display = \trim($sDisplay);
		
		if ($oFullNameProperty)
		{
			$oFullNameProperty->Value = $this->Display;
			$oFullNameProperty->UpdateDependentValues();
		}

		if (!$oFullNameProperty)
		{
			$this->Properties[] = new \RainLoop\Providers\AddressBook\Classes\Property(PropertyType::FULLNAME, $this->Display);
		}
	}

	/**
	 * @return array
	 */
	public function RegenerateContactStr()
	{
		$this->IdContactStr = \Sabre\DAV\UUIDUtil::getUUID();
	}

	/**
	 * @return array
	 */
	public function GetEmails()
	{
		$aResult = array();
		foreach ($this->Properties as /* @var $oProperty \RainLoop\Providers\AddressBook\Classes\Property */ &$oProperty)
		{
			if ($oProperty && $oProperty->IsEmail())
			{
				$aResult[] = $oProperty->Value;
			}
		}

		return \array_unique($aResult);
	}

	/**
	 * @return string
	 */
	public function CardDavNameUri()
	{
		return $this->IdContactStr.'.vcf';
	}

	/**
	 * @return string
	 */
	public function ToVCard($sPreVCard = '')
	{
		$this->UpdateDependentValues();

		$oVCard = null;
		if (0 < \strlen($sPreVCard))
		{
			try
			{
				$oVCard = \Sabre\VObject\Reader::read($sPreVCard);
			}
			catch (\Exception $oExc) {};
		}

		if (!$oVCard)
		{
			$oVCard = new \Sabre\VObject\Component\VCard();
		}
		
		$oVCard->VERSION = '3.0';
		$oVCard->PRODID = '-//RainLoop//'.APP_VERSION.'//EN';

		unset($oVCard->FN, $oVCard->EMAIL, $oVCard->TEL, $oVCard->URL);

		$sFirstName = $sLastName = $sMiddleName = $sSuffix = $sPrefix = '';
		foreach ($this->Properties as /* @var $oProperty \RainLoop\Providers\AddressBook\Classes\Property */ &$oProperty)
		{
			if ($oProperty)
			{
				$sAddKey = '';
				switch ($oProperty->Type)
				{
					case PropertyType::FULLNAME:
						$oVCard->FN = $oProperty->Value;
						break;
					case PropertyType::NICK_NAME:
						$oVCard->NICKNAME = $oProperty->Value;
						break;
					case PropertyType::FIRST_NAME:
						$sFirstName = $oProperty->Value;
						break;
					case PropertyType::LAST_NAME:
						$sLastName = $oProperty->Value;
						break;
					case PropertyType::MIDDLE_NAME:
						$sMiddleName = $oProperty->Value;
						break;
					case PropertyType::NAME_SUFFIX:
						$sSuffix = $oProperty->Value;
						break;
					case PropertyType::NAME_PREFIX:
						$sPrefix = $oProperty->Value;
						break;
					case PropertyType::EMAIl:
						if (empty($sAddKey))
						{
							$sAddKey = 'EMAIL';
						}
					case PropertyType::WEB_PAGE:
						if (empty($sAddKey))
						{
							$sAddKey = 'URL';
						}
					case PropertyType::PHONE:
						if (empty($sAddKey))
						{
							$sAddKey = 'TEL';
						}

						$aTypes = $oProperty->TypesAsArray();
						$oVCard->add($sAddKey, $oProperty->Value, \is_array($aTypes) && 0 < \count($aTypes) ? array('TYPE' => $aTypes) : null);
						break;
				}
			}
		}

		$oVCard->UID = $this->IdContactStr;
		$oVCard->N = array($sLastName, $sFirstName, $sMiddleName, $sPrefix, $sSuffix);
		$oVCard->REV = \gmdate('Ymd', $this->Changed).'T'.\gmdate('His', $this->Changed).'Z';

		return (string) $oVCard->serialize();
	}

	/**
	 * @param mixed $oProp
	 * @param bool $bOldVersion
	 * @return string
	 */
	private function getPropertyValueHelper($oProp, $bOldVersion)
	{
		$sValue = \trim($oProp);
		if ($bOldVersion && !isset($oProp->parameters['CHARSET']))
		{
			if (0 < \strlen($sValue))
			{
				$sEncValue = @\utf8_encode($sValue);
				if (0 === \strlen($sEncValue))
				{
					$sEncValue = $sValue;
				}

				$sValue = $sEncValue;
			}
		}

		return \MailSo\Base\Utils::Utf8Clear($sValue);
	}

	/**
	 * @param mixed $oProp
	 * @param bool $bOldVersion
	 * @return string
	 */
	private function addArrayPropertyHelper(&$aProperties, $oArrayProp, $iType)
	{
		foreach ($oArrayProp as $oProp)
		{
			$oTypes = $oProp ? $oProp['TYPE'] : null;
			$aTypes = $oTypes ? $oTypes->getParts() : array();
			$sValue = $oProp ? \trim($oProp->getValue()) : '';

			if (0 < \strlen($sValue))
			{
				if (!$oTypes || $oTypes->has('PREF'))
				{
					\array_unshift($aProperties, new Property($iType, $sValue, \implode(',', $aTypes)));
				}
				else
				{
					\array_push($aProperties, new Property($iType, $sValue, \implode(',', $aTypes)));
				}
			}
		}
	}

	public function PopulateByVCard($sVCard, $sEtag = '')
	{
		$this->Properties = array();
		
		if (!empty($sEtag))
		{
			$this->Etag = $sEtag;
		}

		try
		{
			$oVCard = \Sabre\VObject\Reader::read($sVCard);
		}
		catch (\Exception $oExc) {};

		$aProperties = array();
		if ($oVCard)
		{
			$bOldVersion = empty($oVCard->VERSION) ? false :
				\in_array((string) $oVCard->VERSION, array('2.1', '2.0', '1.0'));

			$this->IdContactStr = $oVCard->UID ? (string) $oVCard->UID : \Sabre\DAV\UUIDUtil::getUUID();

			if (isset($oVCard->FN) && '' !== \trim($oVCard->FN))
			{
				$sValue = $this->getPropertyValueHelper($oVCard->FN, $bOldVersion);
				$aProperties[] = new Property(PropertyType::FULLNAME, $sValue);
			}

			if (isset($oVCard->NICKNAME) && '' !== \trim($oVCard->NICKNAME))
			{
				$sValue = $sValue = $this->getPropertyValueHelper($oVCard->NICKNAME, $bOldVersion);
				$aProperties[] = new Property(PropertyType::NICK_NAME, $sValue);
			}

//			if (isset($oVCard->NOTE) && '' !== \trim($oVCard->NOTE))
//			{
//				$sValue = $this->getPropertyValueHelper($oVCard->NOTE, $bOldVersion);
//				$aProperties[] = new Property(PropertyType::NOTE, $sValue);
//			}

			if (isset($oVCard->N))
			{
				$aNames = $oVCard->N->getParts();
				foreach ($aNames as $iIndex => $sValue)
				{
					$sValue = \trim($sValue);
					if ($bOldVersion && !isset($oVCard->N->parameters['CHARSET']))
					{
						if (0 < \strlen($sValue))
						{
							$sEncValue = @\utf8_encode($sValue);
							if (0 === \strlen($sEncValue))
							{
								$sEncValue = $sValue;
							}

							$sValue = $sEncValue;
						}
					}

					$sValue = \MailSo\Base\Utils::Utf8Clear($sValue);
					switch ($iIndex) {
						case 0:
							$aProperties[] = new Property(PropertyType::LAST_NAME, $sValue);
							break;
						case 1:
							$aProperties[] = new Property(PropertyType::FIRST_NAME, $sValue);
							break;
						case 2:
							$aProperties[] = new Property(PropertyType::MIDDLE_NAME, $sValue);
							break;
						case 3:
							$aProperties[] = new Property(PropertyType::NAME_PREFIX, $sValue);
							break;
						case 4:
							$aProperties[] = new Property(PropertyType::NAME_SUFFIX, $sValue);
							break;
					}
				}
			}

			if (isset($oVCard->EMAIL))
			{
				$this->addArrayPropertyHelper($aProperties, $oVCard->EMAIL, PropertyType::EMAIl);
			}

			if (isset($oVCard->URL))
			{
				$this->addArrayPropertyHelper($aProperties, $oVCard->URL, PropertyType::WEB_PAGE);
			}

			if (isset($oVCard->TEL))
			{
				$this->addArrayPropertyHelper($aProperties, $oVCard->TEL, PropertyType::PHONE);
			}

			$this->Properties = $aProperties;
		}

		$this->UpdateDependentValues();
	}
}
