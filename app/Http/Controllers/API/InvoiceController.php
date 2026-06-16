<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->invoices()->with('customer', 'items.product');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('invoice_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('invoice_date', '<=', $request->to_date);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $invoices = $query->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($invoices);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $customer = Customer::findOrFail($request->customer_id);
        if ($customer->business_id !== $business->id) {
            return $this->errorResponse('Customer does not belong to this business', 403);
        }

        DB::beginTransaction();

        try {
            $subtotal = 0;
            $taxRate = $request->tax_rate ?? $business->settings['tax_rate'] ?? 18;

            $invoice = Invoice::create([
                'business_id' => $business->id,
                'customer_id' => $request->customer_id,
                'invoice_date' => $request->invoice_date,
                'due_date' => $request->due_date,
                'tax_amount' => 0,
                'discount' => $request->discount ?? 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'due_amount' => 0,
                'status' => 'draft',
                'payment_status' => 'pending',
                'notes' => $request->notes,
                'terms' => $request->terms,
                'created_by' => $request->user()->id,
            ]);

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                if ($product->business_id !== $business->id) {
                    throw new \Exception('Product does not belong to this business');
                }

                $quantity = $item['quantity'];
                $unitPrice = $item['unit_price'];
                $itemDiscount = $item['discount'] ?? 0;
                $itemTotal = ($quantity * $unitPrice) - $itemDiscount;
                $itemTax = ($itemTotal * $taxRate) / 100;
                $itemTotalWithTax = $itemTotal + $itemTax;

                $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit' => $product->unit,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemTax,
                    'discount' => $itemDiscount,
                    'total' => $itemTotalWithTax,
                    'description' => $item['description'] ?? null,
                ]);

                $subtotal += $itemTotal;

                // Update product stock
                if ($product->type === 'product') {
                    $product->decrement('stock_quantity', $quantity);

                    $product->stockMovements()->create([
                        'business_id' => $business->id,
                        'type' => 'out',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_amount' => $quantity * $unitPrice,
                        'reference_type' => 'invoice',
                        'reference_id' => $invoice->id,
                        'notes' => 'Invoice: ' . $invoice->invoice_number,
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            $totalTax = ($subtotal * $taxRate) / 100;
            $totalDiscount = $request->discount ?? 0;
            $totalAmount = $subtotal + $totalTax - $totalDiscount;

            $invoice->update([
                'subtotal' => $subtotal,
                'tax_amount' => $totalTax,
                'total_amount' => $totalAmount,
                'due_amount' => $totalAmount,
                'status' => 'sent',
            ]);

            // Update customer stats
            $customer->increment('total_invoices');
            $customer->increment('total_sales', $totalAmount);
            $customer->increment('due_amount', $totalAmount);

            // Create transaction record
            $business->transactions()->create([
                'user_id' => $request->user()->id,
                'type' => 'income',
                'category' => 'product_sale',
                'category_hi' => 'उत्पाद बिक्री',
                'amount' => $totalAmount,
                'description' => 'Invoice ' . $invoice->invoice_number,
                'transaction_date' => $request->invoice_date,
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
            ]);

            DB::commit();

            return $this->successResponse(
                $invoice->load('customer', 'items.product'), 
                'Invoice created successfully', 
                201
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        return $this->successResponse($invoice->load('customer', 'items.product', 'payments'));
    }

    public function update(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        if ($invoice->payment_status === 'paid') {
            return $this->errorResponse('Cannot edit paid invoice', 400);
        }

        $validator = Validator::make($request->all(), [
            'due_date' => 'sometimes|date',
            'notes' => 'sometimes|string',
            'terms' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $invoice->update($request->only(['due_date', 'notes', 'terms']));

        return $this->successResponse($invoice->load('customer', 'items'), 'Invoice updated');
    }

    public function destroy(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        if ($invoice->payment_status === 'paid') {
            return $this->errorResponse('Cannot delete paid invoice', 400);
        }

        DB::beginTransaction();

        try {
            // Restore stock
            foreach ($invoice->items as $item) {
                if ($item->product) {
                    $item->product->increment('stock_quantity', $item->quantity);
                }
            }

            // Update customer stats
            $customer = $invoice->customer;
            $customer->decrement('total_invoices');
            $customer->decrement('total_sales', $invoice->total_amount);
            $customer->decrement('due_amount', $invoice->due_amount);

            $invoice->delete();

            DB::commit();
            return $this->successResponse(null, 'Invoice deleted');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function markAsPaid(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01|max:' . $invoice->due_amount,
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,upi,bank_transfer,cheque,card',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        DB::beginTransaction();

        try {
            $payment = $invoice->payments()->create([
                'business_id' => $business->id,
                'customer_id' => $invoice->customer_id,
                'amount' => $request->amount,
                'payment_date' => $request->payment_date,
                'payment_method' => $request->payment_method,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'received_by' => $request->user()->id,
            ]);

            $newPaidAmount = $invoice->paid_amount + $request->amount;
            $newDueAmount = $invoice->total_amount - $newPaidAmount;

            $paymentStatus = $newDueAmount <= 0 ? 'paid' : 'pending';

            $invoice->update([
                'paid_amount' => $newPaidAmount,
                'due_amount' => $newDueAmount,
                'payment_status' => $paymentStatus,
            ]);

            // Update customer due amount
            $invoice->customer->decrement('due_amount', $request->amount);

            DB::commit();

            return $this->successResponse(
                $invoice->load('payments', 'customer'), 
                'Payment recorded successfully'
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function sendReminder(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        if ($invoice->payment_status === 'paid') {
            return $this->errorResponse('Invoice is already paid', 400);
        }

        // Send notification to customer
        // You can integrate SMS/Email here

        $invoice->customer->notifications()->create([
            'business_id' => $business->id,
            'type' => 'payment_reminder',
            'title' => 'Payment Reminder',
            'title_hi' => 'भुगतान अनुस्मारक',
            'message' => 'Your invoice ' . $invoice->invoice_number . ' of ₹' . $invoice->due_amount . ' is due on ' . $invoice->due_date->format('d M Y'),
            'message_hi' => 'आपका चालान ' . $invoice->invoice_number . ' ₹' . $invoice->due_amount . ' का ' . $invoice->due_date->format('d M Y') . ' को देय है',
        ]);

        return $this->successResponse(null, 'Reminder sent successfully');
    }

    public function downloadPdf(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        $pdf = PDF::loadView('invoices.pdf', [
            'invoice' => $invoice->load('customer', 'items.product', 'business'),
        ]);

        return $pdf->download('invoice-' . $invoice->invoice_number . '.pdf');
    }

    public function print(Request $request, Business $business, Invoice $invoice)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeInvoice($business, $invoice);

        $pdf = PDF::loadView('invoices.pdf', [
            'invoice' => $invoice->load('customer', 'items.product', 'business'),
        ]);

        return $pdf->stream('invoice-' . $invoice->invoice_number . '.pdf');
    }

    public function stats(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $today = now();
        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        $stats = [
            'total_invoices' => $business->invoices()->count(),
            'total_paid' => $business->invoices()->paid()->count(),
            'total_pending' => $business->invoices()->pending()->count(),
            'total_overdue' => $business->invoices()->overdue()->count(),
            'total_amount_this_month' => $business->invoices()
                ->whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
                ->sum('total_amount'),
            'paid_amount_this_month' => $business->invoices()
                ->whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
                ->sum('paid_amount'),
            'due_amount_this_month' => $business->invoices()
                ->whereBetween('invoice_date', [$startOfMonth, $endOfMonth])
                ->sum('due_amount'),
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

    private function authorizeInvoice(Business $business, Invoice $invoice)
    {
        if ($invoice->business_id !== $business->id) {
            abort(403, 'Invoice does not belong to this business');
        }
    }
}
