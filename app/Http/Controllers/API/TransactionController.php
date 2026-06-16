<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class TransactionController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->transactions()->with('user');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        if ($request->has('from_date')) {
            $query->whereDate('transaction_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('transaction_date', '<=', $request->to_date);
        }

        $sortBy = $request->get('sort_by', 'transaction_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $transactions = $query->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($transactions);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:income,expense',
            'category' => 'required|string|max:100',
            'category_hi' => 'nullable|string|max:100',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:500',
            'description_hi' => 'nullable|string|max:500',
            'transaction_date' => 'required|date',
            'payment_method' => 'nullable|in:cash,upi,bank_transfer,cheque,card',
            'reference_type' => 'nullable|string|max:50',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->all();
        $data['business_id'] = $business->id;
        $data['user_id'] = $request->user()->id;

        $transaction = Transaction::create($data);

        return $this->successResponse($transaction->load('user'), 'Transaction recorded successfully', 201);
    }

    public function show(Request $request, Business $business, Transaction $transaction)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeTransaction($business, $transaction);

        return $this->successResponse($transaction->load('user'));
    }

    public function update(Request $request, Business $business, Transaction $transaction)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeTransaction($business, $transaction);

        $validator = Validator::make($request->all(), [
            'category' => 'sometimes|string|max:100',
            'category_hi' => 'sometimes|string|max:100',
            'amount' => 'sometimes|numeric|min:0.01',
            'description' => 'sometimes|string|max:500',
            'description_hi' => 'sometimes|string|max:500',
            'transaction_date' => 'sometimes|date',
            'payment_method' => 'sometimes|in:cash,upi,bank_transfer,cheque,card',
            'notes' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $transaction->update($request->all());

        return $this->successResponse($transaction, 'Transaction updated successfully');
    }

    public function destroy(Request $request, Business $business, Transaction $transaction)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeTransaction($business, $transaction);

        $transaction->delete();
        return $this->successResponse(null, 'Transaction deleted successfully');
    }

    public function summary(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $startOfYear = $today->copy()->startOfYear();

        $summary = [
            'income_this_month' => $business->transactions()->income()->thisMonth()->sum('amount'),
            'expense_this_month' => $business->transactions()->expense()->thisMonth()->sum('amount'),
            'income_this_year' => $business->transactions()->income()->thisYear()->sum('amount'),
            'expense_this_year' => $business->transactions()->expense()->thisYear()->sum('amount'),
            'net_this_month' => 0,
            'net_this_year' => 0,
            'average_monthly_income' => 0,
            'average_monthly_expense' => 0,
        ];

        $summary['net_this_month'] = $summary['income_this_month'] - $summary['expense_this_month'];
        $summary['net_this_year'] = $summary['income_this_year'] - $summary['expense_this_year'];

        $monthlyIncome = $business->transactions()
            ->income()
            ->thisYear()
            ->selectRaw('MONTH(transaction_date) as month, SUM(amount) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyExpense = $business->transactions()
            ->expense()
            ->thisYear()
            ->selectRaw('MONTH(transaction_date) as month, SUM(amount) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $currentMonth = $today->month;
        $summary['average_monthly_income'] = $monthlyIncome->count() > 0 ? round($monthlyIncome->sum() / $currentMonth, 2) : 0;
        $summary['average_monthly_expense'] = $monthlyExpense->count() > 0 ? round($monthlyExpense->sum() / $currentMonth, 2) : 0;

        return $this->successResponse($summary);
    }

    public function export(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'type' => 'nullable|in:income,expense',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $query = $business->transactions()
            ->whereBetween('transaction_date', [$request->from_date, $request->to_date]);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->get();

        // Generate Excel export
        // Excel::download(new TransactionsExport($transactions), 'transactions.xlsx');

        return $this->successResponse([
            'transactions' => $transactions,
            'total_count' => $transactions->count(),
            'total_amount' => $transactions->sum('amount'),
        ], 'Export data ready');
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function authorizeTransaction(Business $business, Transaction $transaction)
    {
        if ($transaction->business_id !== $business->id) {
            abort(403, 'Transaction does not belong to this business');
        }
    }
}
