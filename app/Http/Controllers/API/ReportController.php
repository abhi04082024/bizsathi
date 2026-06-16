<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends BaseController
{
    public function dashboard(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $year = $request->get('year', now()->year);
        $month = $request->get('month', now()->month);

        $startDate = now()->create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Monthly income/expense trend
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->copy()->subMonths($i);
            $monthlyTrend[] = [
                'month' => $m->format('M'),
                'month_hi' => $this->getHindiMonth($m->month),
                'income' => $business->transactions()
                    ->where('type', 'income')
                    ->whereMonth('transaction_date', $m->month)
                    ->whereYear('transaction_date', $m->year)
                    ->sum('amount'),
                'expense' => $business->transactions()
                    ->where('type', 'expense')
                    ->whereMonth('transaction_date', $m->month)
                    ->whereYear('transaction_date', $m->year)
                    ->sum('amount'),
            ];
        }

        // Category-wise sales
        $categorySales = $business->transactions()
            ->where('type', 'income')
            ->whereYear('transaction_date', $year)
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderBy('total', 'desc')
            ->get();

        // Top customers
        $topCustomers = $business->customers()
            ->orderBy('total_sales', 'desc')
            ->limit(10)
            ->get(['id', 'name', 'total_sales', 'due_amount']);

        // Top products
        $topProducts = DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->where('products.business_id', $business->id)
            ->selectRaw('products.name, SUM(invoice_items.quantity) as total_qty, SUM(invoice_items.total) as total_amount')
            ->groupBy('products.id', 'products.name')
            ->orderBy('total_amount', 'desc')
            ->limit(10)
            ->get();

        // Payment status breakdown
        $paymentStatus = [
            'paid' => $business->invoices()->paid()->count(),
            'pending' => $business->invoices()->pending()->count(),
            'overdue' => $business->invoices()->overdue()->count(),
            'paid_amount' => $business->invoices()->sum('paid_amount'),
            'due_amount' => $business->invoices()->sum('due_amount'),
        ];

        // Expense breakdown
        $expenseBreakdown = $business->transactions()
            ->where('type', 'expense')
            ->whereYear('transaction_date', $year)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderBy('total', 'desc')
            ->get();

        return $this->successResponse([
            'monthly_trend' => $monthlyTrend,
            'category_sales' => $categorySales,
            'top_customers' => $topCustomers,
            'top_products' => $topProducts,
            'payment_status' => $paymentStatus,
            'expense_breakdown' => $expenseBreakdown,
            'summary' => [
                'total_income' => $business->transactions()->income()->thisYear()->sum('amount'),
                'total_expense' => $business->transactions()->expense()->thisYear()->sum('amount'),
                'net_profit' => $business->transactions()->income()->thisYear()->sum('amount') - $business->transactions()->expense()->thisYear()->sum('amount'),
                'total_invoices' => $business->invoices()->count(),
                'total_customers' => $business->customers()->count(),
                'total_products' => $business->products()->count(),
            ],
        ]);
    }

    public function incomeReport(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = \Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'group_by' => 'nullable|in:day,week,month,category',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $groupBy = $request->get('group_by', 'day');

        $query = $business->transactions()
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$request->from_date, $request->to_date]);

        $data = match($groupBy) {
            'day' => $query->selectRaw('DATE(transaction_date) as period, SUM(amount) as total')
                ->groupBy('period')
                ->orderBy('period')
                ->get(),
            'week' => $query->selectRaw('YEARWEEK(transaction_date) as period, SUM(amount) as total')
                ->groupBy('period')
                ->orderBy('period')
                ->get(),
            'month' => $query->selectRaw('DATE_FORMAT(transaction_date, "%Y-%m") as period, SUM(amount) as total')
                ->groupBy('period')
                ->orderBy('period')
                ->get(),
            'category' => $query->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
                ->groupBy('category')
                ->orderBy('total', 'desc')
                ->get(),
            default => $query->get(),
        };

        return $this->successResponse([
            'data' => $data,
            'total' => $query->sum('amount'),
            'count' => $query->count(),
            'period' => [
                'from' => $request->from_date,
                'to' => $request->to_date,
            ],
        ]);
    }

    public function expenseReport(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = \Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $query = $business->transactions()
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$request->from_date, $request->to_date]);

        $categoryWise = $query->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderBy('total', 'desc')
            ->get();

        $daily = $business->transactions()
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$request->from_date, $request->to_date])
            ->selectRaw('DATE(transaction_date) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $this->successResponse([
            'category_wise' => $categoryWise,
            'daily' => $daily,
            'total' => $query->sum('amount'),
            'highest_expense' => $query->max('amount'),
            'average_daily' => $query->selectRaw('AVG(daily_total) as avg')->fromSub(
                $business->transactions()
                    ->where('type', 'expense')
                    ->whereBetween('transaction_date', [$request->from_date, $request->to_date])
                    ->selectRaw('DATE(transaction_date) as d, SUM(amount) as daily_total')
                    ->groupBy('d'),
                'daily'
            )->value('avg'),
        ]);
    }

    public function dueReport(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $customerDues = $business->customers()
            ->where('due_amount', '>', 0)
            ->orderBy('due_amount', 'desc')
            ->get(['id', 'name', 'phone', 'due_amount', 'credit_limit']);

        $supplierDues = $business->suppliers()
            ->where('due_amount', '>', 0)
            ->orderBy('due_amount', 'desc')
            ->get(['id', 'name', 'phone', 'due_amount']);

        $overdueInvoices = $business->invoices()
            ->overdue()
            ->with('customer')
            ->get();

        $aging = [
            '0_30' => $business->invoices()
                ->where('payment_status', 'pending')
                ->whereBetween('due_date', [now()->subDays(30), now()])
                ->sum('due_amount'),
            '31_60' => $business->invoices()
                ->where('payment_status', 'pending')
                ->whereBetween('due_date', [now()->subDays(60), now()->subDays(31)])
                ->sum('due_amount'),
            '61_90' => $business->invoices()
                ->where('payment_status', 'pending')
                ->whereBetween('due_date', [now()->subDays(90), now()->subDays(61)])
                ->sum('due_amount'),
            '90_plus' => $business->invoices()
                ->where('payment_status', 'pending')
                ->where('due_date', '<', now()->subDays(90))
                ->sum('due_amount'),
        ];

        return $this->successResponse([
            'customer_dues' => $customerDues,
            'supplier_dues' => $supplierDues,
            'overdue_invoices' => $overdueInvoices,
            'aging' => $aging,
            'total_receivable' => $customerDues->sum('due_amount'),
            'total_payable' => $supplierDues->sum('due_amount'),
        ]);
    }

    public function stockReport(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $lowStock = $business->products()
            ->lowStock()
            ->with('category')
            ->get();

        $outOfStock = $business->products()
            ->where('stock_quantity', '<=', 0)
            ->with('category')
            ->get();

        $stockValue = $business->products()
            ->where('type', 'product')
            ->selectRaw('SUM(stock_quantity * purchase_price) as total_value, SUM(stock_quantity * selling_price) as potential_value')
            ->first();

        $categoryWise = $business->products()
            ->where('type', 'product')
            ->with('category')
            ->selectRaw('category_id, SUM(stock_quantity) as total_qty, SUM(stock_quantity * purchase_price) as total_value')
            ->groupBy('category_id')
            ->get();

        return $this->successResponse([
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'stock_value' => $stockValue,
            'category_wise' => $categoryWise,
        ]);
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
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
