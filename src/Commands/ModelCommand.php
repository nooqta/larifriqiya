<?php

namespace Nooqta\Larifriqiya\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Container\Container;
use Nooqta\Larifriqiya\Migrations\SyntaxBuilder;

class ModelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ifriqiya:model {filename}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate models from a json file generated using Ifriqiya generator.';

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
            $this->makeModel();
        }
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
     * Generate an Eloquent model, if the user wishes.
     */
    protected function makeModel1()
    {
        $modelPath = $this->getModelPath($this->getModelName());

        if (!$this->files->exists($modelPath)) {
            $this->call('make:model', [
                'name' => $this->getModelName()
            ]);
        }
    }

    /**
     * Generate an Eloquent model
     *
     * @return string
     */
    protected function makeModel()
    {
        $name = $this->meta['name'];

        $path = $this->getModelPath($name);

        $this->makeDirectory($path);
        
        $this->files->put($path, $this->compileModelStub());

        $this->info("Model for {$this->meta['name']} created successfully.");
        
    }

    function compileModelStub() {
        $stub = $this->files->get(__DIR__ . '/../stubs/model.stub');
        $this->replaceNamespace($stub)
        ->replaceClassName($stub)
            ->replaceFillable($stub, $this->meta['fillable'])
            ->replaceSoftDelete($stub, $this->meta['softDelete']);
        foreach ($this->meta['relationships'] as $rel) {
            // relationshipname#relationshiptype#args_separated_by_pipes
            // e.g. employees#hasMany#App\Employee|id|dept_id
            // user is responsible for ensuring these relationships are valid
            
            // blindly wrap each arg in single quotes
            $args = $rel['arguments'];
            $argsString = '';
            foreach ($args as $k => $v) {
                if (trim($v) == '') {
                    continue;
                }
                $argsString .= "'" . trim($v) . "', ";
            }
            $argsString = substr($argsString, 0, -2); // remove last comma
            $this->createRelationshipFunction($stub, $rel['name'], $rel['type'], $rel['class'], $argsString);
        }
        $this->replaceRelationshipPlaceholder($stub);
        return $stub;
    }

    /**
     * Replace the table for the given stub.
     *
     * @param  string  $stub
     * @param  string  $table
     *
     * @return $this
     */
    protected function replaceTable(&$stub, $table)
    {
        $stub = str_replace('{{table}}', $table, $stub);
        return $this;
    }
    
    /**
     * Replace the fillable for the given stub.
     *
     * @param  string  $stub
     * @param  array  $fillable
     *
     * @return $this
     */
    protected function replaceFillable(&$stub, $fillable)
    {
        $fillableStr = '';
            foreach ($fillable as $k => $v) {
                if (trim($v) == '') {
                    continue;
                }
                $fillableStr .= "'" . trim($v) . "', ";
            }
            $fillableStr = $fillableStr? "[ ".substr($fillableStr, 0, -2)." ]": ''; // remove last comma
        $stub = str_replace('{{fillable}}', $fillableStr, $stub);
        return $this;
    }

    /**
     * Replace the (optional) soft deletes part for the given stub.
     *
     * @param  string  $stub
     * @param  string  $replaceSoftDelete
     *
     * @return $this
     */
    protected function replaceSoftDelete(&$stub, $replaceSoftDelete)
    {
        if ($replaceSoftDelete) {
            $stub = str_replace('{{softDeletes}}', "use SoftDeletes;\n    ", $stub);
            $stub = str_replace('{{useSoftDeletes}}', "use Illuminate\Database\Eloquent\SoftDeletes;\n", $stub);
        } else {
            $stub = str_replace('{{softDeletes}}', '', $stub);
            $stub = str_replace('{{useSoftDeletes}}', '', $stub);
        }
        return $this;
    }
    /**
     * Create the code for a model relationship
     *
     * @param string $stub
     * @param string $relationshipName  the name of the function, e.g. owners
     * @param string $relationshipType  the type of the relationship, hasOne, hasMany, belongsTo etc
     * @param array $relationshipArgs   args for the relationship function
     */
    protected function createRelationshipFunction(&$stub, $relationshipName, $relationshipType, $className, $argsString)
    {
        $argsString = $argsString != ''? ', '.$argsString: $argsString;
        $tabIndent = '    ';
        $code = "public function " . $relationshipName . "()\n" . $tabIndent . "{\n" . $tabIndent . $tabIndent
            . "return \$this->" . $relationshipType . "(" .$className ."::class". $argsString . ");"
            . "\n" . $tabIndent . "}";
        $str = '{{relationships}}';
        $stub = str_replace($str, $code . "\n" . $tabIndent . $str, $stub);
        return $this;
    }
    /**
     * remove the relationships placeholder when it's no longer needed
     *
     * @param $stub
     * @return $this
     */
    protected function replaceRelationshipPlaceholder(&$stub)
    {
        $stub = str_replace('{{relationships}}', '', $stub);
        return $this;
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
        $className = ucwords(camel_case($this->meta['name']));

        $stub = str_replace('{{ClassName}}', $className, $stub);

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
        $name =  base_path() . '/' .str_replace('App', 'app', str_replace('\\', '/', $this->meta['namespace'])). '/' .$this->getModelName();

        return  $name . '.php';
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
