<?php

namespace Marshmallow\Ecommerce\Cart\Console\Commands;

use Illuminate\Console\Command;
use OptimistDigital\MenuBuilder\Models\Menu;

class DemoShopCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecommerce:demo';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fill the database with demo content.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->createMenuItems();
        $this->createRoutes();
        $this->info('Marshmallow ecommerce assets have been published');
    }

    public function createRoutes()
    {
        // No routes yet to create
    }

    public function createMenuItems()
    {
        $menu_items = [
            [
                'name' => 'Ecommerce main menu',
                'slug' => 'ecommerce-main-menu',
                'locale' => 'nl',
            ]
        ];
        foreach ($menu_items as $menu_item) {

            if (Menu::whereSlug($menu_item['slug'])->first()) {
                continue;
            }

            $menu = new Menu;
            $menu->name = $menu_item['name'];
            $menu->slug = $menu_item['slug'];
            $menu->locale = $menu_item['locale'];
            $menu->created_at = now();
            $menu->save();
        }
    }
}
