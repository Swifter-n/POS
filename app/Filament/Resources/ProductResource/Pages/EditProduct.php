<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\RelationManagers\ProductIngredientsRelationManager;
use App\Filament\Resources\ProductResource\RelationManagers\ProductphotosRelationManager;
use App\Filament\Resources\ProductResource\RelationManagers\ProductsizesRelationManager;
use App\Filament\Resources\ProductResource\RelationManagers\RecipesRelationManager;
use App\Filament\Resources\ProductResource\RelationManagers\UomsRelationManager;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Override method ini untuk menampilkan Relation Manager secara kondisional.
     */
    public function getRelationManagers(): array
    {
        $product = $this->getRecord();

        // Daftar manajer yang selalu tampil untuk semua tipe produk
        $managers = [
            UomsRelationManager::class,
            ProductSizesRelationManager::class,
            ProductphotosRelationManager::class,
            ProductIngredientsRelationManager::class,
        ];

        // Tambahkan relation manager lain HANYA JIKA tipenya 'finished_good'
        if ($product->product_type === 'finished_good') {
            $managers[] = RecipesRelationManager::class;
            $managers[] = \App\Filament\Resources\ProductResource\RelationManagers\AddonsRelationManager::class;
            // $managers[] = ProductphotosRelationManager::class;
            // $managers[] = ProductsizesRelationManager::class;
            // $managers[] = ProductIngredientsRelationManager::class;
        }

        return $managers;
    }

}
