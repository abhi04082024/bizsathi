<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->customers();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('village', 'like', "%{$search}%");
            });
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            switch ($request->status) {
                case 'active':
                    $query->where('due_amount', '<=', 0);
                    break;
                case 'pending':
                    $query->where('due_amount', '>', 0)->where('due_amount', '<=', 'credit_limit');
                    break;
                case 'overdue':
                    $query->where('due_amount', '>', 'credit_limit');
                    break;
            }
        }

        $customers = $query->orderBy('name')->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($customers);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone,NULL,id,business_id,' . $business->id,
            'email' => 'nullable|email|max:255',
            'type' => 'required|in:farmer,trader,retailer,other',
            'address' => 'nullable|string',
            'village' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'gst_number' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->all();
        $data['business_id'] = $business->id;
        $data['due_amount'] = 0;
        $data['total_sales'] = 0;
        $data['total_invoices'] = 0;

        $customer = Customer::create($data);

        return $this->successResponse($customer, 'Customer created successfully', 201);
    }

    public function show(Request $request, Business $business, Customer $customer)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeCustomer($business, $customer);

        return $this->successResponse($customer->load([
            'invoices' => fn($q) => $q->latest()->limit(10),
            'payments' => fn($q) => $q->latest()->limit(10),
        ]));
    }

    public function update(Request $request, Business $business, Customer $customer)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeCustomer($business, $customer);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20|unique:customers,phone,' . $customer->id . ',id,business_id,' . $business->id,
            'email' => 'nullable|email|max:255',
            'type' => 'sometimes|in:farmer,trader,retailer,other',
            'address' => 'sometimes|string',
            'village' => 'sometimes|string|max:100',
            'district' => 'sometimes|string|max:100',
            'state' => 'sometimes|string|max:100',
            'pincode' => 'sometimes|string|max:10',
            'gst_number' => 'nullable|string|max:50',
            'credit_limit' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'notes' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $customer->update($request->all());

        return $this->successResponse($customer, 'Customer updated successfully');
    }

    public function destroy(Request $request, Business $business, Customer $customer)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeCustomer($business, $customer);

        if ($customer->invoices()->count() > 0) {
            return $this->errorResponse('Cannot delete customer with invoices', 400);
        }

        $customer->delete();
        return $this->successResponse(null, 'Customer deleted successfully');
    }

    public function stats(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $stats = [
            'total_customers' => $business->customers()->count(),
            'active_customers' => $business->customers()->where('due_amount', '<=', 0)->count(),
            'pending_customers' => $business->customers()->where('due_amount', '>', 0)->whereColumn('due_amount', '<=', 'credit_limit')->count(),
            'overdue_customers' => $business->customers()->whereColumn('due_amount', '>', 'credit_limit')->count(),
            'total_due' => $business->customers()->sum('due_amount'),
            'top_customers' => $business->customers()
                ->orderBy('total_sales', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'total_sales', 'due_amount']),
        ];

        return $this->successResponse($stats);
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function authorizeCustomer(Business $business, Customer $customer)
    {
        if ($customer->business_id !== $business->id) {
            abort(403, 'Customer does not belong to this business');
        }
    }
}
