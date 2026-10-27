<?php

namespace Tests\Support;

/**
 * Checks an object against a schema of Stripe's OpenAPI spec (https://github.com/stripe/openapi, openapi/spec3.json): required fields,
 * types, enums, nullability, and fields the schema does not know. Used to keep FakePaymentGateway's objects shaped like the real API's.
 * Not a general JSON Schema validator: it knows what Stripe's spec uses (properties, required, anyOf selected by `object`, nullable).
 */
class OpenApiShape
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas;

    /** @var array<int, string> */
    private array $problems = [];

    public function __construct(string $specPath)
    {
        $this->schemas = json_decode((string) file_get_contents($specPath), true)['components']['schemas'];
    }

    /** @return array<int, string> what is wrong with $object as a $schema (empty = fine) */
    public function problems(string $schema, mixed $object): array
    {
        $this->problems = [];
        $this->check(['$ref' => '#/components/schemas/'.$schema], $object, $schema);

        return $this->problems;
    }

    /** @param  array<string, mixed>  $schema */
    private function resolve(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $schema = $this->schemas[basename($schema['$ref'])];
        }

        return $schema;
    }

    /** @param  array<string, mixed>  $schema */
    private function typeOk(array $schema, mixed $value): bool
    {
        return match ($schema['type'] ?? null) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => true,
        };
    }

    /** @param  array<string, mixed>  $schema */
    private function check(array $schema, mixed $value, string $path): void
    {
        $schema = $this->resolve($schema);

        if ($value === null) {
            $nullable = ($schema['nullable'] ?? false) || collect($schema['anyOf'] ?? [])->contains(fn ($c) => $this->resolve($c)['nullable'] ?? false);
            if (! $nullable) {
                $this->problems[] = "{$path}: null but not nullable";
            }

            return;
        }

        if (isset($schema['anyOf'])) {
            $candidates = array_map(fn ($c) => $this->resolve($c), $schema['anyOf']);

            if (is_array($value) && isset($value['object'])) {
                foreach ($candidates as $candidate) {
                    if (in_array($value['object'], $candidate['properties']['object']['enum'] ?? [], true)) {
                        $this->check($candidate, $value, $path);

                        return;
                    }
                }
                $this->problems[] = "{$path}: no schema for object={$value['object']}";

                return;
            }

            foreach ($candidates as $candidate) {
                if ($this->typeOk($candidate, $value)) {
                    $this->check($candidate, $value, $path);

                    return;
                }
            }
            $this->problems[] = "{$path}: matches none of anyOf";

            return;
        }

        if (! $this->typeOk($schema, $value)) {
            $this->problems[] = "{$path}: expected ".($schema['type'] ?? '?').', got '.get_debug_type($value);

            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $this->problems[] = "{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum']);
        }

        if (($schema['type'] ?? null) === 'array' && is_array($value)) {
            foreach ($value as $i => $item) {
                $this->check($schema['items'] ?? [], $item, "{$path}[{$i}]");
            }
        }

        if (($schema['type'] ?? null) === 'object' && is_array($value) && $value !== []) {
            $properties = $schema['properties'] ?? [];

            foreach ($schema['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    $this->problems[] = "{$path}: missing required field {$required}";
                }
            }

            foreach ($value as $key => $item) {
                if ($properties !== [] && ! isset($properties[$key])) {
                    $this->problems[] = "{$path}: unknown field {$key}";
                } elseif (isset($properties[$key])) {
                    $this->check($properties[$key], $item, "{$path}.{$key}");
                }
            }
        }
    }
}
