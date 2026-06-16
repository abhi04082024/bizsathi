<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->expenses()->with('category');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhereHas('category', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('from_date')) {
            $query->whereDate('expense_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('expense_date', '<=', $request->to_date);
        }

        $expenses = $query->orderBy('expense_date', 'desc')->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($expenses);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:500',
            'expense_date' => 'required|date',
            'payment_method' => 'required|in:cash,upi,bank_transfer,cheque,card',
            'reference_number' => 'nullable|string|max:255',
            'attachment' => 'nullable|file|max:5120',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->except('attachment');
        $data['business_id'] = $business->id;
        $data['created_by'] = $request->user()->id;

        $expense = Expense::create($data);

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('expenses', 'public');
            $expense->update(['attachment' => $path]);
        }

        // Also create a transaction record
        $business->transactions()->create([
            'user_id' => $request->user()->id,
            'type' => 'expense',
            'category' => $expense->category->name,
            'category_hi' => $expense->category->name_hi,
            'amount' => $request->amount,
            'description' => $request->description,
            'transaction_date' => $request->expense_date,
            'reference_type' => 'expense',
            'reference_id' => $expense->id,
        ]);

        return $this->successResponse($expense->load('category'), 'Expense recorded successfully', 201);
    }

    public function show(Request $request, Business $business, Expense $expense)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeExpense($business, $expense);

        return $this->successResponse($expense->load('category'));
    }

    public function update(Request $request, Business $business, Expense $expense)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeExpense($business, $expense);

        $validator = Validator::make($request->all(), [
            'category_id' => 'sometimes|exists:categories,id',
            'amount' => 'sometimes|numeric|min:0.01',
            'description' => 'sometimes|string|max:500',
            'expense_date' => 'sometimes|date',
            'payment_method' => 'sometimes|in:cash,upi,bank_transfer,cheque,card',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $expense->update($request->all());

        return $this->successResponse($expense->load('category'), 'Expense updated successfully');
    }

    public function destroy(Request $request, Business $business, Expense $expense)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeExpense($business, $expense);

        $expense->delete();
        return $this->successResponse(null, 'Expense deleted successfully');
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function authorizeExpense(Business $business, Expense $expense)
    {
        if ($expense->business_id !== $business->id) {
            abort(403, 'Expense does not belong to this business');
        }
    }
}
