<?php

use App\Http\Controllers\Admin\AdministrationHubController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminProfileController;
use App\Http\Controllers\Admin\AdminShellController;
use App\Http\Controllers\Admin\AppPreviewConfigurationController;
use App\Http\Controllers\Admin\AppPreviewController;
use App\Http\Controllers\Admin\AppPreviewInvalidationController;
use App\Http\Controllers\Admin\AppVersionController;
use App\Http\Controllers\Admin\AssistantController;
use App\Http\Controllers\Admin\AssistantSettingsController;
use App\Http\Controllers\Admin\B2bWorkspaceController;
use App\Http\Controllers\Admin\B2cWorkspaceController;
use App\Http\Controllers\Admin\BusinessManagementController;
use App\Http\Controllers\Admin\CatalogManagementController;
use App\Http\Controllers\Admin\CommercialDashboardController;
use App\Http\Controllers\Admin\Customer360Controller;
use App\Http\Controllers\Admin\CustomerServiceWorkspaceController;
use App\Http\Controllers\Admin\DriverLiveTrackingDashboardController;
use App\Http\Controllers\Admin\EnterpriseActionCenterController;
use App\Http\Controllers\Admin\EnterpriseBiController;
use App\Http\Controllers\Admin\EnterpriseBiScheduleController;
use App\Http\Controllers\Admin\EnterpriseModuleAdministrationController;
use App\Http\Controllers\Admin\EnterpriseOrganizationAdministrationController;
use App\Http\Controllers\Admin\EnterpriseProcurementController;
use App\Http\Controllers\Admin\EnterpriseSavedViewController;
use App\Http\Controllers\Admin\EnterpriseSecurityController;
use App\Http\Controllers\Admin\EnterpriseSystemHealthController;
use App\Http\Controllers\Admin\EnterpriseTreasuryController;
use App\Http\Controllers\Admin\EnterpriseWmsController;
use App\Http\Controllers\Admin\EnterpriseWorkspaceController;
use App\Http\Controllers\Admin\ErpCenterController;
use App\Http\Controllers\Admin\FieldOperationsController;
use App\Http\Controllers\Admin\FinanceWorkbenchController;
use App\Http\Controllers\Admin\FoodexResetController;
use App\Http\Controllers\Admin\GlobalSearchController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LiveAdController;
use App\Http\Controllers\Admin\LookupManagementController;
use App\Http\Controllers\Admin\MobileAppDownloadController;
use App\Http\Controllers\Admin\MobileSettingsController;
use App\Http\Controllers\Admin\OrderOperationsController;
use App\Http\Controllers\Admin\ProductionDatasetSwitchController;
use App\Http\Controllers\Admin\ProductionLaunchController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RetailStoreProvisioningController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SettingsCenterController;
use App\Http\Controllers\Admin\SmartOrderingQrController;
use App\Http\Controllers\Admin\SmsSettingsController;
use App\Http\Controllers\Admin\StorefrontDraftEditorController;
use App\Http\Controllers\Admin\StoreSubmissionController;
use App\Http\Controllers\Admin\SystemInspectorController;
use App\Http\Controllers\Admin\SystemLookupController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\TranslationController;
use App\Http\Controllers\Admin\TreasuryMasterController;
use App\Http\Controllers\Admin\VanFinanceSupportController;
use App\Http\Controllers\Admin\WhatsAppCallController;
use App\Http\Controllers\Admin\WhatsAppCampaignController;
use App\Http\Controllers\Admin\WhatsAppChatbotController;
use App\Http\Controllers\Admin\WhatsAppConnectionController;
use App\Http\Controllers\Admin\WhatsAppFoundationController;
use App\Http\Controllers\Admin\WhatsAppInboundAlertController;
use App\Http\Controllers\Admin\WhatsAppInboxController;
use App\Http\Controllers\Admin\WhatsAppSupervisorController;
use App\Http\Controllers\Api\V1\DriverLiveTrackingController;
use App\Http\Controllers\Installer\InstallerController;
use App\Http\Controllers\PublicInformationController;
use App\Http\Controllers\SmartOrderingQrPublicController;
use App\Http\Controllers\SupplierRfqPortalController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => redirect()->route('admin.b2c.login'));

Route::get('/downloads/apps/{app}/latest.apk', [MobileAppDownloadController::class, 'latest'])
    ->whereIn('app', ['customer', 'driver', 'van'])
    ->name('public.mobile-apps.latest');
Route::get('/downloads/apps/{app}/{version}.apk', [MobileAppDownloadController::class, 'versioned'])
    ->whereIn('app', ['customer', 'driver', 'van'])
    ->where('version', '\\d+\\.\\d+\\.\\d+')
    ->name('public.mobile-apps.versioned');

Route::get('/privacy', [PublicInformationController::class, 'privacy'])->name('public.privacy');
Route::get('/terms', [PublicInformationController::class, 'terms'])->name('public.terms');
Route::get('/support', [PublicInformationController::class, 'support'])->name('public.support');
Route::get('/account-deletion', [PublicInformationController::class, 'accountDeletion'])->name('public.account-deletion');

Route::withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function (): void {
    Route::get('/webhooks/whatsapp/meta/{profile}', [WhatsAppWebhookController::class, 'verify'])
        ->whereNumber('profile')
        ->middleware('throttle:120,1')
        ->name('webhooks.whatsapp.meta.verify');
    Route::post('/webhooks/whatsapp/meta/{profile}', [WhatsAppWebhookController::class, 'ingest'])
        ->whereNumber('profile')
        ->middleware('throttle:240,1')
        ->name('webhooks.whatsapp.meta.ingest');

    Route::get('/install', [InstallerController::class, 'show'])->name('install.index');
    Route::post('/install/step/{step}', [InstallerController::class, 'process'])
        ->whereNumber('step')
        ->name('install.step');
});

Route::get('/q/{token}', SmartOrderingQrPublicController::class)
    ->where('token', '[A-Za-z0-9_-]{32,96}')
    ->middleware('throttle:120,1')
    ->name('smart-ordering.qr.resolve');

Route::prefix('supplier/rfq')->name('supplier.rfq.')->group(function (): void {
    Route::get('/{token}', [SupplierRfqPortalController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:120,1')
        ->name('show');
    Route::post('/{token}', [SupplierRfqPortalController::class, 'submit'])
        ->where('token', '[A-Za-z0-9]{64}')
        ->middleware('throttle:30,1')
        ->name('submit');
});

Route::prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/csrf-token', [AdminLoginController::class, 'csrfToken'])
            ->middleware('throttle:60,1')
            ->name('csrf-token');
        Route::get('/b2b/login', [AdminLoginController::class, 'show'])
            ->defaults('channel', 'b2b')
            ->name('b2b.login');
        Route::post('/b2b/login', [AdminLoginController::class, 'store'])
            ->defaults('channel', 'b2b')
            ->middleware('throttle:login')
            ->name('b2b.login.store');
        Route::get('/b2c/login', [AdminLoginController::class, 'show'])
            ->defaults('channel', 'b2c')
            ->name('b2c.login');
        Route::post('/b2c/login', [AdminLoginController::class, 'store'])
            ->defaults('channel', 'b2c')
            ->middleware('throttle:login')
            ->name('b2c.login.store');
        Route::post('/logout', [AdminLoginController::class, 'destroy'])
            ->middleware('auth')
            ->name('logout');
    });

Route::prefix('admin')
    ->name('admin.')
    ->middleware('management.dashboard')
    ->group(function (): void {
        Route::get('/', [AdminShellController::class, 'index'])->name('index');
        Route::get('/search', GlobalSearchController::class)->middleware('throttle:120,1')->name('global-search');
        Route::get('/action-center', [EnterpriseActionCenterController::class, 'index'])->name('action-center.index');
        Route::get('/action-center/export', [EnterpriseActionCenterController::class, 'export'])->middleware('throttle:30,1')->name('action-center.export');
        Route::get('/erp', ErpCenterController::class)->name('erp.index');
        Route::prefix('erp/procurement')->name('erp.procurement.')->group(function (): void {
            Route::get('/', [EnterpriseProcurementController::class, 'index'])->name('index');
            Route::post('/suppliers', [EnterpriseProcurementController::class, 'storeSupplier'])->middleware('throttle:30,1')->name('suppliers.store');
            Route::post('/suppliers/{supplier}/governance', [EnterpriseProcurementController::class, 'updateSupplierGovernance'])->whereNumber('supplier')->middleware('throttle:30,1')->name('suppliers.governance');
            Route::post('/rfqs', [EnterpriseProcurementController::class, 'storeRfq'])->middleware('throttle:30,1')->name('rfqs.store');
            Route::post('/rfqs/{rfq}/publish', [EnterpriseProcurementController::class, 'publishRfq'])->whereNumber('rfq')->middleware('throttle:30,1')->name('rfqs.publish');
            Route::post('/rfqs/{rfq}/cancel', [EnterpriseProcurementController::class, 'cancelRfq'])->whereNumber('rfq')->middleware('throttle:30,1')->name('rfqs.cancel');
            Route::post('/rfqs/{rfq}/quotes/{quote}/award', [EnterpriseProcurementController::class, 'awardRfq'])->whereNumber('rfq')->whereNumber('quote')->middleware('throttle:30,1')->name('rfqs.award');
            Route::get('/rfqs/{rfq}/documents/{document}/download', [EnterpriseProcurementController::class, 'downloadQuoteDocument'])->whereNumber('rfq')->whereNumber('document')->middleware('throttle:60,1')->name('rfqs.documents.download');
            Route::post('/contracts', [EnterpriseProcurementController::class, 'createContract'])->middleware('throttle:30,1')->name('contracts.store');
            Route::post('/contracts/{contract}/activate', [EnterpriseProcurementController::class, 'activateContract'])->whereNumber('contract')->middleware('throttle:30,1')->name('contracts.activate');
            Route::post('/contracts/{contract}/terminate', [EnterpriseProcurementController::class, 'terminateContract'])->whereNumber('contract')->middleware('throttle:30,1')->name('contracts.terminate');
            Route::post('/contracts/{contract}/documents', [EnterpriseProcurementController::class, 'registerContractDocument'])->whereNumber('contract')->middleware('throttle:30,1')->name('contracts.documents.store');
            Route::get('/contracts/{contract}/documents/{document}/download', [EnterpriseProcurementController::class, 'downloadContractDocument'])->whereNumber('contract')->whereNumber('document')->middleware('throttle:60,1')->name('contracts.documents.download');
            Route::post('/orders/{purchaseOrder}/contract', [EnterpriseProcurementController::class, 'assignContract'])->whereNumber('purchaseOrder')->middleware('throttle:30,1')->name('orders.contract');
            Route::post('/invoices/{purchaseInvoice}/match-review', [EnterpriseProcurementController::class, 'reviewMatch'])->whereNumber('purchaseInvoice')->middleware('throttle:30,1')->name('matches.review');
            Route::get('/reports/export', [EnterpriseProcurementController::class, 'exportReport'])->middleware('throttle:30,1')->name('reports.export');
            Route::post('/suppliers/{supplier}/archive', [EnterpriseProcurementController::class, 'archiveSupplier'])->whereNumber('supplier')->middleware('throttle:30,1')->name('suppliers.archive');
            Route::post('/requests', [EnterpriseProcurementController::class, 'storeRequest'])->middleware('throttle:30,1')->name('requests.store');
            Route::post('/requests/{purchaseRequest}/submit', [EnterpriseProcurementController::class, 'submitRequest'])->whereNumber('purchaseRequest')->middleware('throttle:30,1')->name('requests.submit');
            Route::post('/requests/{purchaseRequest}/decision', [EnterpriseProcurementController::class, 'decideRequest'])->whereNumber('purchaseRequest')->middleware('throttle:30,1')->name('requests.decision');
            Route::post('/requests/{purchaseRequest}/cancel', [EnterpriseProcurementController::class, 'cancelRequest'])->whereNumber('purchaseRequest')->middleware('throttle:30,1')->name('requests.cancel');
            Route::post('/requests/{purchaseRequest}/purchase-order', [EnterpriseProcurementController::class, 'createOrder'])->whereNumber('purchaseRequest')->middleware('throttle:30,1')->name('orders.create');
            Route::post('/orders/{purchaseOrder}/issue', [EnterpriseProcurementController::class, 'issueOrder'])->whereNumber('purchaseOrder')->middleware('throttle:30,1')->name('orders.issue');
            Route::post('/orders/{purchaseOrder}/cancel', [EnterpriseProcurementController::class, 'cancelOrder'])->whereNumber('purchaseOrder')->middleware('throttle:30,1')->name('orders.cancel');
            Route::post('/orders/{purchaseOrder}/receipts', [EnterpriseProcurementController::class, 'storeReceipt'])->whereNumber('purchaseOrder')->middleware('throttle:30,1')->name('receipts.store');
            Route::post('/receipts/{goodsReceipt}/post', [EnterpriseProcurementController::class, 'postReceipt'])->whereNumber('goodsReceipt')->middleware('throttle:30,1')->name('receipts.post');
            Route::post('/receipts/{goodsReceipt}/cancel', [EnterpriseProcurementController::class, 'cancelReceipt'])->whereNumber('goodsReceipt')->middleware('throttle:30,1')->name('receipts.cancel');
            Route::post('/receipts/{goodsReceipt}/invoices', [EnterpriseProcurementController::class, 'storeInvoice'])->whereNumber('goodsReceipt')->middleware('throttle:30,1')->name('invoices.store');
            Route::post('/invoices/{purchaseInvoice}/handoff', [EnterpriseProcurementController::class, 'handoffInvoice'])->whereNumber('purchaseInvoice')->middleware('throttle:30,1')->name('invoices.handoff');
            Route::post('/invoices/{purchaseInvoice}/cancel', [EnterpriseProcurementController::class, 'cancelInvoice'])->whereNumber('purchaseInvoice')->middleware('throttle:30,1')->name('invoices.cancel');
            Route::post('/receipts/{goodsReceipt}/returns', [EnterpriseProcurementController::class, 'storeReturn'])->whereNumber('goodsReceipt')->middleware('throttle:30,1')->name('returns.store');
            Route::post('/returns/{purchaseReturn}/post', [EnterpriseProcurementController::class, 'postReturn'])->whereNumber('purchaseReturn')->middleware('throttle:30,1')->name('returns.post');
            Route::post('/returns/{purchaseReturn}/cancel', [EnterpriseProcurementController::class, 'cancelReturn'])->whereNumber('purchaseReturn')->middleware('throttle:30,1')->name('returns.cancel');
        });
        Route::prefix('erp/wms')->name('erp.wms.')->group(function (): void {
            Route::get('/', [EnterpriseWmsController::class, 'index'])->name('index');
            Route::post('/transfers', [EnterpriseWmsController::class, 'createTransfer'])->middleware('throttle:30,1')->name('transfers.store');
            Route::post('/transfers/{transfer}/dispatch', [EnterpriseWmsController::class, 'dispatchTransfer'])->whereNumber('transfer')->middleware('throttle:30,1')->name('transfers.dispatch');
            Route::post('/transfers/{transfer}/receive', [EnterpriseWmsController::class, 'receiveTransfer'])->whereNumber('transfer')->middleware('throttle:30,1')->name('transfers.receive');
            Route::post('/counts', [EnterpriseWmsController::class, 'startCount'])->middleware('throttle:30,1')->name('counts.store');
            Route::post('/counts/{count}/complete', [EnterpriseWmsController::class, 'completeCount'])->whereNumber('count')->middleware('throttle:30,1')->name('counts.complete');
            Route::post('/adjustments', [EnterpriseWmsController::class, 'postAdjustment'])->middleware('throttle:30,1')->name('adjustments.store');
            Route::post('/lots', [EnterpriseWmsController::class, 'saveLot'])->middleware('throttle:30,1')->name('lots.store');
            Route::post('/lots/{lot}/status', [EnterpriseWmsController::class, 'transitionLot'])->whereNumber('lot')->middleware('throttle:30,1')->name('lots.status');
            Route::post('/reservations', [EnterpriseWmsController::class, 'saveReservation'])->middleware('throttle:30,1')->name('reservations.store');
            Route::post('/reorder', [EnterpriseWmsController::class, 'saveReorderPolicy'])->middleware('throttle:30,1')->name('reorder.store');
            Route::post('/reorder/{policy}/disable', [EnterpriseWmsController::class, 'disableReorderPolicy'])->whereNumber('policy')->middleware('throttle:30,1')->name('reorder.disable');
            Route::post('/valuation', [EnterpriseWmsController::class, 'saveValuationPolicy'])->middleware('throttle:30,1')->name('valuation.store');
            Route::post('/locations/zones', [EnterpriseWmsController::class, 'saveZone'])->middleware('throttle:30,1')->name('locations.zones.store');
            Route::post('/locations/bins', [EnterpriseWmsController::class, 'saveBin'])->middleware('throttle:30,1')->name('locations.bins.store');
            Route::post('/locations/assignments', [EnterpriseWmsController::class, 'assignInventoryBin'])->middleware('throttle:30,1')->name('locations.assignments.store');
            Route::post('/planning/approve', [EnterpriseWmsController::class, 'approveDemandPlan'])->middleware('throttle:30,1')->name('planning.approve');
        });
        Route::get('/workspaces/{workspace?}', EnterpriseWorkspaceController::class)
            ->whereIn('workspace', ['executive', 'finance', 'sales', 'warehouse'])
            ->name('enterprise-workspaces.index');
        Route::get('/erp/finance', [FinanceWorkbenchController::class, 'index'])->name('erp.finance.index');
        Route::post('/erp/finance/accounts', [FinanceWorkbenchController::class, 'storeAccount'])->middleware('throttle:30,1')->name('erp.finance.accounts.store');
        Route::patch('/erp/finance/accounts/{account}/status', [FinanceWorkbenchController::class, 'updateAccountStatus'])->whereNumber('account')->middleware('throttle:30,1')->name('erp.finance.accounts.status');
        Route::post('/erp/finance/cost-centers', [FinanceWorkbenchController::class, 'storeCostCenter'])->middleware('throttle:30,1')->name('erp.finance.cost-centers.store');
        Route::patch('/erp/finance/cost-centers/{costCenter}/status', [FinanceWorkbenchController::class, 'updateCostCenterStatus'])->whereNumber('costCenter')->middleware('throttle:30,1')->name('erp.finance.cost-centers.status');
        Route::post('/erp/finance/fiscal-years', [FinanceWorkbenchController::class, 'storeFiscalYear'])->middleware('throttle:30,1')->name('erp.finance.fiscal-years.store');
        Route::patch('/erp/finance/fiscal-years/{fiscalYear}/state', [FinanceWorkbenchController::class, 'updateFiscalYearState'])->whereNumber('fiscalYear')->middleware('throttle:30,1')->name('erp.finance.fiscal-years.state');
        Route::post('/erp/finance/fiscal-years/{fiscalYear}/periods', [FinanceWorkbenchController::class, 'storeFiscalPeriod'])->whereNumber('fiscalYear')->middleware('throttle:30,1')->name('erp.finance.fiscal-periods.store');
        Route::patch('/erp/finance/fiscal-periods/{fiscalPeriod}/state', [FinanceWorkbenchController::class, 'updateFiscalPeriodState'])->whereNumber('fiscalPeriod')->middleware('throttle:30,1')->name('erp.finance.fiscal-periods.state');
        Route::post('/erp/finance/journals', [FinanceWorkbenchController::class, 'storeJournal'])->middleware('throttle:30,1')->name('erp.finance.journals.store');
        Route::post('/erp/finance/journals/{journal}/approval', [FinanceWorkbenchController::class, 'requestJournalApproval'])->whereNumber('journal')->middleware('throttle:30,1')->name('erp.finance.journals.approval');
        Route::post('/erp/finance/journals/{journal}/post', [FinanceWorkbenchController::class, 'postJournal'])->whereNumber('journal')->middleware('throttle:30,1')->name('erp.finance.journals.post');
        Route::post('/erp/finance/opening-balances', [FinanceWorkbenchController::class, 'storeOpeningBalance'])->middleware('throttle:30,1')->name('erp.finance.opening-balances.store');
        Route::post('/erp/finance/opening-balances/{batch}/approval', [FinanceWorkbenchController::class, 'requestOpeningBalanceApproval'])->whereNumber('batch')->middleware('throttle:30,1')->name('erp.finance.opening-balances.approval');
        Route::post('/erp/finance/opening-balances/{batch}/post', [FinanceWorkbenchController::class, 'postOpeningBalance'])->whereNumber('batch')->middleware('throttle:30,1')->name('erp.finance.opening-balances.post');
        Route::post('/erp/finance/opening-balances/{batch}/cancel', [FinanceWorkbenchController::class, 'cancelOpeningBalance'])->whereNumber('batch')->middleware('throttle:30,1')->name('erp.finance.opening-balances.cancel');
        Route::post('/erp/finance/credit-terms', [FinanceWorkbenchController::class, 'storeCreditTerm'])->middleware('throttle:30,1')->name('erp.finance.credit-terms.store');
        Route::patch('/erp/finance/credit-terms/{creditTerm}/status', [FinanceWorkbenchController::class, 'updateCreditTermStatus'])->whereNumber('creditTerm')->middleware('throttle:30,1')->name('erp.finance.credit-terms.status');
        Route::post('/erp/finance/profit-centers', [FinanceWorkbenchController::class, 'storeProfitCenter'])->middleware('throttle:30,1')->name('erp.finance.profit-centers.store');
        Route::patch('/erp/finance/profit-centers/{profitCenter}/status', [FinanceWorkbenchController::class, 'updateProfitCenterStatus'])->whereNumber('profitCenter')->middleware('throttle:30,1')->name('erp.finance.profit-centers.status');
        Route::patch('/erp/finance/accounts/{account}/cash-flow', [FinanceWorkbenchController::class, 'updateCashFlowClassification'])->whereNumber('account')->middleware('throttle:30,1')->name('erp.finance.accounts.cash-flow');
        Route::post('/erp/finance/budgets', [FinanceWorkbenchController::class, 'storeBudget'])->middleware('throttle:30,1')->name('erp.finance.budgets.store');
        Route::post('/erp/finance/budgets/{budget}/lines', [FinanceWorkbenchController::class, 'storeBudgetLine'])->whereNumber('budget')->middleware('throttle:30,1')->name('erp.finance.budgets.lines.store');
        Route::post('/erp/finance/budgets/{budget}/transition', [FinanceWorkbenchController::class, 'transitionBudget'])->whereNumber('budget')->middleware('throttle:30,1')->name('erp.finance.budgets.transition');
        Route::post('/erp/finance/budgets/{budget}/revise', [FinanceWorkbenchController::class, 'reviseBudget'])->whereNumber('budget')->middleware('throttle:30,1')->name('erp.finance.budgets.revise');
        Route::post('/erp/finance/tax-codes', [FinanceWorkbenchController::class, 'storeTaxCode'])->middleware('throttle:30,1')->name('erp.finance.tax-codes.store');
        Route::post('/erp/finance/allocation-rules', [FinanceWorkbenchController::class, 'storeAllocationRule'])->middleware('throttle:30,1')->name('erp.finance.allocation-rules.store');
        Route::post('/erp/finance/allocation-rules/{allocationRule}/apply', [FinanceWorkbenchController::class, 'applyAllocationRule'])->whereNumber('allocationRule')->middleware('throttle:30,1')->name('erp.finance.allocation-rules.apply');
        Route::post('/erp/finance/consolidation-eliminations', [FinanceWorkbenchController::class, 'storeConsolidationElimination'])->middleware('throttle:30,1')->name('erp.finance.consolidation-eliminations.store');
        Route::post('/erp/finance/asset-categories', [FinanceWorkbenchController::class, 'storeAssetCategory'])->middleware('throttle:30,1')->name('erp.finance.asset-categories.store');
        Route::post('/erp/finance/assets', [FinanceWorkbenchController::class, 'storeAsset'])->middleware('throttle:30,1')->name('erp.finance.assets.store');
        Route::post('/erp/finance/assets/{asset}/depreciation', [FinanceWorkbenchController::class, 'createAssetDepreciation'])->whereNumber('asset')->middleware('throttle:30,1')->name('erp.finance.assets.depreciation');
        Route::post('/erp/finance/assets/{asset}/disposal', [FinanceWorkbenchController::class, 'prepareAssetDisposal'])->whereNumber('asset')->middleware('throttle:30,1')->name('erp.finance.assets.disposal');
        Route::post('/erp/finance/fiscal-periods/{fiscalPeriod}/close-evaluation', [FinanceWorkbenchController::class, 'evaluatePeriodClose'])->whereNumber('fiscalPeriod')->middleware('throttle:30,1')->name('erp.finance.fiscal-periods.close-evaluation');
        Route::post('/action-center/{workflowRequest}/steps/{stepCode}/decision', [EnterpriseActionCenterController::class, 'decide'])
            ->whereNumber('workflowRequest')
            ->where('stepCode', '[A-Za-z0-9._-]+')
            ->middleware('throttle:30,1')
            ->name('action-center.decide');
        Route::get('/administration', AdministrationHubController::class)->name('administration.index');
        Route::get('/administration/organizations', [EnterpriseOrganizationAdministrationController::class, 'index'])->name('enterprise-organizations.index');
        Route::post('/administration/organizations', [EnterpriseOrganizationAdministrationController::class, 'storeOrganization'])->middleware('throttle:30,1')->name('enterprise-organizations.store');
        Route::get('/administration/organizations/{organization}', [EnterpriseOrganizationAdministrationController::class, 'showOrganization'])->whereNumber('organization')->name('enterprise-organizations.show');
        Route::patch('/administration/organizations/{organization}', [EnterpriseOrganizationAdministrationController::class, 'updateOrganization'])->whereNumber('organization')->middleware('throttle:30,1')->name('enterprise-organizations.update');
        Route::post('/administration/organizations/{organization}/companies', [EnterpriseOrganizationAdministrationController::class, 'storeCompany'])->whereNumber('organization')->middleware('throttle:30,1')->name('enterprise-companies.store');
        Route::get('/administration/companies/{company}', [EnterpriseOrganizationAdministrationController::class, 'showCompany'])->whereNumber('company')->name('enterprise-companies.show');
        Route::patch('/administration/companies/{company}', [EnterpriseOrganizationAdministrationController::class, 'updateCompany'])->whereNumber('company')->middleware('throttle:30,1')->name('enterprise-companies.update');
        Route::post('/administration/companies/{company}/legal-entities', [EnterpriseOrganizationAdministrationController::class, 'storeLegalEntity'])->whereNumber('company')->middleware('throttle:30,1')->name('enterprise-legal-entities.store');
        Route::get('/administration/legal-entities/{legalEntity}', [EnterpriseOrganizationAdministrationController::class, 'showLegalEntity'])->whereNumber('legalEntity')->name('enterprise-legal-entities.show');
        Route::patch('/administration/legal-entities/{legalEntity}', [EnterpriseOrganizationAdministrationController::class, 'updateLegalEntity'])->whereNumber('legalEntity')->middleware('throttle:30,1')->name('enterprise-legal-entities.update');
        Route::post('/administration/legal-entities/{legalEntity}/business-units', [EnterpriseOrganizationAdministrationController::class, 'storeBusinessUnit'])->whereNumber('legalEntity')->middleware('throttle:30,1')->name('enterprise-business-units.store');
        Route::get('/administration/business-units/{businessUnit}', [EnterpriseOrganizationAdministrationController::class, 'showBusinessUnit'])->whereNumber('businessUnit')->name('enterprise-business-units.show');
        Route::patch('/administration/business-units/{businessUnit}', [EnterpriseOrganizationAdministrationController::class, 'updateBusinessUnit'])->whereNumber('businessUnit')->middleware('throttle:30,1')->name('enterprise-business-units.update');
        Route::post('/administration/business-units/{businessUnit}/stores/{store}', [EnterpriseOrganizationAdministrationController::class, 'assignStore'])->whereNumber('businessUnit')->whereNumber('store')->middleware('throttle:30,1')->name('enterprise-business-units.stores.assign');
        Route::get('/administration/system-health', EnterpriseSystemHealthController::class)->name('enterprise-system-health.index');
        Route::get('/administration/modules', [EnterpriseModuleAdministrationController::class, 'index'])->name('enterprise-modules.index');
        Route::get('/administration/modules/{module}', [EnterpriseModuleAdministrationController::class, 'show'])
            ->where('module', '[a-z][a-z0-9_]*')
            ->name('enterprise-modules.show');
        Route::post('/administration/modules/{module}/lifecycle', [EnterpriseModuleAdministrationController::class, 'lifecycle'])
            ->where('module', '[a-z][a-z0-9_]*')
            ->middleware('throttle:30,1')
            ->name('enterprise-modules.lifecycle');
        Route::post('/saved-views', [EnterpriseSavedViewController::class, 'store'])
            ->middleware('throttle:30,1')
            ->name('enterprise-saved-views.store');
        Route::delete('/saved-views/{savedView}', [EnterpriseSavedViewController::class, 'destroy'])
            ->whereNumber('savedView')
            ->middleware('throttle:30,1')
            ->name('enterprise-saved-views.destroy');
        Route::get('/settings', SettingsCenterController::class)->name('settings.index');
        Route::get('/settings/whatsapp', [WhatsAppFoundationController::class, 'index'])->name('whatsapp.index');
        Route::put('/settings/whatsapp', [WhatsAppFoundationController::class, 'update'])->middleware('throttle:30,1')->name('whatsapp.update');
        Route::get('/settings/whatsapp/connections', [WhatsAppConnectionController::class, 'index'])->name('whatsapp.connections.index');
        Route::post('/settings/whatsapp/connections', [WhatsAppConnectionController::class, 'store'])->middleware('throttle:30,1')->name('whatsapp.connections.store');
        Route::patch('/settings/whatsapp/connections/{profile}', [WhatsAppConnectionController::class, 'update'])->middleware('throttle:30,1')->name('whatsapp.connections.update');
        Route::put('/settings/whatsapp/connections/{profile}/credential', [WhatsAppConnectionController::class, 'replaceCredential'])->middleware('throttle:20,1')->name('whatsapp.connections.credential');
        Route::post('/settings/whatsapp/connections/{profile}/validate', [WhatsAppConnectionController::class, 'validateConnection'])->middleware('throttle:20,1')->name('whatsapp.connections.validate');
        Route::post('/settings/whatsapp/connections/{profile}/primary', [WhatsAppConnectionController::class, 'switchPrimary'])->middleware('throttle:10,1')->name('whatsapp.connections.primary');
        Route::post('/settings/whatsapp/connections/{profile}/receive-only', [WhatsAppConnectionController::class, 'receiveOnly'])->middleware('throttle:20,1')->name('whatsapp.connections.receive-only');
        Route::post('/settings/whatsapp/connections/{profile}/deactivate', [WhatsAppConnectionController::class, 'deactivate'])->middleware('throttle:20,1')->name('whatsapp.connections.deactivate');
        Route::prefix('whatsapp/inbound-alerts')
            ->name('whatsapp-inbound-alerts.')
            ->middleware('whatsapp.capability:agent_inbox')
            ->group(function (): void {
                Route::get('/', [WhatsAppInboundAlertController::class, 'feed'])
                    ->middleware('throttle:120,1')
                    ->name('feed');
                Route::patch('/{alert}/dismiss', [WhatsAppInboundAlertController::class, 'dismiss'])
                    ->whereNumber('alert')
                    ->middleware('throttle:120,1')
                    ->name('dismiss');
                Route::patch('/{alert}/open', [WhatsAppInboundAlertController::class, 'open'])
                    ->whereNumber('alert')
                    ->middleware('throttle:120,1')
                    ->name('open');
            });
        Route::prefix('whatsapp/inbox')
            ->name('whatsapp-inbox.')
            ->middleware('whatsapp.capability:agent_inbox')
            ->group(function (): void {
                Route::get('/', [WhatsAppInboxController::class, 'index'])->name('index');
                Route::get('/{conversation}/messages/{message}/attachment', [WhatsAppInboxController::class, 'attachment'])
                    ->whereNumber('conversation')
                    ->whereNumber('message')
                    ->name('attachments.show');
                Route::post('/{conversation}/reply', [WhatsAppInboxController::class, 'reply'])->whereNumber('conversation')->name('reply');
                Route::post('/{conversation}/notes', [WhatsAppInboxController::class, 'note'])->whereNumber('conversation')->name('note');
                Route::patch('/{conversation}/assignment', [WhatsAppInboxController::class, 'assign'])->whereNumber('conversation')->name('assign');
                Route::patch('/{conversation}/status', [WhatsAppInboxController::class, 'transition'])->whereNumber('conversation')->name('transition');
                Route::post('/{conversation}/tags', [WhatsAppInboxController::class, 'tag'])->whereNumber('conversation')->name('tag');
                Route::patch('/my-status', [WhatsAppInboxController::class, 'updateMyStatus'])->name('status');
            });
        Route::prefix('whatsapp/calls')
            ->name('whatsapp-calls.')
            ->middleware('whatsapp.capability:calls')
            ->group(function (): void {
                Route::get('/feed', [WhatsAppCallController::class, 'feed'])
                    ->middleware('throttle:120,1')
                    ->name('feed');
                Route::get('/conversations/{conversation}/permission', [WhatsAppCallController::class, 'permission'])
                    ->whereNumber('conversation')
                    ->middleware('throttle:60,1')
                    ->name('permission');
                Route::post('/conversations/{conversation}/permission', [WhatsAppCallController::class, 'requestPermission'])
                    ->whereNumber('conversation')
                    ->middleware('throttle:20,1')
                    ->name('permission.request');
                Route::post('/conversations/{conversation}/start', [WhatsAppCallController::class, 'start'])
                    ->whereNumber('conversation')
                    ->middleware('throttle:30,1')
                    ->name('start');
                Route::post('/{call}/action', [WhatsAppCallController::class, 'action'])
                    ->whereNumber('call')
                    ->middleware('throttle:60,1')
                    ->name('action');
            });
        Route::prefix('whatsapp/supervisor')
            ->name('whatsapp-supervisor.')
            ->middleware('whatsapp.capability:agent_inbox')
            ->group(function (): void {
                Route::get('/', [WhatsAppSupervisorController::class, 'index'])->name('index');
                Route::post('/leads', [WhatsAppSupervisorController::class, 'saveLead'])->name('leads.store');
                Route::post('/follow-ups', [WhatsAppSupervisorController::class, 'createFollowUp'])->name('follow-ups.store');
                Route::patch('/follow-ups/{followUp}', [WhatsAppSupervisorController::class, 'transitionFollowUp'])->whereNumber('followUp')->name('follow-ups.transition');
                Route::put('/conversations/{conversation}/outcome', [WhatsAppSupervisorController::class, 'recordOutcome'])->whereNumber('conversation')->name('outcomes.store');
            });
        Route::prefix('smart-ordering/qr')
            ->name('smart-ordering.qr.')
            ->group(function (): void {
                Route::get('/', [SmartOrderingQrController::class, 'index'])->name('index');
                Route::post('/', [SmartOrderingQrController::class, 'store'])->middleware('throttle:30,1')->name('store');
                Route::patch('/{qr:public_id}', [SmartOrderingQrController::class, 'update'])->whereUuid('qr')->middleware('throttle:60,1')->name('update');
                Route::post('/{qr:public_id}/toggle', [SmartOrderingQrController::class, 'toggle'])->whereUuid('qr')->middleware('throttle:60,1')->name('toggle');
                Route::post('/{qr:public_id}/rotate', [SmartOrderingQrController::class, 'rotate'])->whereUuid('qr')->middleware('throttle:20,1')->name('rotate');
                Route::get('/{qr:public_id}/asset.svg', [SmartOrderingQrController::class, 'svg'])->whereUuid('qr')->name('svg');
                Route::get('/{qr:public_id}/asset.pdf', [SmartOrderingQrController::class, 'pdf'])->whereUuid('qr')->name('pdf');
            });
        Route::prefix('whatsapp/campaigns')
            ->name('whatsapp-campaigns.')
            ->middleware('whatsapp.capability:campaigns')
            ->group(function (): void {
                Route::get('/', [WhatsAppCampaignController::class, 'index'])->name('index');
                Route::get('/customers', [WhatsAppCampaignController::class, 'customers'])
                    ->middleware('throttle:120,1')
                    ->name('customers');
                Route::post('/preview', [WhatsAppCampaignController::class, 'preview'])->name('preview');
                Route::post('/audience/import/preview', [WhatsAppCampaignController::class, 'importPreview'])
                    ->middleware('throttle:20,1')
                    ->name('audience.import.preview');
                Route::post('/audience/import/{batch}/commit', [WhatsAppCampaignController::class, 'importCommit'])
                    ->whereNumber('batch')
                    ->middleware('throttle:20,1')
                    ->name('audience.import.commit');
                Route::post('/audience/manual', [WhatsAppCampaignController::class, 'manualContacts'])
                    ->middleware('throttle:30,1')
                    ->name('audience.manual');
                Route::post('/templates', [WhatsAppCampaignController::class, 'storeTemplate'])->name('templates.store');
                Route::post('/templates/meta', [WhatsAppCampaignController::class, 'createMetaTemplate'])
                    ->middleware('throttle:10,1')
                    ->name('templates.meta.store');
                Route::post('/templates/meta/sync', [WhatsAppCampaignController::class, 'syncMetaTemplates'])
                    ->middleware('throttle:60,1')
                    ->name('templates.meta.sync');
                Route::post('/consents', [WhatsAppCampaignController::class, 'storeConsent'])->name('consents.store');
                Route::post('/', [WhatsAppCampaignController::class, 'storeCampaign'])->name('store');
                Route::get('/{campaign}/report', [WhatsAppCampaignController::class, 'report'])
                    ->whereNumber('campaign')
                    ->name('report');
                Route::get('/{campaign}/export/{format}', [WhatsAppCampaignController::class, 'export'])
                    ->whereNumber('campaign')
                    ->whereIn('format', ['csv', 'xlsx'])
                    ->name('export');
                Route::post('/{campaign}/snapshot', [WhatsAppCampaignController::class, 'snapshot'])->whereNumber('campaign')->name('snapshot');
                Route::post('/{campaign}/schedule', [WhatsAppCampaignController::class, 'schedule'])->whereNumber('campaign')->name('schedule');
                Route::post('/{campaign}/pause', [WhatsAppCampaignController::class, 'pause'])->whereNumber('campaign')->name('pause');
                Route::post('/{campaign}/cancel', [WhatsAppCampaignController::class, 'cancel'])->whereNumber('campaign')->name('cancel');
            });
        Route::prefix('whatsapp/chatbot')
            ->name('whatsapp-chatbot.')
            ->group(function (): void {
                Route::put('/runtime', [WhatsAppChatbotController::class, 'updateRuntime'])
                    ->middleware('throttle:30,1')
                    ->name('runtime.update');
                Route::post('/ai/test', [WhatsAppChatbotController::class, 'testAi'])
                    ->middleware('throttle:30,1')
                    ->name('ai.test');
                Route::patch('/features/{feature}', [WhatsAppChatbotController::class, 'updateFeature'])
                    ->whereNumber('feature')
                    ->middleware('throttle:60,1')
                    ->name('features.update');
                Route::post('/flows', [WhatsAppChatbotController::class, 'storeFlow'])
                    ->middleware('throttle:30,1')
                    ->name('flows.store');
                Route::patch('/flows/{flow}', [WhatsAppChatbotController::class, 'updateFlow'])
                    ->whereNumber('flow')
                    ->middleware('throttle:30,1')
                    ->name('flows.update');
                Route::post('/flows/{flow}/publish', [WhatsAppChatbotController::class, 'publishFlow'])
                    ->whereNumber('flow')
                    ->middleware('throttle:30,1')
                    ->name('flows.publish');
                Route::post('/flows/{flow}/rollback', [WhatsAppChatbotController::class, 'rollbackFlow'])
                    ->whereNumber('flow')
                    ->middleware('throttle:30,1')
                    ->name('flows.rollback');
                Route::post('/segments', [WhatsAppChatbotController::class, 'storeSegment'])
                    ->middleware('throttle:30,1')
                    ->name('segments.store');
                Route::patch('/segments/{segment}', [WhatsAppChatbotController::class, 'updateSegment'])
                    ->whereNumber('segment')
                    ->middleware('throttle:30,1')
                    ->name('segments.update');
                Route::get('/preview/{screen}', [WhatsAppChatbotController::class, 'preview'])
                    ->whereIn('screen', ['home', 'new-order', 'reorder', 'products', 'cart', 'orders', 'finance', 'support'])
                    ->name('preview');
                Route::get('/{section?}', [WhatsAppChatbotController::class, 'index'])
                    ->whereIn('section', ['control', 'features', 'flows', 'ai', 'segments', 'analytics'])
                    ->name('index');
            });
        Route::get('/van-finance-support', VanFinanceSupportController::class)->name('van-finance-support.index');
        Route::prefix('assistant')
            ->name('assistant.')
            ->middleware('throttle:assistant')
            ->group(function (): void {
                Route::get('/bootstrap', [AssistantController::class, 'bootstrap'])->name('bootstrap');
                Route::get('/conversations', [AssistantController::class, 'index'])->name('conversations.index');
                Route::post('/conversations', [AssistantController::class, 'storeConversation'])->name('conversations.store');
                Route::get('/conversations/{conversationId}/messages', [AssistantController::class, 'messages'])->whereUuid('conversationId')->name('conversations.messages.index');
                Route::post('/conversations/{conversationId}/messages', [AssistantController::class, 'storeMessage'])->whereUuid('conversationId')->name('conversations.messages.store');
                Route::post('/conversations/{conversationId}/clear', [AssistantController::class, 'clear'])->whereUuid('conversationId')->name('conversations.clear');
                Route::delete('/conversations/{conversationId}', [AssistantController::class, 'destroy'])->whereUuid('conversationId')->name('conversations.destroy');
            });
        Route::get('/profile', [AdminProfileController::class, 'index'])->name('profile.index');
        Route::get('/app-preview', [AppPreviewController::class, 'index'])->name('app-preview.index');
        Route::get('/app-preview/targets', [AppPreviewController::class, 'targets'])->name('app-preview.targets');
        Route::get('/app-preview/storefront-configuration', AppPreviewConfigurationController::class)->name('app-preview.storefront-configuration');
        Route::get('/app-preview/events', AppPreviewInvalidationController::class)->name('app-preview.events');
        Route::post('/app-preview/sessions', [AppPreviewController::class, 'storeSession'])->name('app-preview.sessions.store');
        Route::delete('/app-preview/sessions/{sessionId}', [AppPreviewController::class, 'destroySession'])
            ->whereUuid('sessionId')
            ->name('app-preview.sessions.destroy');
        Route::prefix('field-operations')->name('field-operations.')->group(function (): void {
            Route::get('/', [FieldOperationsController::class, 'overview'])->name('overview');
            Route::get('/fleet-map', [FieldOperationsController::class, 'fleet'])->name('fleet');
            Route::get('/fleet-map/feed', [FieldOperationsController::class, 'fleetFeed'])->name('fleet.feed');
            Route::get('/vans', [FieldOperationsController::class, 'vans'])->name('vans');
            Route::get('/vans/{van}', [FieldOperationsController::class, 'showVan'])->whereNumber('van')->name('vans.show');
            Route::post('/vans', [FieldOperationsController::class, 'storeVan'])->name('vans.store');
            Route::post('/vans/{van}/suspend', [FieldOperationsController::class, 'suspendVan'])->whereNumber('van')->name('vans.suspend');
            Route::get('/assignments', [FieldOperationsController::class, 'assignments'])->name('assignments');
            Route::post('/vans/{van}/assignments', [FieldOperationsController::class, 'storeAssignment'])->whereNumber('van')->name('assignments.store');
            Route::patch('/assignments/{assignment}', [FieldOperationsController::class, 'updateAssignment'])->whereNumber('assignment')->name('assignments.update');
            Route::delete('/assignments/{assignment}', [FieldOperationsController::class, 'destroyAssignment'])->whereNumber('assignment')->name('assignments.destroy');
            Route::get('/customers', [FieldOperationsController::class, 'customers'])->name('customers');
            Route::get('/visits', [FieldOperationsController::class, 'visits'])->name('visits');
            Route::post('/visits', [FieldOperationsController::class, 'storeVisit'])->name('visits.store');
            Route::post('/visits/{visit}/transition', [FieldOperationsController::class, 'transitionVisit'])->whereNumber('visit')->name('visits.transition');
            Route::get('/territories', [FieldOperationsController::class, 'territories'])->name('territories');
            Route::post('/geography', [FieldOperationsController::class, 'storeGeography'])->name('geography.store');
            Route::post('/territories', [FieldOperationsController::class, 'storeTerritory'])->name('territories.store');
            Route::post('/territories/{territory}/geometry', [FieldOperationsController::class, 'storeGeometry'])->whereNumber('territory')->name('territories.geometry.store');
            Route::get('/address-quality', [FieldOperationsController::class, 'addressQuality'])->name('address-quality');
            Route::post('/address-quality/{review}/{action}', [FieldOperationsController::class, 'addressAction'])
                ->whereNumber('review')->whereIn('action', ['confirm', 'reject', 'reopen'])->name('address-quality.action');
            Route::get('/routing-policies', [FieldOperationsController::class, 'routingPolicies'])->name('routing');
            Route::post('/routing-policies', [FieldOperationsController::class, 'storeRoutingPolicy'])->name('routing.store');
            Route::post('/routing-policies/{routingPolicy}/{action}', [FieldOperationsController::class, 'routingAction'])
                ->whereNumber('routingPolicy')->whereIn('action', ['publish', 'simulate', 'rollback'])->name('routing.action');
            Route::get('/finance', [FieldOperationsController::class, 'finance'])->name('finance');
            Route::post('/finance/remittances/{remittance}/{action}', [FieldOperationsController::class, 'reviewRemittance'])
                ->whereNumber('remittance')->whereIn('action', ['approve', 'reject', 'reconcile'])->name('finance.remittances.review');
        });
        Route::get('/driver-live-tracking', [DriverLiveTrackingDashboardController::class, 'index'])->name('driver-live-tracking.index');
        Route::get('/driver-live-tracking/feed', [DriverLiveTrackingController::class, 'feed'])->name('driver-live-tracking.feed');
        Route::get('/driver-live-tracking/lookups', [DriverLiveTrackingDashboardController::class, 'lookups'])->name('driver-live-tracking.lookups');
        Route::get('/driver-live-tracking/assignments/{assignment}/evidence', [DriverLiveTrackingDashboardController::class, 'evidence'])
            ->whereNumber('assignment')
            ->name('driver-live-tracking.evidence');
        Route::get('/driver-live-tracking/assignments/{assignment}/proofs/{proof}', [DriverLiveTrackingDashboardController::class, 'proof'])
            ->whereNumber('assignment')
            ->whereNumber('proof')
            ->name('driver-live-tracking.proofs.show');
        Route::get('/erp/bi', [EnterpriseBiController::class, 'index'])->name('erp.bi');
        Route::post('/erp/bi/schedules', [EnterpriseBiScheduleController::class, 'store'])->middleware('throttle:30,1')->name('erp.bi.schedules.store');
        Route::patch('/erp/bi/schedules/{schedule}', [EnterpriseBiScheduleController::class, 'update'])->whereNumber('schedule')->middleware('throttle:30,1')->name('erp.bi.schedules.update');
        Route::get('/erp/customer-service', [CustomerServiceWorkspaceController::class, 'index'])->name('erp.customer-service');
        Route::post('/erp/customer-service/cases', [CustomerServiceWorkspaceController::class, 'storeCase'])->middleware('throttle:30,1')->name('erp.customer-service.cases.store');
        Route::get('/erp/customer-service/cases/{case}', [CustomerServiceWorkspaceController::class, 'showCase'])->whereNumber('case')->name('erp.customer-service.cases.show');
        Route::patch('/erp/customer-service/cases/{case}', [CustomerServiceWorkspaceController::class, 'updateCase'])->whereNumber('case')->middleware('throttle:30,1')->name('erp.customer-service.cases.update');
        Route::post('/erp/customer-service/cases/{case}/notes', [CustomerServiceWorkspaceController::class, 'addNote'])->whereNumber('case')->middleware('throttle:30,1')->name('erp.customer-service.cases.notes.store');
        Route::post('/erp/customer-service/cases/{case}/feedback', [CustomerServiceWorkspaceController::class, 'recordFeedback'])->whereNumber('case')->middleware('throttle:30,1')->name('erp.customer-service.cases.feedback.store');
        Route::get('/erp/customer-service/cases/{case}/notes/{note}/attachment', [CustomerServiceWorkspaceController::class, 'attachment'])->whereNumber('case')->whereNumber('note')->name('erp.customer-service.cases.notes.attachment');
        Route::get('/customer-360', [Customer360Controller::class, 'index'])->name('customer-360.index');
        Route::get('/customer-360/{platformCustomer}', [Customer360Controller::class, 'show'])->whereNumber('platformCustomer')->name('customer-360.show');
        Route::get('/customer-360/{platformCustomer}/finance-invoices', [Customer360Controller::class, 'financeInvoices'])->whereNumber('platformCustomer')->name('customer-360.finance-invoices');
        Route::post('/customer-360/{platformCustomer}/finance-entries', [Customer360Controller::class, 'storeFinanceEntry'])->whereNumber('platformCustomer')->name('customer-360.finance-entries.store');
        Route::post('/customer-360/{platformCustomer}/invoices/{invoice}/settle', [Customer360Controller::class, 'settleInvoice'])->whereNumber('platformCustomer')->whereNumber('invoice')->name('customer-360.invoices.settle');
        Route::post('/customer-360/{platformCustomer}/finance-entries/{ledgerEntry}/reverse', [Customer360Controller::class, 'reverseFinanceEntry'])->whereNumber('platformCustomer')->whereNumber('ledgerEntry')->name('customer-360.finance-entries.reverse');
        Route::get('/customer-360/{platformCustomer}/statement/export', [Customer360Controller::class, 'statementExport'])->whereNumber('platformCustomer')->name('customer-360.statement.export');
        Route::get('/customer-360/{platformCustomer}/reports/{report}', [Customer360Controller::class, 'replicaReport'])
            ->whereNumber('platformCustomer')
            ->whereIn('report', ['account-statement', 'item-detail', 'item-summary'])
            ->name('customer-360.reports.show');
        Route::get('/customer-360/{platformCustomer}/reports/{report}/pdf', [Customer360Controller::class, 'replicaReportPdf'])
            ->whereNumber('platformCustomer')
            ->whereIn('report', ['account-statement', 'item-detail', 'item-summary'])
            ->name('customer-360.reports.pdf');
        Route::patch('/customer-360/{platformCustomer}/credit-limit', [Customer360Controller::class, 'updateCreditLimit'])->whereNumber('platformCustomer')->name('customer-360.credit-limit.update');
        Route::get('/customer-360/{invalidCustomerReference}', [Customer360Controller::class, 'invalidReference'])
            ->where('invalidCustomerReference', '[^0-9]+')
            ->name('customer-360.invalid-reference');
        Route::post('/customer-360/{platformCustomer}/addresses', [Customer360Controller::class, 'storeAddress'])->whereNumber('platformCustomer')->name('customer-360.addresses.store');
        Route::patch('/customer-360/{platformCustomer}/addresses/{address}', [Customer360Controller::class, 'updateAddress'])->whereNumber('platformCustomer')->whereNumber('address')->name('customer-360.addresses.update');
        Route::post('/customer-360/{platformCustomer}/addresses/{address}/default', [Customer360Controller::class, 'setDefaultAddress'])->whereNumber('platformCustomer')->whereNumber('address')->name('customer-360.addresses.default');
        Route::delete('/customer-360/{platformCustomer}/addresses/{address}', [Customer360Controller::class, 'destroyAddress'])->whereNumber('platformCustomer')->whereNumber('address')->name('customer-360.addresses.destroy');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->whereNumber('invoice')->name('invoices.download');
        Route::post('/invoices/{invoice}/settle', [InvoiceController::class, 'settle'])->whereNumber('invoice')->name('invoices.settle');
        Route::post('/invoices/{invoice}/void-reissue', [InvoiceController::class, 'reissue'])->whereNumber('invoice')->name('invoices.reissue');
        Route::patch('/profile/password', [AdminProfileController::class, 'updatePassword'])->name('profile.password');
        Route::patch('/profile/locale', [AdminProfileController::class, 'updateLocale'])->name('profile.locale');
        Route::get('/b2b/dashboard', [B2bWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2b.dashboard');
        Route::get('/b2b/orders', [OrderOperationsController::class, 'b2bIndex'])->name('b2b.orders.canonical');
        Route::post('/b2b/orders/quote', [B2bWorkspaceController::class, 'quoteOrder'])->name('b2b.orders.quote');
        Route::post('/b2b/orders', [B2bWorkspaceController::class, 'storeOrder'])->name('b2b.orders.store');
        Route::patch('/b2b/orders/{order}', [B2bWorkspaceController::class, 'updateOrder'])->whereNumber('order')->name('b2b.orders.update');
        Route::post('/b2b/orders/{order}/status', [B2bWorkspaceController::class, 'transitionOrder'])->whereNumber('order')->name('b2b.orders.status');
        Route::post('/b2b/drivers/assign', [B2bWorkspaceController::class, 'assignDriver'])->name('b2b.drivers.assign');
        Route::patch('/b2b/orders/{order}/driver', [B2bWorkspaceController::class, 'reassignDriver'])->whereNumber('order')->name('b2b.orders.driver.reassign');
        Route::delete('/b2b/orders/{order}/driver', [B2bWorkspaceController::class, 'unassignDriver'])->whereNumber('order')->name('b2b.orders.driver.unassign');
        Route::post('/b2b/pricing', [B2bWorkspaceController::class, 'savePriceRule'])->name('b2b.pricing.save');
        Route::post('/b2b/clients', [B2bWorkspaceController::class, 'storeClient'])->name('b2b.clients.store');
        Route::patch('/b2b/clients/{account}/status', [B2bWorkspaceController::class, 'updateClientStatus'])->name('b2b.clients.status');
        Route::post('/b2b/categories', [B2bWorkspaceController::class, 'storeCategory'])->name('b2b.categories.store');
        Route::post('/b2b/products', [B2bWorkspaceController::class, 'storeProduct'])->name('b2b.products.store');
        Route::patch('/b2b/products/{product}', [B2bWorkspaceController::class, 'updateProduct'])->name('b2b.products.update');
        Route::post('/b2b/warehouses', [B2bWorkspaceController::class, 'storeWarehouse'])->name('b2b.warehouses.store');
        Route::post('/b2b/inventory', [B2bWorkspaceController::class, 'ensureInventory'])->name('b2b.inventory.ensure');
        Route::patch('/b2b/inventory/{inventory}/adjust', [B2bWorkspaceController::class, 'adjustInventory'])->whereNumber('inventory')->name('b2b.inventory.adjust');
        Route::post('/b2b/drivers', [B2bWorkspaceController::class, 'storeDriver'])->name('b2b.drivers.store');
        Route::patch('/b2b/drivers/{driver}/password', [B2bWorkspaceController::class, 'resetDriverPassword'])->whereNumber('driver')->name('b2b.drivers.password');
        Route::put('/b2b/settings/pricing-tiers', [B2bWorkspaceController::class, 'savePricingMode'])->name('b2b.settings.pricing-tiers');
        Route::put('/b2b/settings', [B2bWorkspaceController::class, 'saveSetting'])->name('b2b.settings.save');
        Route::put('/b2b/storefront/settings', [StorefrontDraftEditorController::class, 'wholesaleSettings'])->name('b2b.storefront.settings');
        Route::post('/b2b/storefront/sections', [StorefrontDraftEditorController::class, 'wholesaleSectionStore'])->name('b2b.storefront.sections.store');
        Route::patch('/b2b/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'wholesaleSectionUpdate'])->name('b2b.storefront.sections.update');
        Route::delete('/b2b/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'wholesaleSectionDestroy'])->name('b2b.storefront.sections.destroy');
        Route::post('/b2b/storefront/banners', [StorefrontDraftEditorController::class, 'wholesaleBannerStore'])->name('b2b.storefront.banners.store');
        Route::patch('/b2b/storefront/banners/{banner}', [StorefrontDraftEditorController::class, 'wholesaleBannerUpdate'])->name('b2b.storefront.banners.update');
        Route::delete('/b2b/storefront/banners/{banner}', [StorefrontDraftEditorController::class, 'wholesaleBannerDestroy'])->name('b2b.storefront.banners.destroy');
        Route::post('/b2b/storefront/publish', [StorefrontDraftEditorController::class, 'wholesalePublish'])->name('b2b.storefront.publish');
        Route::post('/b2b/storefront/discard', [StorefrontDraftEditorController::class, 'wholesaleDiscard'])->name('b2b.storefront.discard');
        Route::post('/b2b/finance/remittances/{remittance}/{action}', [B2bWorkspaceController::class, 'reviewRemittance'])
            ->whereNumber('remittance')
            ->whereIn('action', ['approve', 'reject', 'reconcile'])
            ->name('b2b.finance.remittances.review');
        Route::get('/b2b/pricing-approvals', [B2bWorkspaceController::class, 'show'])->defaults('module', 'pricing')->name('b2b.pricing-approvals');
        Route::get('/b2b/settings-permissions', [B2bWorkspaceController::class, 'show'])->defaults('module', 'settings')->name('b2b.settings-permissions');
        Route::get('/b2b/commercial/sales-control', [CommercialDashboardController::class, 'salesControl'])->name('commercial.sales-control');
        Route::get('/b2b/commercial/sales-control/products', [CommercialDashboardController::class, 'searchSalesControlProducts'])->name('commercial.sales-control.products');
        Route::put('/b2b/commercial/sales-control/{product}', [CommercialDashboardController::class, 'saveSalesControl'])->whereNumber('product')->name('commercial.sales-control.save');
        Route::get('/b2b/{module}', [B2bWorkspaceController::class, 'show'])->name('b2b.module');
        Route::get('/b2c/dashboard', [B2cWorkspaceController::class, 'show'])->defaults('module', 'dashboard')->name('b2c.dashboard');
        Route::get('/b2c/orders', [OrderOperationsController::class, 'b2cIndex'])->name('b2c.orders.canonical');
        Route::post('/b2c/merchant-intelligence/suggested-cart', [B2cWorkspaceController::class, 'applySuggestedWholesaleCart'])->name('b2c.merchant-intelligence.cart');
        Route::redirect('/b2c/commercial/sales-control', '/admin/b2b/commercial/sales-control', 302)->name('commercial.sales-control.legacy');
        Route::put('/b2c/commercial/feature-flags', [CommercialDashboardController::class, 'saveFeatureFlags'])->name('commercial.feature-flags.save');
        Route::get('/b2c/commercial/flash-offers', [CommercialDashboardController::class, 'flashOffers'])->name('commercial.flash-offers');
        Route::get('/b2c/commercial/flash-offers/{offer}/preview', [CommercialDashboardController::class, 'flashPreview'])->whereNumber('offer')->name('commercial.flash-offers.preview');
        Route::get('/b2c/commercial/flash-offers/{offer}/analytics', [CommercialDashboardController::class, 'flashAnalytics'])->whereNumber('offer')->name('commercial.flash-offers.analytics');
        Route::post('/b2c/commercial/flash-offers', [CommercialDashboardController::class, 'saveFlashOffer'])->name('commercial.flash-offers.save');
        Route::post('/b2c/commercial/flash-offers/{offer}/action', [CommercialDashboardController::class, 'flashAction'])->whereNumber('offer')->name('commercial.flash-offers.action');
        Route::post('/b2c/orders/quote', [B2cWorkspaceController::class, 'quoteOrder'])->name('b2c.orders.quote');
        Route::post('/b2c/orders', [B2cWorkspaceController::class, 'storeOrder'])->name('b2c.orders.store');
        Route::patch('/b2c/orders/{order}', [B2cWorkspaceController::class, 'updateOrder'])->whereNumber('order')->name('b2c.orders.update');
        Route::post('/b2c/orders/{order}/status', [B2cWorkspaceController::class, 'transitionOrder'])->whereNumber('order')->name('b2c.orders.status');
        Route::post('/b2c/wholesale-purchases/{order}/available-for-sale', [B2cWorkspaceController::class, 'makeWholesalePurchaseAvailable'])->whereNumber('order')->name('b2c.wholesale-purchases.available-for-sale');
        Route::post('/b2c/drivers/assign', [B2cWorkspaceController::class, 'assignDriver'])->name('b2c.drivers.assign');
        Route::patch('/b2c/orders/{order}/driver', [B2cWorkspaceController::class, 'reassignDriver'])->whereNumber('order')->name('b2c.orders.driver.reassign');
        Route::delete('/b2c/orders/{order}/driver', [B2cWorkspaceController::class, 'unassignDriver'])->whereNumber('order')->name('b2c.orders.driver.unassign');
        Route::patch('/b2c/drivers/{driver}/password', [B2cWorkspaceController::class, 'resetDriverPassword'])->whereNumber('driver')->name('b2c.drivers.password');
        Route::post('/b2c/warehouses', [B2cWorkspaceController::class, 'storeWarehouse'])->name('b2c.warehouses.store');
        Route::post('/b2c/inventory', [B2cWorkspaceController::class, 'ensureInventory'])->name('b2c.inventory.ensure');
        Route::post('/b2c/inventory/{inventory}/adjust', [B2cWorkspaceController::class, 'adjustInventory'])->whereNumber('inventory')->name('b2c.inventory.adjust');
        Route::post('/b2c/inventory/transfer', [B2cWorkspaceController::class, 'transferInventory'])->name('b2c.inventory.transfer');
        Route::put('/b2c/settings', [B2cWorkspaceController::class, 'saveSetting'])->name('b2c.settings.save');
        Route::get('/b2c/storefront-preview', [B2cWorkspaceController::class, 'show'])->defaults('module', 'storefront')->name('b2c.storefront-preview');
        Route::put('/b2c/storefront/settings', [StorefrontDraftEditorController::class, 'retailSettings'])->name('b2c.storefront.settings');
        Route::post('/b2c/storefront/sections', [StorefrontDraftEditorController::class, 'retailSectionStore'])->name('b2c.storefront.sections.store');
        Route::patch('/b2c/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'retailSectionUpdate'])->name('b2c.storefront.sections.update');
        Route::delete('/b2c/storefront/sections/{section}', [StorefrontDraftEditorController::class, 'retailSectionDestroy'])->name('b2c.storefront.sections.destroy');
        Route::post('/b2c/storefront/service-zones', [StorefrontDraftEditorController::class, 'retailZoneStore'])->name('b2c.storefront.zones.store');
        Route::delete('/b2c/storefront/service-zones/{zone}', [StorefrontDraftEditorController::class, 'retailZoneDestroy'])->name('b2c.storefront.zones.destroy');
        Route::post('/b2c/storefront/publish', [StorefrontDraftEditorController::class, 'retailPublish'])->name('b2c.storefront.publish');
        Route::post('/b2c/storefront/discard', [StorefrontDraftEditorController::class, 'retailDiscard'])->name('b2c.storefront.discard');
        Route::get('/b2c/{module}', [B2cWorkspaceController::class, 'show'])->name('b2c.module');
        Route::get('/catalog', [CatalogManagementController::class, 'index'])->name('catalog.index');
        Route::get('/catalog/categories', [CatalogManagementController::class, 'categories'])->name('catalog.categories.index');
        Route::get('/catalog/import/sample', [CatalogManagementController::class, 'downloadImportSample'])->name('catalog.import.sample');
        Route::post('/catalog/import/preview', [CatalogManagementController::class, 'previewImport'])->name('catalog.import.preview');
        Route::post('/catalog/import/commit', [CatalogManagementController::class, 'commitImport'])->name('catalog.import.commit');
        Route::get('/catalog/export', [CatalogManagementController::class, 'exportCatalog'])->name('catalog.export');
        Route::get('/lookups', [LookupManagementController::class, 'index'])->name('lookups.index');
        Route::post('/lookups/{type}', [LookupManagementController::class, 'store'])->whereIn('type', ['brands', 'units'])->name('lookups.store');
        Route::patch('/lookups/{type}/{lookup}', [LookupManagementController::class, 'update'])->whereIn('type', ['brands', 'units'])->name('lookups.update');
        Route::patch('/lookups/{type}/{lookup}/status', [LookupManagementController::class, 'toggle'])->whereIn('type', ['brands', 'units'])->name('lookups.toggle');
        Route::delete('/lookups/{type}/{lookup}', [LookupManagementController::class, 'destroy'])->whereIn('type', ['brands', 'units'])->name('lookups.destroy');
        Route::get('/business', [BusinessManagementController::class, 'index'])->name('business.index');
        Route::post('/business/warehouses', [BusinessManagementController::class, 'storeWarehouse'])->name('business.warehouses.store');
        Route::patch('/business/warehouses/{warehouse}', [BusinessManagementController::class, 'updateWarehouse'])->name('business.warehouses.update');
        Route::post('/business/inventory', [BusinessManagementController::class, 'ensureInventory'])->name('business.inventory.ensure');
        Route::patch('/business/inventory/{inventory}', [BusinessManagementController::class, 'adjustInventory'])->name('business.inventory.adjust');
        Route::post('/business/customers', [BusinessManagementController::class, 'storeCustomer'])->name('business.customers.store');
        Route::patch('/business/customers/{customer}', [BusinessManagementController::class, 'updateCustomer'])->name('business.customers.update');
        Route::delete('/business/customers/{customer}', [BusinessManagementController::class, 'destroyCustomer'])->name('business.customers.destroy');
        Route::post('/business/promotions', [BusinessManagementController::class, 'storePromotion'])->name('business.promotions.store');
        Route::patch('/business/promotions/{promotion}', [BusinessManagementController::class, 'updatePromotion'])->name('business.promotions.update');
        Route::delete('/business/promotions/{promotion}', [BusinessManagementController::class, 'destroyPromotion'])->name('business.promotions.destroy');
        Route::post('/business/banners', [StorefrontDraftEditorController::class, 'retailBannerStore'])->name('business.banners.store');
        Route::patch('/business/banners/{banner}', [StorefrontDraftEditorController::class, 'retailBannerUpdate'])->name('business.banners.update');
        Route::delete('/business/banners/{banner}', [StorefrontDraftEditorController::class, 'retailBannerDestroy'])->name('business.banners.destroy');
        Route::post('/business/drivers', [BusinessManagementController::class, 'storeDriver'])->name('business.drivers.store');
        Route::patch('/business/drivers/{driver}', [BusinessManagementController::class, 'updateDriver'])->name('business.drivers.update');
        Route::delete('/business/drivers/{driver}', [BusinessManagementController::class, 'destroyDriver'])->name('business.drivers.destroy');
        Route::post('/catalog/products', [CatalogManagementController::class, 'storeProduct'])->name('catalog.products.store');
        Route::patch('/catalog/products/{product}', [CatalogManagementController::class, 'updateProduct'])->name('catalog.products.update');
        Route::patch('/catalog/products/{product}/images/{image}', [CatalogManagementController::class, 'updateProductImage'])->name('catalog.products.images.update');
        Route::delete('/catalog/products/{product}/images/{image}', [CatalogManagementController::class, 'destroyProductImage'])->name('catalog.products.images.destroy');
        Route::delete('/catalog/products/{product}', [CatalogManagementController::class, 'destroyProduct'])->name('catalog.products.destroy');
        Route::post('/catalog/products/{product}/store', [CatalogManagementController::class, 'assignProduct'])->name('catalog.products.assign');
        Route::put('/catalog/products/{product}/attributes', [CatalogManagementController::class, 'saveProductAttributes'])->whereNumber('product')->name('catalog.products.attributes.save');
        Route::post('/catalog/products/{product}/lifecycle', [CatalogManagementController::class, 'transitionProductLifecycle'])->whereNumber('product')->name('catalog.products.lifecycle');
        Route::post('/catalog/attributes', [CatalogManagementController::class, 'storeAttribute'])->name('catalog.attributes.store');
        Route::patch('/catalog/attributes/{attribute}', [CatalogManagementController::class, 'updateAttribute'])->whereNumber('attribute')->name('catalog.attributes.update');
        Route::post('/catalog/categories', [CatalogManagementController::class, 'storeCategory'])->name('catalog.categories.store');
        Route::patch('/catalog/categories/{category}', [CatalogManagementController::class, 'updateCategory'])->name('catalog.categories.update');
        Route::delete('/catalog/categories/{category}', [CatalogManagementController::class, 'destroyCategory'])->name('catalog.categories.destroy');
        Route::post('/catalog/brands', [CatalogManagementController::class, 'storeBrand'])->name('catalog.brands.store');
        Route::delete('/catalog/brands/{brand}', [CatalogManagementController::class, 'destroyBrand'])->name('catalog.brands.destroy');
        Route::post('/catalog/units', [CatalogManagementController::class, 'storeUnit'])->name('catalog.units.store');
        Route::get('/retail-stores', [RetailStoreProvisioningController::class, 'index'])->name('retail-stores.index');
        Route::post('/retail-stores', [RetailStoreProvisioningController::class, 'store'])->name('retail-stores.store');
        Route::patch('/retail-stores/{store}', [RetailStoreProvisioningController::class, 'update'])->name('retail-stores.update');
        Route::patch('/retail-stores/{store}/features/restaurant-saas', [RetailStoreProvisioningController::class, 'restaurantSaas'])->name('retail-stores.restaurant-saas');
        Route::post('/retail-stores/{store}/roles', [RetailStoreProvisioningController::class, 'assignRole'])->name('retail-stores.roles.assign');
        Route::delete('/retail-stores/{store}/roles/{assignment}', [RetailStoreProvisioningController::class, 'removeRole'])->name('retail-stores.roles.remove');
        Route::post('/retail-stores/{store}/inspect', [RetailStoreProvisioningController::class, 'inspect'])->name('retail-stores.inspect');
        Route::get('/live-ads', [LiveAdController::class, 'index'])->name('live-ads.index');
        Route::post('/live-ads', [LiveAdController::class, 'store'])->name('live-ads.store');
        Route::patch('/live-ads/{liveAd}', [LiveAdController::class, 'update'])->name('live-ads.update');
        Route::patch('/live-ads/{liveAd}/toggle', [LiveAdController::class, 'toggle'])->name('live-ads.toggle');
        Route::delete('/live-ads/{liveAd}', [LiveAdController::class, 'destroy'])->name('live-ads.destroy');
        Route::get('/operations/lookups', [SystemLookupController::class, 'index'])->name('operations.lookups.index');
        Route::post('/operations/lookups/{type}', [SystemLookupController::class, 'store'])->name('operations.lookups.store');
        Route::patch('/operations/lookups/{type}/{lookup}', [SystemLookupController::class, 'update'])->whereNumber('lookup')->name('operations.lookups.update');
        Route::get('/operations/orders', [OrderOperationsController::class, 'index'])->name('operations.orders.index');
        Route::get('/operations/orders/new-order-feed', [OrderOperationsController::class, 'newOrderFeed'])->name('operations.orders.new-order-feed');
        Route::get('/operations/orders/van-assignments/{assignment}/proofs/{proof}', [OrderOperationsController::class, 'vanProof'])
            ->whereNumber('assignment')
            ->whereNumber('proof')
            ->name('operations.orders.van-proofs.show');
        Route::get('/operations/orders/lookups/{type}', [OrderOperationsController::class, 'newOrderLookup'])
            ->whereIn('type', ['customer', 'product', 'address'])
            ->name('operations.orders.lookups');
        Route::post('/operations/orders/quote', [OrderOperationsController::class, 'quoteNewOrder'])->name('operations.orders.quote');
        Route::post('/operations/orders', [OrderOperationsController::class, 'storeNewOrder'])->name('operations.orders.store');
        Route::patch('/operations/orders/{order}', [OrderOperationsController::class, 'update'])->whereNumber('order')->name('operations.orders.update');
        Route::post('/operations/orders/{order}/remind-driver', [OrderOperationsController::class, 'remindDriver'])->whereNumber('order')->name('operations.orders.remind');
        Route::post('/operations/orders/{order}/status', [OrderOperationsController::class, 'transition'])->whereNumber('order')->name('operations.orders.transition');
        Route::patch('/operations/orders/{order}/dispatch', [OrderOperationsController::class, 'dispatch'])->whereNumber('order')->name('operations.orders.dispatch');
        Route::delete('/operations/orders/{order}/dispatch', [OrderOperationsController::class, 'clearDispatch'])->whereNumber('order')->name('operations.orders.dispatch.clear');
        Route::patch('/operations/orders/{order}/driver', [OrderOperationsController::class, 'reassign'])->whereNumber('order')->name('operations.orders.reassign');
        Route::delete('/operations/orders/{order}/driver', [OrderOperationsController::class, 'unassign'])->whereNumber('order')->name('operations.orders.unassign');
        Route::prefix('erp/treasury')->name('erp.treasury.')->group(function (): void {
            Route::get('/', [EnterpriseTreasuryController::class, 'index'])->name('index');
            Route::post('/receipts', [EnterpriseTreasuryController::class, 'storeReceipt'])->middleware('throttle:30,1')->name('receipts.store');
            Route::post('/receipts/{receipt}/post', [EnterpriseTreasuryController::class, 'postReceipt'])->whereNumber('receipt')->middleware('throttle:30,1')->name('receipts.post');
            Route::post('/receipts/{receipt}/cancel', [EnterpriseTreasuryController::class, 'cancelReceipt'])->whereNumber('receipt')->middleware('throttle:30,1')->name('receipts.cancel');
            Route::post('/payments', [EnterpriseTreasuryController::class, 'storePayment'])->middleware('throttle:30,1')->name('payments.store');
            Route::post('/payments/{payment}/post', [EnterpriseTreasuryController::class, 'postPayment'])->whereNumber('payment')->middleware('throttle:30,1')->name('payments.post');
            Route::post('/payments/{payment}/cancel', [EnterpriseTreasuryController::class, 'cancelPayment'])->whereNumber('payment')->middleware('throttle:30,1')->name('payments.cancel');
            Route::post('/cheques', [EnterpriseTreasuryController::class, 'storeCheque'])->middleware('throttle:30,1')->name('cheques.store');
            Route::post('/cheques/{cheque}/transition', [EnterpriseTreasuryController::class, 'transitionCheque'])->whereNumber('cheque')->middleware('throttle:30,1')->name('cheques.transition');
            Route::post('/reconciliation', [EnterpriseTreasuryController::class, 'storeReconciliation'])->middleware('throttle:30,1')->name('reconciliation.store');
            Route::post('/reconciliation/{reconciliation}/matches', [EnterpriseTreasuryController::class, 'addReconciliationMatch'])->whereNumber('reconciliation')->middleware('throttle:30,1')->name('reconciliation.matches.store');
            Route::post('/reconciliation/{reconciliation}/close', [EnterpriseTreasuryController::class, 'closeReconciliation'])->whereNumber('reconciliation')->middleware('throttle:30,1')->name('reconciliation.close');
            Route::post('/cash-closing', [EnterpriseTreasuryController::class, 'storeCashClosing'])->middleware('throttle:30,1')->name('cash-closing.store');
            Route::post('/cash-closing/{cashClosing}/close', [EnterpriseTreasuryController::class, 'closeCashClosing'])->whereNumber('cashClosing')->middleware('throttle:30,1')->name('cash-closing.close');
            Route::post('/reports/schedule', [EnterpriseTreasuryController::class, 'scheduleReport'])->middleware('throttle:20,1')->name('reports.schedule');
            Route::post('/reports/export', [EnterpriseTreasuryController::class, 'requestReportExport'])->middleware('throttle:20,1')->name('reports.export');
            Route::post('/investments/{investment}/settle', [EnterpriseTreasuryController::class, 'settleInvestment'])->whereNumber('investment')->middleware('throttle:30,1')->name('investments.settle');
            Route::post('/investments', [EnterpriseTreasuryController::class, 'storeInvestment'])->middleware('throttle:30,1')->name('investments.store');
            Route::post('/repayments/{repayment}/paid', [EnterpriseTreasuryController::class, 'markRepaymentPaid'])->whereNumber('repayment')->middleware('throttle:30,1')->name('repayments.paid');
            Route::post('/drawdowns', [EnterpriseTreasuryController::class, 'storeDrawdown'])->middleware('throttle:30,1')->name('drawdowns.store');
            Route::post('/facilities', [EnterpriseTreasuryController::class, 'storeFacility'])->middleware('throttle:30,1')->name('facilities.store');
        });
        Route::get('/treasury/master-data', [TreasuryMasterController::class, 'index'])->name('treasury.master.index');
        Route::post('/treasury/cashboxes', [TreasuryMasterController::class, 'storeCashbox'])->middleware('throttle:60,1')->name('treasury.cashboxes.store');
        Route::patch('/treasury/cashboxes/{cashbox}', [TreasuryMasterController::class, 'updateCashbox'])->whereNumber('cashbox')->middleware('throttle:60,1')->name('treasury.cashboxes.update');
        Route::post('/treasury/bank-accounts', [TreasuryMasterController::class, 'storeBankAccount'])->middleware('throttle:60,1')->name('treasury.bank-accounts.store');
        Route::patch('/treasury/bank-accounts/{bankAccount}', [TreasuryMasterController::class, 'updateBankAccount'])->whereNumber('bankAccount')->middleware('throttle:60,1')->name('treasury.bank-accounts.update');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/lookups/{entity}', [ReportController::class, 'lookup'])->whereIn('entity', ['products', 'customers'])->name('reports.lookups');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/inspector', [SystemInspectorController::class, 'index'])->name('inspector.index');
        Route::post('/inspector/client-events', [SystemInspectorController::class, 'clientEvent'])->name('inspector.client-events');
        Route::get('/inspector/export', [SystemInspectorController::class, 'export'])->name('inspector.export');
        Route::post('/inspector/storage-link', [SystemInspectorController::class, 'repairStorageLink'])->name('inspector.storage-link');
        Route::post('/inspector/restaurant/{store}/support-access', [SystemInspectorController::class, 'grantRestaurantSupport'])
            ->whereNumber('store')->middleware('throttle:20,1')->name('inspector.restaurant-support.grant');
        Route::delete('/inspector/restaurant/{store}/support-access', [SystemInspectorController::class, 'revokeRestaurantSupport'])
            ->whereNumber('store')->middleware('throttle:20,1')->name('inspector.restaurant-support.revoke');
        Route::post('/inspector/restaurant/{store}/support-sessions/revoke', [SystemInspectorController::class, 'revokeRestaurantSupportSessions'])
            ->whereNumber('store')->middleware('throttle:20,1')->name('inspector.restaurant-support.sessions.revoke');
        Route::patch('/inspector/restaurant/{store}/lifecycle', [SystemInspectorController::class, 'transitionRestaurantLifecycle'])
            ->whereNumber('store')->middleware('throttle:20,1')->name('inspector.restaurant-lifecycle.update');
        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::get('/security/enterprise', [EnterpriseSecurityController::class, 'index'])->name('enterprise-security.index');
        Route::post('/security/enterprise/role-scopes', [EnterpriseSecurityController::class, 'requestRoleScopeGrant'])->name('enterprise-security.role-scopes.store');
        Route::delete('/security/enterprise/role-scopes/{dataScope}', [EnterpriseSecurityController::class, 'requestRoleScopeRevoke'])->name('enterprise-security.role-scopes.destroy');
        Route::post('/security/enterprise/approval-limits', [EnterpriseSecurityController::class, 'requestApprovalLimit'])->name('enterprise-security.approval-limits.store');
        Route::delete('/security/enterprise/approval-limits/{approvalLimit}', [EnterpriseSecurityController::class, 'requestApprovalLimitDeactivate'])->name('enterprise-security.approval-limits.destroy');
        Route::post('/security/enterprise/delegations', [EnterpriseSecurityController::class, 'requestDelegation'])->name('enterprise-security.delegations.store');
        Route::delete('/security/enterprise/delegations/{delegation}', [EnterpriseSecurityController::class, 'requestDelegationRevoke'])->name('enterprise-security.delegations.destroy');
        Route::post('/security/enterprise/changes/{workflowRequest}/decision', [EnterpriseSecurityController::class, 'decide'])->name('enterprise-security.changes.decide');
        Route::get('/security/demo-data', [SecurityController::class, 'demoData'])->name('security.demo-data.index');
        Route::post('/security/demo-data', [SecurityController::class, 'seedDemoData'])->name('security.demo-data.seed');
        Route::delete('/security/demo-data', [SecurityController::class, 'clearDemoData'])->name('security.demo-data.clear');
        Route::patch('/security/users/{user}/status', [SecurityController::class, 'updateUserStatus'])->name('security.users.status');
        Route::put('/security/users/{user}/roles', [SecurityController::class, 'updateUserRoles'])->name('security.users.roles');
        Route::post('/security/roles', [SecurityController::class, 'storeRole'])->name('security.roles.store');
        Route::patch('/security/roles/{role}', [SecurityController::class, 'updateRole'])->name('security.roles.update');
        Route::post('/security/roles/{role}/clone', [SecurityController::class, 'cloneRole'])->name('security.roles.clone');
        Route::delete('/security/roles/{role}', [SecurityController::class, 'destroyRole'])->name('security.roles.destroy');
        Route::get('/apps/customer/download', [MobileAppDownloadController::class, 'customer'])->name('mobile-apps.customer.download');
        Route::get('/apps/driver/download', [MobileAppDownloadController::class, 'driver'])->name('mobile-apps.driver.download');
        Route::get('/apps/van/download', [MobileAppDownloadController::class, 'van'])->name('mobile-apps.van.download');
        Route::get('/apps/downloads/status', [MobileAppDownloadController::class, 'status'])->name('mobile-apps.status');
        Route::post('/apps/downloads/prepare', [MobileAppDownloadController::class, 'prepare'])->name('mobile-apps.prepare');
        Route::post('/apps/downloads/retry', [MobileAppDownloadController::class, 'retry'])->name('mobile-apps.retry');
        Route::get('/settings/assistant', [AssistantSettingsController::class, 'index'])->name('assistant-settings.index');
        Route::put('/settings/assistant', [AssistantSettingsController::class, 'update'])->name('assistant-settings.update');
        Route::get('/settings/app-versions', [AppVersionController::class, 'index'])->name('app-versions.index');
        Route::post('/settings/app-versions', [AppVersionController::class, 'store'])->name('app-versions.store');
        Route::get('/settings/sms', [SmsSettingsController::class, 'index'])->name('sms-settings.index');
        Route::put('/settings/sms', [SmsSettingsController::class, 'update'])->name('sms-settings.update');
        Route::post('/settings/sms/test', [SmsSettingsController::class, 'test'])->middleware('throttle:10,1')->name('sms-settings.test');
        Route::get('/settings/mobile', [MobileSettingsController::class, 'index'])->name('mobile-settings.index');
        Route::put('/settings/mobile/app', [MobileSettingsController::class, 'updateApp'])->name('mobile-settings.app');
        Route::put('/settings/mobile/driver-location-policy', [MobileSettingsController::class, 'updateDriverLocationPolicy'])->name('mobile-settings.driver-location-policy');
        Route::put('/settings/mobile/push', [MobileSettingsController::class, 'updateProvider'])->name('mobile-settings.push');
        Route::post('/settings/mobile/push/test-connection', [MobileSettingsController::class, 'testProvider'])->name('mobile-settings.push.test');
        Route::post('/settings/mobile/test-push', [MobileSettingsController::class, 'testPush'])->name('mobile-settings.test');
        Route::put('/settings/mobile/submission', [StoreSubmissionController::class, 'updateSubmission'])->name('mobile-settings.submission');
        Route::put('/settings/mobile/reviewer', [StoreSubmissionController::class, 'upsertReviewer'])->name('mobile-settings.reviewer');
        Route::post('/settings/mobile/reviewer/{reviewer}/rotate', [StoreSubmissionController::class, 'rotateReviewer'])->whereNumber('reviewer')->name('mobile-settings.reviewer.rotate');
        Route::post('/settings/mobile/reviewer/{reviewer}/test', [StoreSubmissionController::class, 'testReviewer'])->whereNumber('reviewer')->name('mobile-settings.reviewer.test');
        Route::get('/settings/foodex-reset', [FoodexResetController::class, 'index'])->name('foodex-reset.index');
        Route::post('/settings/foodex-reset', [FoodexResetController::class, 'store'])->block(920, 1)->middleware('throttle:3,1')->name('foodex-reset.store');
        Route::get('/settings/production-dataset', [ProductionDatasetSwitchController::class, 'index'])->name('production-dataset-switch.index');
        Route::post('/settings/production-dataset/launch', [ProductionDatasetSwitchController::class, 'launch'])->block(920, 1)->middleware('throttle:3,1')->name('production-dataset-switch.launch');
        Route::post('/settings/production-dataset/legacy', [ProductionDatasetSwitchController::class, 'legacy'])->block(1820, 1)->middleware('throttle:3,1')->name('production-dataset-switch.legacy');
        Route::get('/settings/production-launch', [ProductionLaunchController::class, 'index'])->name('production-launch.index');
        Route::get('/settings/production-launch/report', [ProductionLaunchController::class, 'report'])->name('production-launch.report');
        Route::post('/settings/production-launch', [ProductionLaunchController::class, 'store'])->middleware('throttle:3,1')->name('production-launch.store');
        Route::get('/settings/system-update', [SystemUpdateController::class, 'index'])->name('system-update.index');
        Route::post('/settings/system-update', [SystemUpdateController::class, 'store'])->name('system-update.store');
        Route::post('/settings/system-update/backups', [SystemUpdateController::class, 'backup'])
            ->middleware('throttle:3,1')
            ->name('system-update.backups.store');
        Route::post('/settings/system-update/backups/{backupReference}/restore', [SystemUpdateController::class, 'restore'])
            ->where('backupReference', '\\d{8}-\\d{6}-database-[a-f0-9]{10}')
            ->middleware('throttle:1,1')
            ->name('system-update.backups.restore');
        Route::get('/settings/translations', [TranslationController::class, 'index'])->name('translations.index');
        Route::patch('/settings/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        Route::post('/settings/translations/{translation}/reset', [TranslationController::class, 'reset'])->name('translations.reset');
    });

require __DIR__.'/smart_ordering.php';

require __DIR__.'/smart_ordering_web.php';
