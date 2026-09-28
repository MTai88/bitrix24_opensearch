<?php

namespace Mtai\OpenSearch;

use Bitrix\Main\Request;

/**
 * Сборка конфигурации области поиска (опция scopes, JSON) из POST
 * страницы настроек — с валидацией кодов полей по реестру.
 */
final class OptionsSaver
{
	public static function collectFromRequest(Request $request): array
	{
		$scopes = [];

		// --- CRM-классика ---
		foreach (FieldRegistry::CRM_SCOPES as $scope)
		{
			$scopes[$scope] = [
				'enabled' => $request->getPost('entity_' . $scope) !== null,
				'fields' => self::filterCodes(
					(array)$request->getPost('scope_fields_' . $scope),
					self::crmScopeCodes($scope)
				),
			];
		}

		// --- Смарт-процессы (выбираются по названию) ---
		$smart = [];
		foreach ((array)$request->getPost('smart_types') as $typeId)
		{
			$typeId = (int)$typeId;
			if ($typeId <= 0)
			{
				continue;
			}
			$allowed = array_keys(FieldRegistry::systemFields('smart'));
			foreach (array_keys(FieldRegistry::smartUserFields($typeId)) as $code)
			{
				$allowed[] = $code;
			}
			$smart[$typeId] = [
				'fields' => self::filterCodes(
					(array)$request->getPost('scope_fields_smart_' . $typeId),
					$allowed
				),
			];
		}
		$scopes['smart'] = $smart;

		// --- Пользователи ---
		$scopes['user'] = [
			'enabled' => $request->getPost('search_users') !== null,
			'fields' => self::filterCodes(
				(array)$request->getPost('scope_fields_user'),
				array_keys(FieldRegistry::systemFields('user'))
			),
		];

		// --- Инфоблоки: элементы + свойства + разделы ---
		$iblocks = [];
		foreach ((array)$request->getPost('iblocks') as $iblockId)
		{
			$iblockId = (int)$iblockId;
			if ($iblockId <= 0)
			{
				continue;
			}

			$elementCodes = array_keys(FieldRegistry::systemFields('iblock_element'));
			$propertyCodes = array_keys(FieldRegistry::iblockProperties($iblockId));
			$sectionCodes = array_keys(FieldRegistry::systemFields('iblock_section'));
			foreach (array_keys(FieldRegistry::sectionUserFields($iblockId)) as $code)
			{
				$sectionCodes[] = $code;
			}

			$iblocks[$iblockId] = [
				'fields' => self::filterCodes(
					(array)$request->getPost('scope_fields_iblock_' . $iblockId),
					$elementCodes
				),
				'properties' => self::filterCodes(
					(array)$request->getPost('scope_fields_iblock_' . $iblockId),
					$propertyCodes
				),
				'sections' => [
					'enabled' => $request->getPost('scope_sections_iblock_' . $iblockId) !== null,
					'fields' => self::filterCodes(
						(array)$request->getPost('scope_section_fields_iblock_' . $iblockId),
						$sectionCodes
					),
				],
			];
		}
		$scopes['iblock'] = $iblocks;

		return $scopes;
	}

	/**
	 * Допустимые коды полей CRM-классики (системные + UF_).
	 *
	 * @return string[]
	 */
	private static function crmScopeCodes(string $scope): array
	{
		$codes = array_keys(FieldRegistry::systemFields($scope));
		foreach (array_keys(FieldRegistry::crmUserFields($scope)) as $code)
		{
			$codes[] = $code;
		}

		return $codes;
	}

	/**
	 * @param string[] $posted
	 * @param string[] $allowed
	 * @return string[]
	 */
	private static function filterCodes(array $posted, array $allowed): array
	{
		$result = [];
		foreach ($posted as $code)
		{
			if (is_string($code) && in_array($code, $allowed, true))
			{
				$result[] = $code;
			}
		}

		return array_values(array_unique($result));
	}
}
