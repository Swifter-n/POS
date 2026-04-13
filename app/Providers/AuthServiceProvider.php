<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\Barcode;
use App\Models\Brand;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\Customer;
use App\Models\DebitNote;
use App\Models\DiscountRule;
use App\Models\Fleet;
use App\Models\GoodsReceipt;
use App\Models\GoodsReturn;
use App\Models\Inventory;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\PickingList;
use App\Models\Position;
use App\Models\PriceList;
use App\Models\PriorityLevel;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Promo;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Region;
use App\Models\SalesOrder;
use App\Models\SalesTeam;
use App\Models\Shipment;
use App\Models\ShipmentRoute;
use App\Models\ShippingRate;
use App\Models\Stock;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Policies\AreaPolicy;
use App\Policies\BarcodePolicy;
use App\Policies\BrandPolicy;
use App\Policies\BusinessPolicy;
use App\Policies\BusinessSettingPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\ChannelGroupPolicy;
use App\Policies\ChannelPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DebitNotePolicy;
use App\Policies\DiscountRulePolicy;
use App\Policies\FleetPolicy;
use App\Policies\GoodsReceiptPolicy;
use App\Policies\GoodsReturnPolicy;
use App\Policies\InventoryAdjustmentPolicy;
use App\Policies\InventoryPolicy;
use App\Policies\LocationPolicy;
use App\Policies\OrderPolicy;
use App\Policies\OutletPolicy;
use App\Policies\PickingListPolicy;
use App\Policies\PositionPolicy;
use App\Policies\PriceListPolicy;
use App\Policies\PriorityLevelPolicy;
use App\Policies\ProductionOrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PromoPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\PurchaseReturnPolicy;
use App\Policies\RegionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SalesOrderPolicy;
use App\Policies\SalesTeamPolicy;
use App\Policies\ShipmentPolicy;
use App\Policies\ShipmentRoutePolicy;
use App\Policies\ShippingRatePolicy;
use App\Policies\StockCountPolicy;
use App\Policies\StockPolicy;
use App\Policies\StockTransferPolicy;
use App\Policies\UserPolicy;
use App\Policies\VendorPolicy;
use App\Policies\WarehousePolicy;
use App\Policies\ZonePolicy;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        BusinessSetting::class => BusinessSettingPolicy::class,
        Business::class => BusinessPolicy::class,
        Outlet::class => OutletPolicy::class,
        User::class => UserPolicy::class,
        Category::class => CategoryPolicy::class,
        Brand::class => BrandPolicy::class,
        Product::class => ProductPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        GoodsReceipt::class => GoodsReceiptPolicy::class,
        Role::class => RolePolicy::class,
        Warehouse::class => WarehousePolicy::class,
        Location::class => LocationPolicy::class,
        Zone::class => ZonePolicy::class,
        StockTransfer::class => StockTransferPolicy::class,
        Shipment::class => ShipmentPolicy::class,
        ProductionOrder::class => ProductionOrderPolicy::class,
        Inventory::class => InventoryPolicy::class,
        InventoryAdjustment::class => InventoryAdjustmentPolicy::class,
        StockCount::class => StockCountPolicy::class,
        GoodsReturn::class => GoodsReturnPolicy::class,
        PurchaseReturn::class => PurchaseReturnPolicy::class,
        Order::class => OrderPolicy::class,
        SalesOrder::class => SalesOrderPolicy::class,
        Area::class => AreaPolicy::class,
        Barcode::class => BarcodePolicy::class,
        Channel::class => ChannelPolicy::class,
        ChannelGroup::class => ChannelGroupPolicy::class,
        Customer::class => CustomerPolicy::class,
        DebitNote::class => DebitNotePolicy::class,
        DiscountRule::class => DiscountRulePolicy::class,
        Fleet::class => FleetPolicy::class,
        PickingList::class => PickingListPolicy::class,
        Position::class => PositionPolicy::class,
        PriceList::class => PriceListPolicy::class,
        PriorityLevel::class => PriorityLevelPolicy::class,
        Promo::class => PromoPolicy::class,
        Region::class => RegionPolicy::class,
        SalesTeam::class => SalesTeamPolicy::class,
        ShipmentRoute::class => ShipmentRoutePolicy::class,
        ShippingRate::class => ShippingRatePolicy::class,
        Vendor::class => VendorPolicy::class,

    ];
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
