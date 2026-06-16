<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\BusinessController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\CustomerController;
use App\Http\Controllers\API\TransactionController;
use App\Http\Controllers\API\ReportController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\SupplierController;
use App\Http\Controllers\API\ExpenseController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\LanguageController;

/*
|--------------------------------------------------------------------------
| BusinessHub API Routes
|--------------------------------------------------------------------------
| All routes are prefixed with /api
| Authentication via Sanctum Bearer tokens
| Multi-language support: en, hi, bn, mr, or, as, te, ta, pa, gu, kn, ml, ur
|
*/

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Language routes (public)
Route::get('/languages', [LanguageController::class, 'list']);
Route::get('/translations', [LanguageController::class, 'getTranslations']);
Route::post('/translate', [LanguageController::class, 'translate']);

// Protected routes (authentication required)
Route::middleware(['auth:sanctum'])->group(function () {

    // Auth management
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    });

    // Language preference
    Route::post('/language', [LanguageController::class, 'setLanguage']);

    // Business management
    Route::prefix('businesses')->group(function () {
        Route::get('/', [BusinessController::class, 'index']);
        Route::post('/', [BusinessController::class, 'store']);
        Route::get('/{business}', [BusinessController::class, 'show']);
        Route::put('/{business}', [BusinessController::class, 'update']);
        Route::delete('/{business}', [BusinessController::class, 'destroy']);
        Route::post('/{business}/switch', [BusinessController::class, 'switchBusiness']);
        Route::get('/{business}/dashboard', [BusinessController::class, 'dashboard']);
    });

    // Business-scoped routes
    Route::prefix('businesses/{business}')->group(function () {

        // Products
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index']);
            Route::post('/', [ProductController::class, 'store']);
            Route::get('/low-stock', [ProductController::class, 'lowStock']);
            Route::post('/import', [ProductController::class, 'import']);
            Route::get('/{product}', [ProductController::class, 'show']);
            Route::put('/{product}', [ProductController::class, 'update']);
            Route::delete('/{product}', [ProductController::class, 'destroy']);
            Route::post('/{product}/adjust-stock', [ProductController::class, 'adjustStock']);
        });

        // Categories
        Route::prefix('categories')->group(function () {
            Route::get('/', [CategoryController::class, 'index']);
            Route::post('/', [CategoryController::class, 'store']);
            Route::get('/{category}', [CategoryController::class, 'show']);
            Route::put('/{category}', [CategoryController::class, 'update']);
            Route::delete('/{category}', [CategoryController::class, 'destroy']);
        });

        // Customers
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::post('/', [CustomerController::class, 'store']);
            Route::get('/stats', [CustomerController::class, 'stats']);
            Route::get('/{customer}', [CustomerController::class, 'show']);
            Route::put('/{customer}', [CustomerController::class, 'update']);
            Route::delete('/{customer}', [CustomerController::class, 'destroy']);
        });

        // Suppliers
        Route::prefix('suppliers')->group(function () {
            Route::get('/', [SupplierController::class, 'index']);
            Route::post('/', [SupplierController::class, 'store']);
            Route::get('/{supplier}', [SupplierController::class, 'show']);
            Route::put('/{supplier}', [SupplierController::class, 'update']);
            Route::delete('/{supplier}', [SupplierController::class, 'destroy']);
            Route::post('/{supplier}/payment', [SupplierController::class, 'recordPayment']);
        });

        // Invoices
        Route::prefix('invoices')->group(function () {
            Route::get('/', [InvoiceController::class, 'index']);
            Route::post('/', [InvoiceController::class, 'store']);
            Route::get('/stats', [InvoiceController::class, 'stats']);
            Route::get('/{invoice}', [InvoiceController::class, 'show']);
            Route::put('/{invoice}', [InvoiceController::class, 'update']);
            Route::delete('/{invoice}', [InvoiceController::class, 'destroy']);
            Route::post('/{invoice}/mark-paid', [InvoiceController::class, 'markAsPaid']);
            Route::post('/{invoice}/send-reminder', [InvoiceController::class, 'sendReminder']);
            Route::get('/{invoice}/download-pdf', [InvoiceController::class, 'downloadPdf']);
            Route::get('/{invoice}/print', [InvoiceController::class, 'print']);
        });

        // Transactions
        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'index']);
            Route::post('/', [TransactionController::class, 'store']);
            Route::get('/summary', [TransactionController::class, 'summary']);
            Route::post('/export', [TransactionController::class, 'export']);
            Route::get('/{transaction}', [TransactionController::class, 'show']);
            Route::put('/{transaction}', [TransactionController::class, 'update']);
            Route::delete('/{transaction}', [TransactionController::class, 'destroy']);
        });

        // Expenses
        Route::prefix('expenses')->group(function () {
            Route::get('/', [ExpenseController::class, 'index']);
            Route::post('/', [ExpenseController::class, 'store']);
            Route::get('/{expense}', [ExpenseController::class, 'show']);
            Route::put('/{expense}', [ExpenseController::class, 'update']);
            Route::delete('/{expense}', [ExpenseController::class, 'destroy']);
        });

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/dashboard', [ReportController::class, 'dashboard']);
            Route::get('/income', [ReportController::class, 'incomeReport']);
            Route::get('/expense', [ReportController::class, 'expenseReport']);
            Route::get('/dues', [ReportController::class, 'dueReport']);
            Route::get('/stock', [ReportController::class, 'stockReport']);
        });
    });

    // Notifications (user-scoped)
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{notification}', [NotificationController::class, 'destroy']);
        Route::delete('/', [NotificationController::class, 'clearAll']);
    });
});

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'BusinessHub API',
        'version' => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]);
});
