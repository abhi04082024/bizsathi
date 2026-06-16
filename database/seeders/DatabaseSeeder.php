<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        $user = User::create([
            'name' => 'Rajesh Kumar',
            'email' => 'rajesh@businesshub.com',
            'phone' => '+919876543210',
            'password' => Hash::make('password123'),
            'language' => 'hi',
            'currency' => 'INR',
        ]);

        // Create business
        $business = Business::create([
            'owner_id' => $user->id,
            'name' => 'Rajesh Agro Center',
            'slug' => 'rajesh-agro-center-' . uniqid(),
            'type' => 'agriculture',
            'description' => 'Fertilizers, Seeds, and Agricultural Equipment',
            'address' => 'Main Market, Rampur',
            'city' => 'Muzaffarpur',
            'district' => 'Muzaffarpur',
            'state' => 'Bihar',
            'pincode' => '842001',
            'phone' => '+919876543210',
            'email' => 'rajesh@agrocenter.com',
            'gst_number' => '10ABCDE1234F1Z5',
            'settings' => [
                'language' => 'hi',
                'currency' => 'INR',
                'date_format' => 'd/m/Y',
                'tax_rate' => 18,
                'invoice_prefix' => 'INV',
            ],
        ]);

        $business->users()->attach($user->id, ['role' => 'owner']);

        // Create categories
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

        foreach ($categories as $cat) {
            $cat['business_id'] = $business->id;
            Category::create($cat);
        }

        $fertilizerCat = Category::where('business_id', $business->id)->where('name', 'Fertilizer')->first();
        $seedsCat = Category::where('business_id', $business->id)->where('name', 'Seeds')->first();
        $grainsCat = Category::where('business_id', $business->id)->where('name', 'Grains')->first();
        $equipmentCat = Category::where('business_id', $business->id)->where('name', 'Equipment Rent')->first();

        // Create products
        $products = [
            [
                'name' => 'Urea Fertilizer',
                'name_hi' => 'यूरिया खाद',
                'sku' => 'FERT-001',
                'category_id' => $fertilizerCat->id,
                'type' => 'product',
                'unit' => 'Bag',
                'unit_hi' => 'बैग',
                'purchase_price' => 280,
                'selling_price' => 350,
                'stock_quantity' => 5,
                'min_stock_level' => 50,
            ],
            [
                'name' => 'DAP Fertilizer',
                'name_hi' => 'डीएपी खाद',
                'sku' => 'FERT-002',
                'category_id' => $fertilizerCat->id,
                'type' => 'product',
                'unit' => 'Bag',
                'unit_hi' => 'बैग',
                'purchase_price' => 950,
                'selling_price' => 1200,
                'stock_quantity' => 12,
                'min_stock_level' => 40,
            ],
            [
                'name' => 'Wheat Seeds',
                'name_hi' => 'गेहूं बीज',
                'sku' => 'SEED-001',
                'category_id' => $seedsCat->id,
                'type' => 'product',
                'unit' => 'Quintal',
                'unit_hi' => 'क्विंटल',
                'purchase_price' => 2800,
                'selling_price' => 3500,
                'stock_quantity' => 8,
                'min_stock_level' => 30,
            ],
            [
                'name' => 'Tractor Rent',
                'name_hi' => 'ट्रैक्टर किराया',
                'sku' => 'SRV-001',
                'category_id' => $equipmentCat->id,
                'type' => 'service',
                'unit' => 'Hour',
                'unit_hi' => 'घंटा',
                'purchase_price' => 0,
                'selling_price' => 1500,
                'stock_quantity' => 0,
                'min_stock_level' => 0,
            ],
            [
                'name' => 'Wheat Grain',
                'name_hi' => 'गेहूं',
                'sku' => 'GRAIN-001',
                'category_id' => $grainsCat->id,
                'type' => 'product',
                'unit' => 'Quintal',
                'unit_hi' => 'क्विंटल',
                'purchase_price' => 1800,
                'selling_price' => 2100,
                'stock_quantity' => 120,
                'min_stock_level' => 50,
            ],
        ];

        foreach ($products as $prod) {
            $prod['business_id'] = $business->id;
            Product::create($prod);
        }

        // Create customers
        $customers = [
            ['name' => 'Ramesh Kumar', 'phone' => '+919876543211', 'type' => 'farmer', 'village' => 'Rampur', 'district' => 'Muzaffarpur'],
            ['name' => 'Suresh Singh', 'phone' => '+919876543212', 'type' => 'farmer', 'village' => 'Sitapur', 'district' => 'Muzaffarpur'],
            ['name' => 'Mahesh Patel', 'phone' => '+919876543213', 'type' => 'trader', 'village' => 'Darbhanga', 'district' => 'Darbhanga'],
            ['name' => 'Pramod Yadav', 'phone' => '+919876543214', 'type' => 'farmer', 'village' => 'Madhubani', 'district' => 'Madhubani'],
            ['name' => 'Vijay Gupta', 'phone' => '+919876543215', 'type' => 'farmer', 'village' => 'Samastipur', 'district' => 'Samastipur'],
        ];

        foreach ($customers as $cust) {
            $cust['business_id'] = $business->id;
            Customer::create($cust);
        }

        // Create supplier
        Supplier::create([
            'business_id' => $business->id,
            'name' => 'Agro Chemicals Ltd',
            'phone' => '+919876543216',
            'city' => 'Patna',
        ]);

        // Create sample transactions
        Transaction::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'type' => 'income',
            'category' => 'product_sale',
            'category_hi' => 'उत्पाद बिक्री',
            'amount' => 24500,
            'description' => 'Ramesh Kumar - Invoice Payment',
            'transaction_date' => now()->subDays(1),
        ]);

        Transaction::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'category' => 'product_purchase',
            'category_hi' => 'उत्पाद खरीद',
            'amount' => 35000,
            'description' => 'Urea Fertilizer Purchase',
            'transaction_date' => now()->subDays(2),
        ]);

        Transaction::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'type' => 'income',
            'category' => 'service_rent',
            'category_hi' => 'सेवा किराया',
            'amount' => 12000,
            'description' => 'Tractor Rent - Mahesh Patel',
            'transaction_date' => now()->subDays(3),
        ]);

        Transaction::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'type' => 'expense',
            'category' => 'fuel',
            'category_hi' => 'ईंधन',
            'amount' => 4750,
            'description' => 'Diesel for Tractor',
            'transaction_date' => now()->subDays(4),
        ]);

        Transaction::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'type' => 'income',
            'category' => 'grain_sale',
            'category_hi' => 'अनाज बिक्री',
            'amount' => 42000,
            'description' => 'Wheat Grain Sale',
            'transaction_date' => now()->subDays(5),
        ]);

        // Create sample invoice
        $ramesh = Customer::where('business_id', $business->id)->where('name', 'Ramesh Kumar')->first();
        $urea = Product::where('business_id', $business->id)->where('sku', 'FERT-001')->first();

        $invoice = Invoice::create([
            'business_id' => $business->id,
            'customer_id' => $ramesh->id,
            'invoice_number' => 'INV-2026-00142',
            'invoice_date' => now()->subDays(1),
            'due_date' => now()->addDays(15),
            'subtotal' => 21000,
            'tax_amount' => 3780,
            'total_amount' => 24500,
            'paid_amount' => 24500,
            'due_amount' => 0,
            'status' => 'sent',
            'payment_status' => 'paid',
            'created_by' => $user->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $urea->id,
            'product_name' => $urea->name,
            'quantity' => 60,
            'unit' => $urea->unit,
            'unit_price' => 350,
            'tax_rate' => 18,
            'tax_amount' => 3780,
            'total' => 24500,
        ]);

        $ramesh->update([
            'total_invoices' => 1,
            'total_sales' => 24500,
            'due_amount' => 0,
        ]);

        $this->command->info('Database seeded successfully!');
        $this->command->info('Login credentials:');
        $this->command->info('Email: rajesh@businesshub.com');
        $this->command->info('Phone: +919876543210');
        $this->command->info('Password: password123');
    }
}
