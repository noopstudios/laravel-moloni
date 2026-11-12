<?php

namespace Noop\Moloni\Console;

use Illuminate\Console\Command;
use Noop\Moloni\Settings\SettingsCheck;

class CheckCommand extends Command
{
    protected $signature = 'moloni:check';

    protected $description = 'Check the Moloni login, the required settings and that every configured id exists in Moloni';

    public function handle(SettingsCheck $check): int
    {
        $problems = $check->readiness();

        if ($problems === []) {
            $this->components->info(__('The Moloni settings are valid.'));

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
