<?php

namespace App\Permission;

final class RemittancePermission
{
    public const MANAGE_RECONCILIATION = 'remit:reconciliation:manage';

    public const MANAGE_CLOSURES = 'remit:closures:manage';

    public const MANAGE_PUSH_PLANS = 'remit:push-plans:manage';

    public const MANAGE_RULES = 'remit:rules:manage';

    public const SUPPORT = 'remit:support:reply';

    public const VIEW_MEMBERS = 'remit:members:view';

    public const VIEW_ORDERS = 'remit:orders:view';

    public const SYNC_ORDERS = 'remit:orders:sync';

    public const MANAGE_MATERIALS = 'remit:materials:manage';

    public const SEND_NOTIFICATION = 'remit:notifications:send';

    public const MANAGE_COUPONS = 'remit:coupons:manage';

    public const VIEW_STATUS_REASONS = 'remit:status-reasons:view';
}
