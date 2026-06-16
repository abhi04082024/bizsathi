<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BusinessController extends BaseController
{
    public function index(Request $request)
    {
        $businesses = $request->user()->businesses()
            ->withCount(['products', 'customers', 'invoices'])
            ->get();

        return $this->successResponse($businesses);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:agriculture,food_processing,delivery,bricks_manufacturing,retail,manufacturing,service,other',
            'description' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'gst_number' => 'nullable|string|max:50',
            'logo' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $slug = Str::slug($request->name) . '-' . Str::random(6);

        $data = $request->except('logo');
        $data['owner_id'] = $request->user()->id;
        $data['slug'] = $slug;
        $data['settings'] = [
            'language' => 'hi',
            'currency' => 'INR',
            'date_format' => 'd/m/Y',
            'tax_rate' => 18,
            'invoice_prefix' => 'INV',
        ];

        $business = Business::create($data);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('business-logos', 'public');
            $business->update(['logo' => $path]);
        }

        $business->users()->attach($request->user()->id, ['role' => 'owner']);

        // Create default categories for the business
        $this->createDefaultCategories($business);

        return $this->successResponse($business->load('owner'), 'Business created successfully', 201);
    }

    public function show(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        return $this->successResponse($business->load([
            'owner',
            'products' => fn($q) => $q->active()->limit(5),
            'customers' => fn($q) => $q->limit(5),
            'invoices' => fn($q) => $q->latest()->limit(5),
        ]));
    }

    public function update(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string|max:100',
            'district' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'pincode' => 'sometimes|string|max:10',
            'phone' => 'sometimes|string|max:20',
            'email' => 'sometimes|email|max:255',
            'gst_number' => 'sometimes|string|max:50',
            'logo' => 'sometimes|image|max:2048',
            'is_active' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->except('logo');

        if ($request->has('settings')) {
            $data['settings'] = array_merge($business->settings ?? [], $request->settings);
        }

        $business->update($data);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('business-logos', 'public');
            $business->update(['logo' => $path]);
        }

        return $this->successResponse($business, 'Business updated successfully');
    }

    public function destroy(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        if ($business->owner_id !== $request->user()->id) {
            return $this->errorResponse('Only owner can delete business', 403);
        }

        $business->delete();
        return $this->successResponse(null, 'Business deleted successfully');
    }

    public function switchBusiness(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $request->user()->update(['current_business_id' => $business->id]);

        return $this->successResponse([
            'business' => $business,
            'message' => 'Switched to ' . $business->name,
        ]);
    }

    public function dashboard(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();
        $startOfYear = $today->copy()->startOfYear();

        $stats = [
            'total_income_this_month' => $business->transactions()
                ->where('type', 'income')
                ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
                ->sum('amount'),
            'total_expense_this_month' => $business->transactions()
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
                ->sum('amount'),
            'total_due' => $business->customers()->sum('due_amount'),
            'total_payable' => $business->suppliers()->sum('due_amount'),
            'net_profit' => 0,
            'profit_margin' => 0,
            'total_customers' => $business->customers()->count(),
            'total_products' => $business->products()->count(),
            'total_invoices' => $business->invoices()->count(),
            'pending_invoices' => $business->invoices()->pending()->count(),
            'overdue_invoices' => $business->invoices()->overdue()->count(),
            'low_stock_products' => $business->products()->lowStock()->count(),
        ];

        $stats['net_profit'] = $stats['total_income_this_month'] - $stats['total_expense_this_month'];
        $stats['profit_margin'] = $stats['total_income_this_month'] > 0 
            ? round(($stats['net_profit'] / $stats['total_income_this_month']) * 100, 2) 
            : 0;

        // Monthly trend data
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $today->copy()->subMonths($i);
            $monthlyTrend[] = [
                'month' => $month->format('M'),
                'month_hi' => $this->getHindiMonth($month->month),
                'income' => $business->transactions()
                    ->where('type', 'income')
                    ->whereMonth('transaction_date', $month->month)
                    ->whereYear('transaction_date', $month->year)
                    ->sum('amount'),
                'expense' => $business->transactions()
                    ->where('type', 'expense')
                    ->whereMonth('transaction_date', $month->month)
                    ->whereYear('transaction_date', $month->year)
                    ->sum('amount'),
            ];
        }

        // Recent invoices
        $recentInvoices = $business->invoices()
            ->with('customer')
            ->latest()
            ->limit(5)
            ->get();

        // Low stock alerts
        $lowStockProducts = $business->products()
            ->lowStock()
            ->with('category')
            ->limit(5)
            ->get();

        // Business distribution
        $businessDistribution = $business->transactions()
            ->selectRaw('category, SUM(amount) as total')
            ->where('type', 'income')
            ->whereYear('transaction_date', $today->year)
            ->groupBy('category')
            ->get();

        return $this->successResponse([
            'stats' => $stats,
            'monthly_trend' => $monthlyTrend,
            'recent_invoices' => $recentInvoices,
            'low_stock_products' => $lowStockProducts,
            'business_distribution' => $businessDistribution,
        ]);
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function createDefaultCategories(Business $business)
    {
        $categories = [
            ['name' => 'Fertilizer', 'name_hi' => 'खाद', 'type' => 'product', 'color' => '#3b82f6'],
            ['name' => 'Seeds', 'name_hi' => 'बीज', 'type' => 'product', 'color' => '#22c55e'],
            ['name' => 'Grains', 'name_hi' => 'अनाज', 'type' => 'product', 'color' => '#f59e0b'],
            ['name' => 'Equipment Rent', 'name_hi' => 'उपकरण किराया', 'type' => 'service', 'color' => '#f97316'],
            ['name' => 'Product Purchase', 'name_hi' => 'उत्पाद खरीद', 'type' => 'expense', 'color' => '#ef4444'],
            ['name' => 'Fuel', 'name_hi' => 'ईंधन', 'type' => 'expense', 'color' => '#8b5cf6'],
            ['name' => 'Rent', 'name_hi' => 'किराया', 'type' => 'expense', 'color' => '#ec4899'],
            ['name' => 'Salary', 'name_hi' => 'वेतन', 'type' => 'expense', 'color' => '#14b8a6'],
        ];

        foreach ($categories as $category) {
            $category['business_id'] = $business->id;
            Category::create($category);
        }
    }

    private function getHindiMonth($month)
    {
        $months = [
            1 => 'जन', 2 => 'फर', 3 => 'मार', 4 => 'अप्',
            5 => 'मई', 6 => 'जून', 7 => 'जुल', 8 => 'अग',
            9 => 'सित', 10 => 'अक्ट', 11 => 'नव', 12 => 'दिस'
        ];
        return $months[$month] ?? '';
    }
}
