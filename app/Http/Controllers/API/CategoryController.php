<?php

namespace App\Http\Controllers\API;

use App\Models\Business;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $query = $business->categories();

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('name_hi', 'like', "%{$request->search}%");
        }

        $categories = $query->orderBy('name')->get();

        return $this->successResponse($categories);
    }

    public function store(Request $request, Business $business)
    {
        $this->authorizeAccess($request, $business);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:categories,name,NULL,id,business_id,' . $business->id,
            'name_hi' => 'nullable|string|max:100',
            'type' => 'required|in:product,service,expense',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $data = $request->all();
        $data['business_id'] = $business->id;

        $category = Category::create($data);

        return $this->successResponse($category, 'Category created successfully', 201);
    }

    public function show(Request $request, Business $business, Category $category)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeCategory($business, $category);

        return $this->successResponse($category->load('products'));
    }

    public function update(Request $request, Business $business, Category $category)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeCategory($business, $category);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100|unique:categories,name,' . $category->id . ',id,business_id,' . $business->id,
            'name_hi' => 'sometimes|string|max:100',
            'type' => 'sometimes|in:product,service,expense',
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Validation failed', 422, $validator->errors());
        }

        $category->update($request->all());

        return $this->successResponse($category, 'Category updated successfully');
    }

    public function destroy(Request $request, Business $business, Category $category)
    {
        $this->authorizeAccess($request, $business);
        $this->authorizeCategory($business, $category);

        if ($category->products()->count() > 0) {
            return $this->errorResponse('Cannot delete category with products', 400);
        }

        $category->delete();
        return $this->successResponse(null, 'Category deleted successfully');
    }

    private function authorizeAccess(Request $request, Business $business)
    {
        $user = $request->user();
        if (!$user->businesses->contains($business->id) && $business->owner_id !== $user->id) {
            abort(403, 'You do not have access to this business');
        }
    }

    private function authorizeCategory(Business $business, Category $category)
    {
        if ($category->business_id !== $business->id) {
            abort(403, 'Category does not belong to this business');
        }
    }
}
