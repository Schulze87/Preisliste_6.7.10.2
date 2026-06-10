<?php declare(strict_types=1);

namespace Preisliste\Service;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class PriceListAccessService
{
    private const CONFIG_KEY_ALLOWED_GROUP_IDS = 'Preisliste.config.allowedCustomerGroupIds';
    private const CUSTOMER_CUSTOM_FIELD = 'custom_payment_preisliste';

    public function __construct(
        private readonly SystemConfigService $systemConfigService
    ) {
    }

    public function isAllowed(SalesChannelContext $salesChannelContext): bool
    {
        $customer = $salesChannelContext->getCustomer();

        if (!$customer instanceof CustomerEntity) {
            return false;
        }

        $allowedCustomerGroupIds = $this->systemConfigService->get(
            self::CONFIG_KEY_ALLOWED_GROUP_IDS,
            $salesChannelContext->getSalesChannelId()
        );

        if (!is_array($allowedCustomerGroupIds) || $allowedCustomerGroupIds === []) {
            return false;
        }

        $customerGroupId = $customer->getGroupId();

        if ($customerGroupId === null || !in_array($customerGroupId, $allowedCustomerGroupIds, true)) {
            return false;
        }

        $customFields = $customer->getCustomFields() ?? [];
        $isBlocked = (bool) ($customFields[self::CUSTOMER_CUSTOM_FIELD] ?? false);

        if ($isBlocked) {
            return false;
        }

        return true;
    }
}
