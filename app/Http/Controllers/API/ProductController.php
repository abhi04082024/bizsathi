<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->products()->with('category');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('name_hi', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by stock status
        if ($request->has('stock_status')) {
            switch ($request->stock_status) {
                case 'low':
                    $query->lowStock();
                    break;
                case 'out':
                    $query->where('stock_quantity', '<=', 0);
                    break;
                case 'in':
                    $query->where('stock_quantity', '>', 0);
                    break;
            }
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $products = $query->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($products);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'name_hi' => 'nullable|string|max:255',
            'sku' => 'required|string|max:100|unique:products,sku,NULL,id,business_id,' . $business->id,
            'category_id' => 'required|exists:categories,id',
            'type' => 'required|in:product,service',
            'description' => 'nullable|string',
            'unit' => 'required|string|max:50',
            'unit_hi' => 'nullable|string|max:50',
            'purchase_price' => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'stock_quantity' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
            'image' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->except('image');
        $data['business_id'] = $business->id;

        $product = Product::create($data);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $product->update(['image' => $path]);
        }

        // Create stock movement for initial stock
        if ($request->stock_quantity > 0) {
            $product->stockMovements()->create([
                'business_id' => $business->id,
                'type' => 'in',
                'quantity' => $request->stock_quantity,
                'unit_price' => $request->purchase_price,
                'total_amount' => $request->stock_quantity * $request->purchase_price,
                'notes' => 'Initial stock',
                'created_by' => $request->user()->id,
            ]);
        }

        return $this->successResponse($product->load('category'), 'Product created successfully', 201);
    }

    public function show(Request $request, Business $business, Product $product)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeProduct($business, $product);

        return $this->successResponse($product->load('category', 'stockMovements'));
    }

    public function update(Request $request, Business $business, Product $product)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeProduct($business, $product);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'name_hi' => 'sometimes|string|max:255',
            'sku' => 'sometimes|string|max:100|unique:products,sku,' . $product->id . ',id,business_id,' . $business->id,
            'category_id' => 'sometimes|exists:categories,id',
            'type' => 'sometimes|in:product,service',
            'description' => 'sometimes|string',
            'unit' => 'sometimes|string|max:50',
            'unit_hi' => 'sometimes|string|max:50',
            'purchase_price' => 'sometimes|numeric|min:0',
            'selling_price' => 'sometimes|numeric|min:0',
            'stock_quantity' => 'sometimes|numeric|min:0',
            'min_stock_level' => 'sometimes|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'image' => 'sometimes|image|max:2048',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $oldStock = $product->stock_quantity;
        $product->update($request->except('image'));

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('products', 'public');
            $product->update(['image' => $path]);
        }

        // Track stock change
        if ($request->has('stock_quantity') && $request->stock_quantity != $oldStock) {
            $diff = $request->stock_quantity - $oldStock;
            $product->stockMovements()->create([
                'business_id' => $business->id,
                'type' => $diff > 0 ? 'in' : 'out',
                'quantity' => abs($diff),
                'unit_price' => $product->purchase_price,
                'total_amount' => abs($diff) * $product->purchase_price,
                'notes' => 'Stock adjustment',
                'created_by' => $request->user()->id,
            ]);
        }

        return $this->successResponse($product->load('category'), 'Product updated successfully');
    }

    public function destroy(Request $request, Business $business, Product $product)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeProduct($business, $product);

        // Check if product is used in invoices
        if ($product->invoiceItems()->count() > 0) {
            return $this->errorResponse('Cannot delete product that is used in invoices', 400);
        }

        $product->delete();
        return $this->successResponse(null, 'Product deleted successfully');
    }

    public function lowStock(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $products = $business->products()
            ->lowStock()
            ->with('category')
            ->get();

        return $this->successResponse($products);
    }

    public function adjustStock(Request $request, Business $business, Product $product)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeProduct($business, $product);

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|numeric',
            'type' => 'required|in:in,out',
            'reason' => 'required|string|max:255',
            'unit_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $quantity = abs($request->quantity);
        $newStock = $request->type === 'in' 
            ? $product->stock_quantity + $quantity 
            : $product->stock_quantity - $quantity;

        if ($newStock < 0) {
            return $this->errorResponse('Insufficient stock', 400);
        }

        $unitPrice = $request->unit_price ?? $product->purchase_price;

        $product->stockMovements()->create([
            'business_id' => $business->id,
            'type' => $request->type,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $quantity * $unitPrice,
            'notes' => $request->reason,
            'created_by' => $request->user()->id,
        ]);

        $product->update(['stock_quantity' => $newStock]);

        return $this->successResponse($product->load('category'), 'Stock adjusted successfully');
    }

    public function import(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        // Use Laravel Excel for import
        // Excel::import(new ProductsImport($business), $request->file('file'));

        return $this->successResponse(null, 'Products imported successfully');
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function authorizeProduct(Business $business, Product $product)
    {
        if ($product->business_id !== $business->id) {
            abort(403, 'Product does not belong to this business');
        }
    }
}
