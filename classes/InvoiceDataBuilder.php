<?php
/**
 * Класс для формирования данных счета на оплату из сделки Битрикс24
 * Адаптирован под структуру config.php пользователя
 * 
 * @package Integration
 */

namespace Integration;

class InvoiceDataBuilder {

    /**
     * Формирует данные для счета на оплату из сделки
     * 
     * @param int $dealId ID сделки
     * @param array $config Конфигурация интеграции (ваш формат)
     * @return array Массив данных для 1С (одна запись)
     * @throws \Exception
     */
    public static function buildFromDeal($dealId, $config) {
        // 1. Получение сделки
		$dbDeal = \CCrmDeal::GetList(
			[],
			['=ID' => $dealId],
			[
				'ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID',
				'COMPANY_ID', 'CONTACT_ID', 'COMPANY_TITLE',
				'UF_CRM_1773266651',  // Организация
				'UF_CRM_INN', 'UF_CRM_KPP',  // Реквизиты
				'UF_CRM_NDS_MODE', 'UF_CRM_DELIVERY_PRICE', 'UF_CRM_SHIPPING_DATE'
			],
			false,
			['nTopCount' => 1]
		);
		$deal = $dbDeal->Fetch();
		
		if (!$deal || !is_array($deal)) {
			throw new \Exception("Сделка #{$dealId} не найдена");
		}

        // 2. Получение товаров
        $products = self::getDealProductsMapped($dealId, $config);
        if (empty($products)) {
            throw new \Exception('В сделке отсутствуют товары');
        }

        // 3. Данные контрагента
        $companyData = self::getCounterpartyData($deal, $config);

        // 4. Получение УИД организации из поля сделки

		// Ключ поля в сделке, где хранится ID элемента списка организаций
		$organizationFieldCode = 'UF_CRM_1773266651';
		$organizationElementId = $deal[$organizationFieldCode] ?? null;
		
		$organizationUuid = '';
		
		// Если в сделке указан элемент списка — получаем УИД из 1С через PROPERTY_1096
		if (!empty($organizationElementId) && \Bitrix\Main\Loader::includeModule('iblock')) {
			try {
				$iblockId = 59;  // ID инфоблока "Организации"
				$propertyId = 1096;  // ID свойства "УИД организации в 1С"
				
				// Получаем свойство элемента
				$dbProp = \CIBlockElement::GetProperty(
					$iblockId,
					(int)$organizationElementId,
					['sort' => 'asc'],
					['ID' => $propertyId]
				);
				$prop = $dbProp->Fetch();
				
				if ($prop && !empty($prop['VALUE'])) {
					$organizationUuid = trim((string)$prop['VALUE']);
					Logger::debug('УИД организации получен из элемента списка', [
						'deal_id' => $dealId,
						'element_id' => $organizationElementId,
						'organization_uid' => $organizationUuid
					]);
				}
			} catch (\Exception $e) {
				Logger::warning('Ошибка получения УИД организации из элемента списка', [
					'deal_id' => $dealId,
					'element_id' => $organizationElementId,
					'error' => $e->getMessage()
				]);
			}
		}
		
		// Fallback: если не получили из элемента — используем конфиг
		if (empty($organizationUuid)) {
			$category = isset($deal['CATEGORY_ID']) ? (string)$deal['CATEGORY_ID'] : '';
			$retailCat = isset($config['deal_categories']['retail']) ? (string)$config['deal_categories']['retail'] : '0';
			$wholesaleCat = isset($config['deal_categories']['wholesale']) ? (string)$config['deal_categories']['wholesale'] : '1';
			
			if ($category === $wholesaleCat) {
				$organizationUuid = $config['default_organization_uid']['wholesale']
					?? $config['default_organization_uid']['retail']
					?? '';
			} elseif ($category === $retailCat) {
				$organizationUuid = $config['default_organization_uid']['retail']
					?? $config['default_organization_uid']['wholesale']
					?? '';
			} else {
				$organizationUuid = $config['default_organization_uid']['retail']
					?? $config['default_organization_uid']['wholesale']
					?? '';
			}
			
			Logger::debug('УИД организации получен из конфига (fallback)', [
				'deal_id' => $dealId,
				'category' => $category,
				'organization_uid' => $organizationUuid
			]);
		}
		
		// Структура = организация (если в 1С структура привязана к организации)
		$structureUuid = $organizationUuid;

        // 5. Дата в формате ISO 8601 с таймзоной
        $dateObj = new \DateTime();
        $dateObj->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $invoiceDate = $dateObj->format(\DateTime::ATOM);

        // 6. Режим НДС: 0 = "в сумме", 1 = "сверху"
        $ndsMode = !empty($deal['UF_CRM_NDS_MODE']) 
            ? (int)$deal['UF_CRM_NDS_MODE'] 
            : 1;

        // 7. Доставка
        $deliveryName = $deal['DELIVERY_LOCATION'] 
                     ?? $deal['ADDRESS'] 
                     ?? $deal['UF_CRM_DELIVERY_ADDRESS'] 
                     ?? '';
        $deliveryPrice = !empty($deal['UF_CRM_DELIVERY_PRICE']) 
            ? (float)$deal['UF_CRM_DELIVERY_PRICE'] 
            : 0;

        // 8. Дата отгрузки
        $shippingDate = '';
        if (!empty($deal['UF_CRM_SHIPPING_DATE'])) {
            try {
                $shipDt = new \DateTime($deal['UF_CRM_SHIPPING_DATE']);
                $shippingDate = $shipDt->format(\DateTime::ATOM);
            } catch (\Exception $e) {
                $shippingDate = '';
            }
        }

        // 9. Формирование итоговой структуры
        return [
            "id" => (string)$dealId,
            "date" => $invoiceDate,
            "inn" => $companyData['INN'] ?? '',
            "kpp" => $companyData['KPP'] ?? '',
            "id_partner" => $companyData['external_id'] ?? '',
            "shipping_date" => $shippingDate,
            "payName" => '',
            "NDS" => (string)$ndsMode,
            "delivery" => $deliveryName,
            "deliveryPrice" => $deliveryPrice,
            "organization" => $organizationUuid,
            "structure" => $structureUuid,
            "items" => $products
        ];
    }

    /**
     * Получение и маппинг товаров сделки в формат 1С
     */
    private static function getDealProductsMapped($dealId, $config) {
        $items = [];
        $key = 1;
        
        $dbResult = \CCrmProductRow::GetList(
            ['SORT' => 'ASC'],
            ['OWNER_TYPE' => 'D', 'OWNER_ID' => $dealId],
            false, false, []
        );
        
        while ($row = $dbResult->Fetch()) {
            $offerId = self::getProductOfferId($row['PRODUCT_ID'] ?? 0);
            $lotUuid = 'f819f5f2-1cdd-11ea-8116-0050569b6607';
            
            $basePrice = (float)($row['PRICE'] ?? 0);
            $discountPercent = (float)($row['DISCOUNT_PERCENT'] ?? 0);
            $discountPrice = (float)($row['DISCOUNT_PRICE'] ?? 0);
            
            $finalPrice = $basePrice;
            if ($discountPercent > 0) {
                $finalPrice = $basePrice * (1 - $discountPercent / 100);
            } elseif ($discountPrice > 0) {
                $finalPrice = $basePrice - $discountPrice;
            }
            
            $items[] = [
                "key" => $key++,
                "offer_Id" => (string)$offerId,
                "lot" => (string)$lotUuid,
                "quantity" => (float)($row['QUANTITY'] ?? 1),
                "basePrice" => round($basePrice, 2),
                "finalPrice" => round($finalPrice, 2),
                "discountsPercent" => round($discountPercent, 2),
                "discountsPrice" => round($discountPrice, 2)
            ];
        }
        
        return $items;
    }

    /**
     * Получение внешнего кода товара (offer_Id) из 1С
     */
	private static function getProductOfferId($productId) {
		if (!$productId) {
			return '';
		}
		
		$product = \CCrmProduct::GetById($productId);
		if (!$product || !is_array($product)) {
			return '';
		}
		
		// 🔹 Ключевые параметры (должны совпадать с OneCClient::getProductCode1C)
		$iblockId = 14;      // ID инфоблока товаров в вашем Битрикс24
		$propertyId = 1085;  // ID свойства "Код номенклатуры 1С"
		
		// 🔹 Основной способ: через CIBlockElement::GetProperty
		if (class_exists('\CIBlockElement')) {
			$dbProp = \CIBlockElement::GetProperty(
				$iblockId,
				$productId,
				['sort' => 'asc'],
				['ID' => $propertyId]
			);
			$prop = $dbProp->Fetch();
			
			if ($prop && !empty($prop['VALUE'])) {
				return trim((string)$prop['VALUE']);
			}
		}
		
		// 🔹 Fallback 1: XML_ID (если синхронизация заполняет это поле)
		if (!empty($product['XML_ID'])) {
			return (string)$product['XML_ID'];
		}
		
		// 🔹 Fallback 2: Сам ID товара (чтобы интеграция не сломалась)
		return (string)$productId;
	}

    /**
     * Получение данных контрагента (компания или контакт)
     */
    private static function getCounterpartyData($deal, $config) {
        $result = [
            'name' => '',
            'INN' => '',
            'KPP' => '',
            'external_id' => ''
        ];
        
        $companyId = $deal['COMPANY_ID'] ?? 0;
        $contactId = $deal['CONTACT_ID'] ?? 0;
        
        if ($companyId) {
            $company = \CCrmCompany::GetById($companyId);
            if ($company && is_array($company)) {
                $result['name'] = (string)($company['TITLE'] ?? '');
                $result['INN'] = (string)($company['UF_CRM_INN'] ?? $company['INDUSTRY'] ?? '');
                $result['KPP'] = (string)($company['UF_CRM_KPP'] ?? '');
                $result['external_id'] = (string)($company['UF_CRM_1C_EXTERNAL_ID'] ?? '');
            }
        } elseif ($contactId) {
            $contact = \CCrmContact::GetById($contactId);
            if ($contact && is_array($contact)) {
                $result['name'] = trim(
                    (string)($contact['LAST_NAME'] ?? '') . ' ' . 
                    (string)($contact['NAME'] ?? '') . ' ' . 
                    (string)($contact['SECOND_NAME'] ?? '')
                );
                $result['INN'] = (string)($contact['UF_CRM_INN'] ?? '');
                $result['KPP'] = '';
                $result['external_id'] = (string)($contact['UF_CRM_1C_EXTERNAL_ID'] ?? '');
            }
        }
        
        // Фоллбэк из полей сделки
        if (empty($result['name']) && !empty($deal['UF_CRM_COUNTERPARTY_NAME'])) {
            $result['name'] = (string)$deal['UF_CRM_COUNTERPARTY_NAME'];
        }
        if (empty($result['INN']) && !empty($deal['UF_CRM_COUNTERPARTY_INN'])) {
            $result['INN'] = (string)$deal['UF_CRM_COUNTERPARTY_INN'];
        }
        if (empty($result['external_id']) && !empty($deal['UF_CRM_1C_PARTNER_ID'])) {
            $result['external_id'] = (string)$deal['UF_CRM_1C_PARTNER_ID'];
        }
        
        return $result;
    }

    /**
     * Валидация сформированных данных перед отправкой в 1С
     */
    public static function validate($data) {
        $errors = [];
        
        if (empty($data['id'])) {
            $errors[] = 'Отсутствует ID сделки';
        }
        if (empty($data['organization'])) {
            $errors[] = 'Не указана организация (organization UUID)';
        }
        if (empty($data['structure'])) {
            $errors[] = 'Не указана структура (structure UUID)';
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            $errors[] = 'Отсутствуют товары в счете';
        } else {
            foreach ($data['items'] as $idx => $item) {
                if (empty($item['offer_Id'])) {
                    $errors[] = "Товар #{$item['key']}: не указан offer_Id";
                }
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    $errors[] = "Товар #{$item['key']}: некорректное количество";
                }
                if (!is_numeric($item['basePrice']) || $item['basePrice'] < 0) {
                    $errors[] = "Товар #{$item['key']}: некорректная цена";
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
