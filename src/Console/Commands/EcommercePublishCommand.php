<?php

namespace Marshmallow\Ecommerce\Cart\Console\Commands;

use Illuminate\Console\Command;

class EcommercePublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecommerce:publish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Example command';

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
        // $this->call('vendor:publish', [
        //     '--tag' => 'ecommerce-config',
        //     '--force' => $this->option('force'),
        // ]);

        $this->call('vendor:publish', [
            '--tag' => 'ecommerce-assets',
            '--force' => true,
        ]);

        // $this->call('vendor:publish', [
        //     '--tag' => 'ecommerce-translations',
        //     '--force' => $this->option('force'),
        // ]);

        // $this->call('vendor:publish', [
        //     '--tag' => 'ecommerce-views',
        //     '--force' => $this->option('force'),
        // ]);

        // $this->call('view:clear');
        $this->info('Marshmallow ecommerce assets have been published');
    }
}
