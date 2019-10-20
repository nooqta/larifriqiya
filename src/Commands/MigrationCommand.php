<?php

namespace Nooqta\Larifriqiya\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Nooqta\Larifriqiya\Migrations\SyntaxBuilder;

class MigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ifriqiya:migration {filename}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate models and migrations from a json file generated using Ifriqiya generator.';

    /**
     * The filename from which the models and migrations will be generated
     *
     * @var string
     */
    private $filename;

    /**
     * The filesystem instance.
     *
     * @var Filesystem
     */
    protected $files;

    /**
     * Meta information for the requested migration.
     *
     * @var array
     */
    protected $meta;

    /**
     * @var Composer
     */
    private $composer;

    /**
     * Create a new command instance.
     *
     * @param Filesystem $files
     * @param Composer $composer
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
        $this->composer = app()['composer'];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->fire();
    }

    public function fire()
    {
        //get the filename
        $this->filename = $this->argument('filename');
        //check if the file exist and continue otherwise stop processing
        if (!file_exists(base_path($this->filename))) {
            $this->error("{$this->filename}  not found. Make sure the filename is correct.");
            exit;
        }
        // check if file is a json file other stop processing
        if ('json' !== $ext = pathinfo(base_path($this->filename), PATHINFO_EXTENSION)) {
            $this->error("{$this->filename}  is not a valid file. Only json files are accepted.");
            exit;
        }
        // Parse json file
        $parsedJson = $this->parse(base_path($this->filename));
        // Validate json format
        if (!$this->isValid($parsedJson)) {
            $this->error("The format of the json file is invalid");
            exit;
        }
        // Generate schema for each model found
        foreach ($parsedJson as  $schema) {
            $this->meta  = $schema;
            $this->meta['table'] = $this->getTableName($this->meta['name']);
            $this->makeMigration();
        }
        $this->makeModel();
    }

    // Json helper
    function parse($path)
    {
        $fileContent = file_get_contents($path);
        return json_decode($fileContent, true);
    }

    // Json helper
    function isValid($json)
    {
        foreach($json as $schema){
            if (
                !array_key_exists('name', $schema) ||
                !array_key_exists('namespace', $schema) ||
                !array_key_exists('fields', $schema)
            ) {
                return false;
            }
        }
        return true;
    }
    /**
     * Generate the desired migration.
     */
    protected function makeMigration()
    {
        $name = $this->meta['name'];

        $path = $this->getPath($name);

        $this->makeDirectory($path);

        $this->files->put($path, $this->compileMigrationStub());

        $this->info("Migration for {$this->meta['name']} created successfully.");

        $this->composer->dumpAutoloads();
    }

    /**
     * Generate an Eloquent model, if the user wishes.
     */
    protected function makeModel()
    {
        $modelPath = $this->getModelPath($this->getModelName());

        if (!$this->files->exists($modelPath)) {
            $this->call('ifriqiya:model', [
                'filename' => $this->argument('filename')
            ]);
        }
    }


    /**
     * Build the directory for the class if necessary.
     *
     * @param  string $path
     * @return string
     */
    protected function makeDirectory($path)
    {
        if (!$this->files->isDirectory(dirname($path))) {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        }
    }

    /**
     * Get the path to where we should store the migration.
     *
     * @param  string $name
     * @return string
     */
    protected function getPath($name)
    {
        $migrationName = 'create_' . Str::plural(strtolower($name)) . '_table.php';
        return base_path() . '/database/migrations/' . date('Y_m_d_His') . '_' . $migrationName;
    }
    /**
     * Compile the migration stub.
     *
     * @return string
     */
    protected function compileMigrationStub()
    {
        $stub = $this->files->get(__DIR__ . '/../stubs/migration.stub');

        $this->replaceNamespace($stub)
        ->replaceClassName($stub)
            ->replaceSchema($stub)
            ->replaceTableName($stub);

        return $stub;
    }

    /**
     * Replace the class name in the stub.
     *
     * @param  string $stub
     * @return $this
     */
    protected function replaceNamespace(&$stub)
    {
        $namespace = $this->meta['namespace'];

        $stub = str_replace('{{namespace}}', $namespace, $stub);

        return $this;
    }
    /**
     * Replace the class name in the stub.
     *
     * @param  string $stub
     * @return $this
     */
    protected function replaceClassName(&$stub)
    {
        $className = 'Create' . Str::plural(ucwords(camel_case($this->meta['name']))) . 'Table';

        $stub = str_replace('{{class}}', $className, $stub);

        return $this;
    }

    /**
     * Replace the table name in the stub.
     *
     * @param  string $stub
     * @return $this
     */
    protected function replaceTableName(&$stub)
    {
        $stub = str_replace('{{table}}', $this->meta['table'], $stub);

        return $this;
    }

    /**
     * Replace the schema for the stub.
     *
     * @param  string $stub
     * @return $this
     */
    protected function replaceSchema(&$stub)
    {
            $schema = $this->meta['fields'];

        $schema = (new SyntaxBuilder)->create($schema, $this->meta);

        $stub = str_replace(['{{schema_up}}', '{{schema_down}}'], $schema, $stub);

        return $this;
    }



    /**
     * Get the class name for the Eloquent model generator.
     *
     * @return string
     */
    protected function getModelName()
    {
        return ucwords(str_singular(camel_case($this->meta['name'])));
    }

    /**
     * Get the table name
     */
    protected function getTableName()
    {
        return str_plural(snake_case($this->meta['name']));
    }

    /**
     * Get the destination class path.
     *
     * @param  string $name
     * @return string
     */
    protected function getModelPath($name)
    {
        $name = str_replace($this->getAppNamespace(), $this->meta['namespace'], $name);

        return $this->laravel['path'] . '/' . str_replace('\\', '/', $name) . '.php';
    }

    /**
     * Get the application namespace.
     *
     * @return string
     */
    protected function getAppNamespace()
    {
        return Container::getInstance()->getNamespace();
    }

    
}
