<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;

Auth::login(User::find(1)); // Login as the first user (admin)

$product = Product::find(3);
$manager = App\Filament\Resources\ProductResource\RelationManagers\AddonsRelationManager::class;

$can = $manager::canViewForRecord($product, 'App\Filament\Resources\ProductResource\Pages\EditProduct');

echo "Can view AddonsRelationManager? : " . ($can ? 'YES' : 'NO') . "\n";
echo "Roles: " . Auth::user()->roles->pluck('name')->join(', ') . "\n";
