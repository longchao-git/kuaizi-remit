<?php

use App\Controller\Admin\Platform\MemberClosureController as StaffClosureController;
use App\Controller\Admin\Platform\PlatformAuditController;
use App\Controller\Admin\Platform\PlatformMemberController;
use App\Controller\Admin\Platform\PlatformRoleController;
use App\Controller\Admin\Platform\PushReminderController;
use App\Controller\Admin\Platform\ReconciliationController;
use App\Controller\Admin\Platform\RemittanceDashboardController;
use App\Controller\Admin\Platform\RemittanceMarginController;
use App\Controller\Admin\Platform\RemittanceOrderController;
use App\Controller\Admin\Platform\RemittanceQuoteRecordController;
use App\Controller\Admin\Platform\RemittanceRuleController;
use App\Controller\Admin\Platform\SessionController;
use App\Controller\Admin\Platform\StatusReasonController;
use App\Controller\Common\AgreementController;
use App\Controller\Common\HealthController;
use App\Controller\Common\MemberClosureController;
use App\Controller\Common\MemberDraftController;
use App\Controller\Common\MemberKycController;
use App\Controller\Common\MemberMaterialController;
use App\Controller\Common\MemberNotificationController;
use App\Controller\Common\MemberOrderController;
use App\Controller\Common\MemberPushDeviceController;
use App\Controller\Common\MemberQuoteController;
use App\Controller\Common\MemberSessionController;
use App\Controller\Common\RemittanceAnnouncementController;
use App\Controller\Common\RemittanceCouponController;
use App\Controller\Common\SupportMessageController;
use App\Controller\Common\SupportTicketController;
use App\Controller\Common\YabandRemitCallbackController;
use App\Middleware\RequireMemberToken;
use App\Middleware\RequirePlatformPermission;
use App\Permission\PlatformRoles;
use App\Permission\RemittancePermission;
use Illuminate\Support\Facades\Route;

Route::get('/', HealthController::class)->name('api.root');
Route::get('/health', HealthController::class)->name('api.health');
Route::get('/app/agreements/{kind}', [AgreementController::class, 'show'])
    ->whereIn('kind', ['terms', 'privacy']);
Route::post('/callbacks/yaband/remit/{event}', YabandRemitCallbackController::class)->whereIn('event', ['transaction', 'kyc']);

Route::post('/user/login', [SessionController::class, 'login'])->middleware('throttle:10,1,remit-platform-login:');
Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('/current', [SessionController::class, 'current']);
    Route::get('/menus', [SessionController::class, 'menus']);
    Route::get('/permissions', [SessionController::class, 'permissions']);
    Route::post('/logout', [SessionController::class, 'logout']);
});

Route::middleware('auth:sanctum')->prefix('admin/remittance')->group(function () {
    Route::get('/dashboard', RemittanceDashboardController::class)
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::VIEW_ORDERS);
    Route::middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_CLOSURES)->group(function () {
        Route::get('/closures', [StaffClosureController::class, 'index']);
        Route::put('/closures/{closure}', [StaffClosureController::class, 'review'])->where('closure', '[1-9][0-9]{0,17}')
            ->middleware('throttle:30,1,remit-closure-review:');
        Route::post('/closures/{closure}/execute', [StaffClosureController::class, 'execute'])->where('closure', '[1-9][0-9]{0,17}')
            ->middleware('throttle:6,1,remit-closure-execute:');
        Route::post('/closures/{closure}/verify', [StaffClosureController::class, 'verify'])->where('closure', '[1-9][0-9]{0,17}')
            ->middleware('throttle:12,1,remit-closure-verify:');
    });
    Route::prefix('push')->middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_PUSH_PLANS)->group(function () {
        Route::get('/plans', [PushReminderController::class, 'index']);
        Route::get('/records', [PushReminderController::class, 'records']);
        Route::post('/plans', [PushReminderController::class, 'save'])->middleware('throttle:30,1,remit-push-plan-create:');
        Route::put('/plans/{plan}', [PushReminderController::class, 'save'])->where('plan', '[1-9][0-9]{0,17}')->middleware('throttle:30,1,remit-push-plan-update:');
        Route::put('/plans/{plan}/status', [PushReminderController::class, 'status'])->where('plan', '[1-9][0-9]{0,17}')->middleware('throttle:30,1,remit-push-plan-status:');
    });
    Route::get('/rules', [RemittanceRuleController::class, 'show']);
    Route::put('/rules', [RemittanceRuleController::class, 'update']);
    Route::put('/operations', [RemittanceRuleController::class, 'updateOperations'])->middleware('throttle:30,1,remit-operations-update:');
    Route::get('/agreements/{kind}', [AgreementController::class, 'editor'])
        ->whereIn('kind', ['terms', 'privacy'])
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_RULES);
    Route::put('/agreements/{kind}/draft', [AgreementController::class, 'saveDraft'])
        ->whereIn('kind', ['terms', 'privacy'])
        ->middleware([RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_RULES, 'throttle:30,1,remit-agreement-draft:']);
    Route::post('/agreements/{kind}', [AgreementController::class, 'publish'])
        ->whereIn('kind', ['terms', 'privacy'])
        ->middleware([RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_RULES, 'throttle:15,1,remit-agreement-publish:']);
    Route::get('/rules/revisions', [RemittanceRuleController::class, 'revisions']);
    Route::prefix('reconciliation')->middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_RECONCILIATION)->group(function () {
        Route::get('/', [ReconciliationController::class, 'index']);
        Route::post('/', [ReconciliationController::class, 'import'])->middleware('throttle:12,1,remit-reconcile-import:');
        Route::get('/{batch}/lines', [ReconciliationController::class, 'lines'])->where('batch', '[1-9][0-9]{0,17}');
        Route::get('/{batch}/export', [ReconciliationController::class, 'export'])->where('batch', '[1-9][0-9]{0,17}')->middleware('throttle:30,1,remit-reconcile-export:');
        Route::put('/{batch}/lines/{line}', [ReconciliationController::class, 'review'])
            ->where(['batch' => '[1-9][0-9]{0,17}', 'line' => '[1-9][0-9]{0,17}'])->middleware('throttle:30,1,remit-reconcile-review:');
    });
    Route::post('/quote-preview', [RemittanceRuleController::class, 'preview']);
    Route::get('/quote-records', RemittanceQuoteRecordController::class)
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_RULES);
    Route::get('/orders', [RemittanceOrderController::class, 'index'])
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::VIEW_ORDERS);
    Route::get('/orders/{reference}', [RemittanceOrderController::class, 'show'])->whereUuid('reference')
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::VIEW_ORDERS);
    Route::post('/orders/{reference}/refresh', [RemittanceOrderController::class, 'refresh'])->whereUuid('reference')
        ->middleware([RequirePlatformPermission::class.':'.RemittancePermission::SYNC_ORDERS, 'throttle:15,1,remit-order-refresh:']);
    Route::middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_MATERIALS)->group(function () {
        Route::get('/materials', [MemberMaterialController::class, 'adminIndex']);
        Route::post('/materials', [MemberMaterialController::class, 'register'])->middleware('throttle:20,1,remit-material-register:');
    });
    Route::post('/margin-preview', RemittanceMarginController::class)
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_RULES);
    Route::get('/members', [PlatformMemberController::class, 'index'])
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::VIEW_MEMBERS);
    Route::get('/status-reasons', StatusReasonController::class)
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::VIEW_STATUS_REASONS);
    Route::post('/members/{member}/notifications', [PlatformMemberController::class, 'notifyMember'])->whereNumber('member')
        ->middleware([RequirePlatformPermission::class.':'.RemittancePermission::SEND_NOTIFICATION, 'throttle:30,1,remit-member-notice-send:']);
    Route::prefix('coupons')->middleware(RequirePlatformPermission::class.':'.RemittancePermission::MANAGE_COUPONS)->group(function () {
        Route::get('/', [RemittanceCouponController::class, 'index']);
        Route::post('/', [RemittanceCouponController::class, 'issue'])->middleware('throttle:30,1,remit-coupon-issue:');
        Route::get('/templates', [RemittanceCouponController::class, 'templates']);
        Route::post('/templates', [RemittanceCouponController::class, 'saveTemplate']);
        Route::put('/templates/{template}', [RemittanceCouponController::class, 'saveTemplate'])->where('template', '[1-9][0-9]{0,17}');
        Route::post('/{coupon}/revoke', [RemittanceCouponController::class, 'revoke'])->where('coupon', '[1-9][0-9]{0,17}');
    });
    Route::middleware(RequirePlatformPermission::class.':'.PlatformRoles::MANAGE_ACCESS)->group(function () {
        Route::get('/audit', PlatformAuditController::class);
        Route::get('/staff', [PlatformRoleController::class, 'staff']);
        Route::put('/staff/{staff}/role', [PlatformRoleController::class, 'assign'])->where('staff', '[1-9][0-9]{0,17}');
        Route::get('/staff/role-audits', [PlatformRoleController::class, 'audits']);
    });
});

Route::post('/app/auth/local-login', [MemberSessionController::class, 'loginLocal'])->middleware('throttle:10,1,remit-member-local-login:');
Route::post('/app/auth/sms-code', [MemberSessionController::class, 'sendSms'])->middleware('throttle:sms-send');
Route::post('/app/auth/sms-login', [MemberSessionController::class, 'loginSms'])->middleware('throttle:sms-login');
Route::middleware(['auth:sanctum', RequireMemberToken::class])->prefix('app')->group(function () {
    Route::get('/auth/current', [MemberSessionController::class, 'current']);
    Route::post('/auth/logout', [MemberSessionController::class, 'logout']);
    Route::get('/account/closures', [MemberClosureController::class, 'index']);
    Route::post('/account/closures', [MemberClosureController::class, 'store'])->middleware('throttle:6,1,remit-closure-submit:');
    Route::get('/kyc/status', [MemberKycController::class, 'status'])->middleware('throttle:20,1,remit-kyc-status:');
    Route::post('/kyc/idv', [MemberKycController::class, 'start'])->middleware('throttle:6,1,remit-kyc-idv:');
    Route::post('/kyc/profile', [MemberKycController::class, 'profile'])->middleware('throttle:6,1,remit-kyc-profile:');
    Route::post('/remittance/quotes', MemberQuoteController::class)->middleware('throttle:30,1,remit-quote-create:');
    Route::get('/remittance/announcement', RemittanceAnnouncementController::class);
    Route::get('/remittance/orders', [MemberOrderController::class, 'index']);
    Route::post('/remittance/orders', [MemberOrderController::class, 'checkout'])->middleware('throttle:10,1,remit-order-checkout:');
    Route::get('/remittance/payout-accounts', [MemberOrderController::class, 'accounts'])->middleware('throttle:30,1,remit-payout-accounts:');
    Route::get('/remittance/orders/{reference}', [MemberOrderController::class, 'show'])->whereUuid('reference');
    Route::get('/remittance/materials', [MemberMaterialController::class, 'memberIndex']);
    Route::get('/remittance/materials/{reference}', [MemberMaterialController::class, 'show'])->whereUuid('reference');
    Route::post('/remittance/materials/{reference}', [MemberMaterialController::class, 'submit'])
        ->whereUuid('reference')->middleware('throttle:12,1,remit-material-submit:');
    Route::get('/remittance/drafts', [MemberDraftController::class, 'index']);
    Route::put('/remittance/drafts/{reference}', [MemberDraftController::class, 'save'])->whereUuid('reference')->middleware('throttle:30,1,remit-draft-save:');
    Route::post('/remittance/drafts/{reference}/remove', [MemberDraftController::class, 'remove'])->whereUuid('reference')->middleware('throttle:30,1,remit-draft-remove:');
    Route::get('/notifications', [MemberNotificationController::class, 'index']);
    Route::get('/notifications/unread', [MemberNotificationController::class, 'unread']);
    Route::get('/notifications/{notice}', [MemberNotificationController::class, 'show'])->where('notice', '[1-9][0-9]{0,17}');
    Route::put('/notifications/device', [MemberPushDeviceController::class, 'register'])->middleware('throttle:12,1,remit-device-register:');
    Route::delete('/notifications/device', [MemberPushDeviceController::class, 'unregister'])->middleware('throttle:12,1,remit-device-unregister:');
    Route::post('/notifications/{notice}/read', [MemberNotificationController::class, 'read'])
        ->where('notice', '[1-9][0-9]{0,17}')->middleware('throttle:60,1,remit-notice-read:');
    Route::get('/support/status', [SupportMessageController::class, 'memberStatus']);
    Route::post('/support/read', [SupportMessageController::class, 'read'])->name('support.member.read');
    Route::get('/support/tickets', [SupportTicketController::class, 'index'])->name('support.tickets.member.index');
    Route::get('/support/tickets/{ticket}', [SupportTicketController::class, 'show'])
        ->where('ticket', '[1-9][0-9]{0,17}')->name('support.tickets.member.show');
    Route::post('/support/tickets', [SupportTicketController::class, 'create'])->middleware('throttle:15,1,remit-ticket-create:');
    Route::get('/coupons', [RemittanceCouponController::class, 'index'])->name('coupon.member.index');
    Route::post('/coupons/claim', [RemittanceCouponController::class, 'claim'])->middleware('throttle:12,1,remit-coupon-claim:');
    Route::get('/coupons/home-offer', [RemittanceCouponController::class, 'homeOffer']);
    Route::post('/coupons/{coupon}/claim', [RemittanceCouponController::class, 'claimHomeOffer'])
        ->where('coupon', '[1-9][0-9]{0,17}')->middleware('throttle:12,1,remit-coupon-claim:');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/app/support/messages', [SupportMessageController::class, 'index'])->name('support.member.index');
    Route::post('/app/support/messages', [SupportMessageController::class, 'store'])->middleware('throttle:30,1,remit-member-message-send:')->name('support.member.store');
    Route::get('/admin/support/members/{member}/messages', [SupportMessageController::class, 'index'])->whereNumber('member');
    Route::post('/admin/support/members/{member}/messages', [SupportMessageController::class, 'store'])->whereNumber('member')->middleware('throttle:30,1,remit-staff-message-send:');
    Route::get('/admin/support/conversations', [SupportMessageController::class, 'conversations'])
        ->middleware(RequirePlatformPermission::class.':'.RemittancePermission::SUPPORT);
    Route::post('/admin/support/members/{member}/read', [SupportMessageController::class, 'read'])->whereNumber('member');
});

Route::prefix('admin/support/tickets')->middleware(['auth:sanctum', RequirePlatformPermission::class.':'.RemittancePermission::SUPPORT])->group(function () {
    Route::get('/', [SupportTicketController::class, 'index']);
    Route::get('/{ticket}', [SupportTicketController::class, 'show'])->where('ticket', '[1-9][0-9]{0,17}');
    Route::put('/{ticket}', [SupportTicketController::class, 'update'])->where('ticket', '[1-9][0-9]{0,17}')->middleware('throttle:30,1,remit-ticket-update:');
});
