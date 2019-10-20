<?php

namespace Nooqta\Larifriqiya\Migrations;

use Noqta\Larifriqiya\GeneratorException;

class SyntaxBuilder
{
    /**
     * A template to be inserted.
     *
     * @var string
     */
    private $template;

    private $meta;

    /**
     * Create the PHP syntax for the given schema.
     *
     * @param  array $schema
     * @param  array $meta
     * @return string
     * @throws GeneratorException
     */
    public function create($schema, $meta)
    {
        $this->meta = $meta;
        $up = $this->createSchemaForUpMethod($schema, $meta);
        $down = $this->createSchemaForDownMethod($schema, $meta);

        return compact('up', 'down');
    }

    /**
     * Create the schema for the "up" method.
     *
     * @param  string $schema
     * @param  array $meta
     * @param string create|remove
     * @return string
     * @throws GeneratorException
     */
    private function createSchemaForUpMethod($schema, $meta, $action = 'create')
    {
        $fields = $this->constructSchema($schema);
        $stub = $this->getCreateSchemaWrapper();
        $stub = $this->replaceForeignKeys($stub);
        if ($action == 'create') {
            return $this->insert($fields)
                        ->into($stub);
        }

        if ($action == 'add') {
            return $this->insert($fields)->into($this->getChangeSchemaWrapper());
        }

        if ($action == 'remove') {
            $fields = $this->constructSchema($schema, 'Drop');

            return $this->insert($fields)->into($this->getChangeSchemaWrapper());
        }

        // Otherwise, we have no idea how to proceed.
        throw new GeneratorException;
    }

    public function replaceForeignKeys(&$stub)
    {
        $tabIndent = '    ';
        $schemaFields = '';
        $foreignKeys = $this->meta['foreign_keys'];
        if (!$foreignKeys) {
            return $stub = str_replace('{{foreign_keys}}', '', $stub);
        }
        foreach ($foreignKeys as $fk) {
                $schemaFields .= "\$table->foreign('" . $fk['column'] . "')"
                . "->references('" . $fk['references'] . "')->on('" . $fk['on'] . "')";
            if($fk['onDelete']) $schemaFields .= "->onDelete('" . $fk['onDelete'] . "')";
            if($fk['onUpdate']) $schemaFields .= "->onUpdate('" . $fk['onUpdate'] . "')";
            $schemaFields .= ";\n" . $tabIndent . $tabIndent . $tabIndent;
            $stub = str_replace('{{foreign_keys}}', $schemaFields, $stub);
            return $stub;
        }
    }

    /**
     * Construct the syntax for a down field.
     *
     * @param  array $schema
     * @param  array $meta
     * @return string
     * @throws GeneratorException
     */
    private function createSchemaForDownMethod($schema, $meta, $action = 'create')
    {
        // If the user created a table, then for the down
        // method, we should drop it.
        if ($action == 'create') {
            return sprintf("Schema::dropIfExists('%s');", $meta['table']);
        }

        // If the user added columns to a table, then for
        // the down method, we should remove them.
        if ($action == 'add') {
            $fields = $this->constructSchema($schema, 'Drop');
            
            return $this->insert($fields)->into($this->getChangeSchemaWrapper());
        }

        // If the user removed columns from a table, then for
        // the down method, we should add them back on.
        if ($action == 'remove') {
            $fields = $this->constructSchema($schema);

            return $this->insert($fields)->into($this->getChangeSchemaWrapper());
        }

        // Otherwise, we have no idea how to proceed.
        throw new GeneratorException;
    }

    /**
     * Store the given template, to be inserted somewhere.
     *
     * @param  string $template
     * @return $this
     */
    private function insert($template)
    {
        $this->template = $template;

        return $this;
    }

    /**
     * Get the stored template, and insert into the given wrapper.
     *
     * @param  string $wrapper
     * @param  string $placeholder
     * @return mixed
     */
    private function into($wrapper, $placeholder = 'schema_up')
    {
        return str_replace('{{' . $placeholder . '}}', $this->template, $wrapper);
    }

    /**
     * Get the wrapper template for a "create" action.
     *
     * @return string
     */
    private function getCreateSchemaWrapper()
    {
        return file_get_contents(__DIR__ . '/../stubs/create-schema.stub');
    }

    /**
     * Get the wrapper template for an "add" action.
     *
     * @return string
     */
    private function getChangeSchemaWrapper()
    {
        return file_get_contents(__DIR__ . '/../stubs/schema-change.stub');
    }

    /**
     * Construct the schema fields.
     *
     * @param  array $schema
     * @param  string $direction
     * @return array
     */
    private function constructSchema($schema, $direction = 'add')
    {
        if (!$schema) return '';

        $fields = array_map(function ($field) use ($direction) {
            $method = "{$direction}Column";

            return $this->$method($field);
        }, $schema);

        return implode("\n" . str_repeat(' ', 12), $fields);
    }


    /**
     * Construct the syntax to add a column.
     *
     * @param  string $field
     * @return string
     */
    private function addColumn($field)
    {
        $syntax = sprintf("\$table->%s('%s')", $field['type'], $field['name']);

        // If there are arguments for the schema type, like decimal('amount', 5, 2)
        // then we have to remember to work those in.
        if ($field['arguments']) {
            $syntax = substr($syntax, 0, -1) . ', ';
            // Check if enum type and array
            if ($field['type'] == 'enum') {
                $syntax .= implode(', [', $field['arguments']) . '])';
            } else {
                $syntax .= implode(', ', $field['arguments']) . ')';
            }
        }

        foreach ($field['options'] as $value) {
            if(isset($value['key']))
                $syntax .= sprintf("->%s(%s)", $value['key'], $value['value']);
        }
        return $syntax .= ';';
    }

    /**
     * Construct the syntax to drop a column.
     *
     * @param  string $field
     * @return string
     */
    private function dropColumn($field)
    {
        return sprintf("\$table->dropColumn('%s');", $field['name']);
    }
}
