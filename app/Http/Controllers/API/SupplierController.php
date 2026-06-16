<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->suppliers();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($suppliers);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'gst_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->all();
        $data['business_id'] = $business->id;
        $data['due_amount'] = 0;
        $data['total_purchases'] = 0;

        $supplier = Supplier::create($data);

        return $this->successResponse($supplier, 'Supplier created successfully', 201);
    }

    public function show(Request $request, Business $business, Supplier $supplier)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeSupplier($business, $supplier);

        return $this->successResponse($supplier->load('payments'));
    }

    public function update(Request $request, Business $business, Supplier $supplier)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeSupplier($business, $supplier);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'sometimes|string',
            'city' => 'sometimes|string|max:100',
            'gst_number' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
            'notes' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $supplier->update($request->all());

        return $this->successResponse($supplier, 'Supplier updated successfully');
    }

    public function destroy(Request $request, Business $business, Supplier $supplier)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeSupplier($business, $supplier);

        $supplier->delete();
        return $this->successResponse(null, 'Supplier deleted successfully');
    }

    public function recordPayment(Request $request, Business $business, Supplier $supplier)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeSupplier($business, $supplier);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01|max:' . $supplier->due_amount,
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,upi,bank_transfer,cheque,card',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $payment = $supplier->payments()->create([
            'business_id' => $business->id,
            'amount' => $request->amount,
            'payment_date' => $request->payment_date,
            'payment_method' => $request->payment_method,
            'reference_number' => $request->reference_number,
            'notes' => $request->notes,
            'received_by' => $request->user()->id,
        ]);

        $supplier->decrement('due_amount', $request->amount);
        $supplier->increment('total_purchases', $request->amount);

        return $this->successResponse($payment, 'Payment recorded successfully');
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function authorizeSupplier(Business $business, Supplier $supplier)
    {
        if ($supplier->business_id !== $business->id) {
            abort(403, 'Supplier does not belong to this business');
        }
    }
}
