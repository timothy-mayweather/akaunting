<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;


class Init extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an sqlite database file and the env file';

    /**
     * Execute the console command.
     *
     * @return void
     */


    public function handle(): void
    {
        $db_file = fopen(config('database.connections.sqlite.database'), 'wb') or die("Unable to create database file!");
        fclose($db_file);
        $this->info('Successfully created database file!');
        file_put_contents(base_path('.env'),file_get_contents(base_path('.env.example')));
        $this->info('Successfully generated .env file!');
        $this->updateEnv([
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        ]);
        $this->info('Successfully set application key');
    }

    public function updateEnv($data)
    {
        if (empty($data) || !is_array($data) || !is_file(base_path('.env'))) {
            return false;
        }

        $env = file_get_contents(base_path('.env'));

        $env = explode("\n", $env);

        foreach ($data as $data_key => $data_value) {
            $updated = false;

            foreach ($env as $env_key => $env_value) {
                $entry = explode('=', $env_value, 2);

                // Check if new or old key
                if ($entry[0] == $data_key) {
                    $env[$env_key] = $data_key . '=' . $data_value;
                    $updated = true;
                } else {
                    $env[$env_key] = $env_value;
                }
            }

            // Lets create if not available
            if (!$updated) {
                $env[] = $data_key . '=' . $data_value;
            }
        }

        $env = implode("\n", $env);

        file_put_contents(base_path('.env'), $env);

        return true;
    }
}