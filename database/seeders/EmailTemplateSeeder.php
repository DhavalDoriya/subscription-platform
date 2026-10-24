<?php

namespace Database\Seeders;

use App\Services\Mail\TemplateCatalog;
use Illuminate\Database\Seeder;

/** Creates the default email templates (PRD 4.9). Safe to run again: edited templates are not touched. */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        app(TemplateCatalog::class)->seedAll();
    }
}
